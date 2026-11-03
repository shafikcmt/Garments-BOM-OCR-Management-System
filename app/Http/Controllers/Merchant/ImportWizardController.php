<?php

namespace App\Http\Controllers\Merchant;

use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Models\BuyerSeason;
use App\Models\ExcelFile;
use App\Services\BomTableSync;
use App\Services\ImportColumnMatcher;
use App\Support\StyleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Three-step import of ANY buyer's Excel: choose, match columns, preview.
 *
 * The strict upload (ExcelUploadController) stays exactly as it was for files
 * that already follow the merchant format. This wizard is for everything else:
 * the data may sit on another sheet, the titles on another row, and the column
 * names may differ. The user confirms the matching once, can save it as the
 * buyer's profile, and the next file of that buyer lines up by itself.
 *
 * It extends the upload controller only to reuse its cell reading, row writing
 * and notification, so an imported file is stored, calculated and announced
 * exactly like an uploaded one. Only merchant input headers can be filled here,
 * the same rule as the plain upload.
 */
class ImportWizardController extends ExcelUploadController
{
    private const SESSION = 'merchant_import';

    /** Title rows are searched among the first rows of a sheet. */
    private const TOP_ROWS = 30;

    public function __construct(private ImportColumnMatcher $matcher)
    {
    }

    // ---------------------------------------------------------------- step 1

    public function index(Request $request)
    {
        $this->authorizeImport($request);

        $buyers = $this->allowedBuyers($request);
        $seasons = BuyerSeason::whereIn('buyer_id', $buyers->pluck('id'))->orderBy('code')->pluck('code')->unique()->values();

        return view('merchant.import.choose', compact('buyers', 'seasons'));
    }

    public function upload(Request $request)
    {
        $this->authorizeImport($request);

        $buyerIds = $this->allowedBuyers($request)->pluck('id')->all();

        $data = $request->validate([
            'buyer_id' => ['required', 'integer', 'in:' . implode(',', $buyerIds ?: [0])],
            'season_code' => ['required', 'string', 'max:50'],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt'],
        ], [
            'buyer_id.in' => 'Choose one of your buyers.',
            'season_code.required' => 'Enter the season, for example SP27.',
        ]);

        $this->discard($request);

        $file = $request->file('file');
        $token = (string) Str::uuid();
        $path = $file->storeAs('excel_imports/tmp', $token . '.' . strtolower($file->getClientOriginalExtension()));

        $request->session()->put(self::SESSION, [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'buyer_id' => (int) $data['buyer_id'],
            'season_code' => mb_strtoupper(trim($data['season_code'])),
        ]);

        return redirect()->route('merchant.import.map');
    }

    // ---------------------------------------------------------------- step 2

    public function map(Request $request)
    {
        $this->authorizeImport($request);
        $state = $this->state($request);

        if (! $state) {
            return redirect()->route('merchant.import.index')->with('warning', 'Choose a file first.');
        }

        $path = Storage::path($state['path']);
        $headers = $this->merchantInputHeaders();
        $sheets = $this->sheetNames($path);
        $profiles = BuyerImportProfile::with('columns')->where('buyer_id', $state['buyer_id'])->latest('updated_at')->get();

        $sheet = $request->query('sheet');
        $headerRow = $request->integer('header_row') ?: null;
        $profile = $request->filled('profile') ? $profiles->firstWhere('id', (int) $request->query('profile')) : null;
        $autoPicked = false;
        $tops = [];

        if (! $profile && ! $sheet && ! $headerRow) {
            [$profile, $tops] = $this->autoProfile($path, $sheets, $profiles);
            $autoPicked = $profile !== null;
        }

        if ($profile) {
            $sheet ??= in_array($profile->sheet_name, $sheets, true) ? $profile->sheet_name : null;
            $headerRow ??= $profile->header_row;
        }

        if (! $sheet || ! in_array($sheet, $sheets, true)) {
            [$sheet, $detectedRow, $tops] = $this->bestSheet($path, $sheets, $headers, $tops);
            $headerRow ??= $detectedRow;
        }

        $top = $tops[$sheet] ?? $this->topRows($path, $sheet);
        $headerRow = max(1, min(self::TOP_ROWS, (int) ($headerRow ?: $this->matcher->detectHeaderRow($top, $headers))));
        $columns = collect($top[$headerRow] ?? [])->filter(fn ($t) => trim((string) $t) !== '')->all();
        $suggestions = $this->matcher->suggest($columns, $headers, $profile);

        return view('merchant.import.map', [
            'state' => $state,
            'buyer' => Buyer::find($state['buyer_id']),
            'sheets' => $sheets,
            'sheet' => $sheet,
            'headerRow' => $headerRow,
            'suggestions' => $suggestions,
            'headers' => $headers,
            'profiles' => $profiles,
            'profile' => $profile,
            'autoPicked' => $autoPicked,
            'samples' => $this->samples($top, $headerRow, array_keys($columns)),
        ]);
    }

    // ---------------------------------------------------------------- step 3

    public function preview(Request $request)
    {
        $this->authorizeImport($request);
        $state = $this->state($request);

        if (! $state) {
            return redirect()->route('merchant.import.index')->with('warning', 'Choose a file first.');
        }

        $data = $request->validate([
            'sheet' => ['required', 'string'],
            'header_row' => ['required', 'integer', 'min:1', 'max:' . self::TOP_ROWS],
            'map' => ['nullable', 'array'],
            'map.*' => ['nullable', 'integer'],
            'save_profile' => ['nullable', 'boolean'],
            'profile_name' => ['nullable', 'string', 'max:100'],
        ]);

        $headers = $this->merchantInputHeaders()->keyBy('id');
        $mapping = $this->cleanMapping($data['map'] ?? [], $headers);

        if (is_string($mapping)) {
            return back()->withInput()->with('warning', $mapping);
        }

        if ($mapping === []) {
            return back()->withInput()->with('warning', 'Match at least one column before continuing.');
        }

        $path = Storage::path($state['path']);
        $read = $this->readRows($path, $data['sheet'], (int) $data['header_row'], $mapping, $headers);

        if ($read['rows'] === []) {
            return back()->withInput()->with('warning', 'No data rows were found below the title row. Check the sheet and title row.');
        }

        $state = array_merge($state, [
            'sheet' => $data['sheet'],
            'header_row' => (int) $data['header_row'],
            'mapping' => $mapping,
            'source_titles' => $read['titles'],
            'save_profile' => (bool) ($data['save_profile'] ?? false),
            'profile_name' => trim((string) ($data['profile_name'] ?? '')),
        ]);
        $request->session()->put(self::SESSION, $state);

        $styleHeaderId = $headers->first(fn ($h) => in_array($this->matcher->key($h->header_key ?: $h->header_name), ['style', 'style_name', 'style_no'], true))?->id;
        $keyOf = fn ($h) => $this->matcher->key($h->header_key ?: $h->header_name);
        $motherHeaderId = $headers->first(fn ($h) => $keyOf($h) === 'mother_style')?->id;
        $colorHeaderId = $headers->first(fn ($h) => $keyOf($h) === 'gmts_color_name')?->id;
        $summary = $this->summariseStyles($read['rows'], $styleHeaderId, $motherHeaderId, $colorHeaderId, $mapping);
        $styles = $summary['styles'];

        $mappedIds = array_values($mapping);
        $missingRequired = $headers->filter(fn ($h) => $h->is_required && ! in_array($h->id, $mappedIds, true))->pluck('header_name')->values();

        return view('merchant.import.preview', [
            'state' => $state,
            'buyer' => Buyer::find($state['buyer_id']),
            'rowCount' => count($read['rows']),
            'mappedCount' => count($mapping),
            'skippedCount' => count($read['titles']) - count($mapping),
            'styles' => $styles,
            'summary' => $summary,
            'styleMapped' => $styleHeaderId !== null && isset($mapping[$styleHeaderId]),
            'missingRequired' => $missingRequired,
            'sampleHeaders' => collect($mappedIds)->take(8)->map(fn ($id) => $headers[$id]),
            'sampleRows' => collect($read['rows'])->take(5),
        ]);
    }

    public function confirm(Request $request)
    {
        $this->authorizeImport($request);
        $state = $this->state($request);

        if (! $state || ! isset($state['mapping'])) {
            return redirect()->route('merchant.import.index')->with('warning', 'Start again: the import session has expired.');
        }

        abort_unless($request->user()->hasBuyerAccess((int) $state['buyer_id']), 403);

        @set_time_limit(300);
        DB::disableQueryLog();

        $headers = $this->merchantInputHeaders()->keyBy('id');
        $mapping = $this->cleanMapping($state['mapping'], $headers);

        if (is_string($mapping) || $mapping === []) {
            return redirect()->route('merchant.import.map')->with('warning', 'The column matching is no longer valid. Match the columns again.');
        }

        $path = Storage::path($state['path']);
        $read = $this->readRows($path, $state['sheet'], (int) $state['header_row'], $mapping, $headers);

        if ($read['rows'] === []) {
            return redirect()->route('merchant.import.map')->with('warning', 'No data rows were found.');
        }

        $userId = $request->user()->id;
        $finalName = time() . '_' . $state['original_name'];
        $finalPath = 'excel_uploads/' . $finalName;
        Storage::move($state['path'], $finalPath);

        $allHeaders = $this->allActiveHeaders();

        $excelFile = DB::transaction(function () use ($state, $finalName, $finalPath, $read, $allHeaders, $userId) {
            $season = BuyerSeason::firstOrCreate(['buyer_id' => $state['buyer_id'], 'code' => $state['season_code']]);

            $excelFile = ExcelFile::create([
                'file_name' => $finalName,
                'original_file_name' => $state['original_name'],
                'file_path' => $finalPath,
                'uploaded_by' => $userId,
                'buyer_id' => $state['buyer_id'],
                'buyer_season_id' => $season->id,
                'upload_batch_no' => 'BATCH-' . now()->format('YmdHis'),
                'total_rows' => 0,
                'status' => 'pending',
                'remarks' => 'Imported with the column-matching wizard',
                'submitted_at' => now(),
            ]);

            $this->bulkCreateRowsAndCells($excelFile, $allHeaders, $read['rows'], $userId);
            $excelFile->update(['total_rows' => count($read['rows'])]);

            return $excelFile;
        });

        if ($state['save_profile']) {
            $this->saveProfile($state, $read['titles'], $mapping, $userId);
        }

        app(\App\Http\Controllers\Shared\ExcelFileController::class)->recalculateFile($excelFile, $userId);

        try {
            app(BomTableSync::class)->enabled() && app(BomTableSync::class)->syncFile($excelFile);
        } catch (\Throwable $e) {
            Log::warning('BOM table sync failed for import ' . $excelFile->id . ': ' . $e->getMessage());
        }

        $this->notifyOtherRoles(
            $excelFile,
            'excel_uploaded',
            'New file uploaded',
            $request->user()->name . ' uploaded file: ' . $excelFile->original_file_name
        );

        $request->session()->forget(self::SESSION);

        return redirect()->route('merchant.workspace', ['tab' => 'files'])
            ->with('success', count($read['rows']) . ' rows imported for season ' . $state['season_code'] . '. Formula fields are calculated automatically.');
    }

    public function cancel(Request $request)
    {
        $this->authorizeImport($request);
        $this->discard($request);

        return redirect()->route('merchant.workspace');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Formulas are NEVER evaluated here, only their value as saved by Excel.
     *
     * Real buyer sheets carry totals such as SUBTOTAL(9,AI5:AI1048576) above
     * the data; evaluating one walks a million-row range and the request never
     * returns. Excel already stored the answer, so that is what is read.
     */
    protected function extractUploadedCellValue($sheet, int $columnIndex, int $rowNumber, $header): ?string
    {
        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $rowNumber);

        if ($cell->isFormula()) {
            $cached = $cell->getOldCalculatedValue();

            match (true) {
                $cached === null => $cell->setValueExplicit(null, DataType::TYPE_NULL),
                is_bool($cached) => $cell->setValueExplicit($cached, DataType::TYPE_BOOL),
                is_int($cached) || is_float($cached) => $cell->setValueExplicit($cached, DataType::TYPE_NUMERIC),
                default => $cell->setValueExplicit((string) $cached, DataType::TYPE_STRING),
            };
        }

        return parent::extractUploadedCellValue($sheet, $columnIndex, $rowNumber, $header);
    }

    /** A title or example cell as plain text, without evaluating a formula. */
    private function plainText(Cell $cell): string
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        if (is_object($value) && method_exists($value, 'getPlainText')) {
            $value = $value->getPlainText();
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless($request->user()?->mayUploadBom(), 403, 'Upload is not enabled for your account.');
    }

    /** @return Collection<int, Buyer> */
    private function allowedBuyers(Request $request): Collection
    {
        $scoped = $request->user()->merchantBuyerIds();

        return Buyer::active()
            ->when($scoped !== [], fn ($q) => $q->whereIn('id', $scoped))
            ->orderBy('buyer_name')
            ->get();
    }

    private function state(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION);

        return $state && Storage::exists($state['path']) ? $state : null;
    }

    private function discard(Request $request): void
    {
        $state = $request->session()->pull(self::SESSION);

        if ($state && ! empty($state['path'])) {
            Storage::delete($state['path']);
        }
    }

    /**
     * Accept only merchant input headers, each at most once.
     *
     * @param  array<int|string, mixed>  $map  column index => header id (or blank to skip)
     * @return array<int, int>|string  column index => header id, or an error message
     */
    private function cleanMapping(array $map, Collection $headers): array|string
    {
        $clean = [];
        $used = [];

        foreach ($map as $column => $headerId) {
            if ($headerId === null || $headerId === '' || (int) $headerId === 0) {
                continue;
            }

            $headerId = (int) $headerId;

            if (! $headers->has($headerId)) {
                return 'One of the chosen columns is not available for import.';
            }

            if (isset($used[$headerId])) {
                return '"' . $headers[$headerId]->header_name . '" is matched to more than one column. Each system column can be used once.';
            }

            $used[$headerId] = true;
            $clean[(int) $column] = $headerId;
        }

        return $clean;
    }

    /** @return list<string> */
    private function sheetNames(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);

        if ($reader instanceof Csv || ! method_exists($reader, 'listWorksheetNames')) {
            return ['Sheet1'];
        }

        return array_values($reader->listWorksheetNames($path));
    }

    private function loadSheet(string $path, ?string $sheet, ?int $maxRow = null): Worksheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if (! ($reader instanceof Csv) && $sheet !== null) {
            $reader->setLoadSheetsOnly([$sheet]);
        }

        if ($maxRow !== null) {
            $reader->setReadFilter(new class($maxRow) implements IReadFilter {
                public function __construct(private int $maxRow)
                {
                }

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row <= $this->maxRow;
                }
            });
        }

        return $reader->load($path)->getSheet(0);
    }

    /** @return array<int, array<int, string>> row => (column index => text) */
    private function topRows(string $path, string $sheet): array
    {
        $ws = $this->loadSheet($path, $sheet, self::TOP_ROWS);
        $maxColumn = Coordinate::columnIndexFromString($ws->getHighestColumn());
        $maxRow = min(self::TOP_ROWS, $ws->getHighestRow());
        $rows = [];

        for ($r = 1; $r <= $maxRow; $r++) {
            for ($c = 1; $c <= $maxColumn; $c++) {
                $text = $this->cleanHeaderName($this->plainText($ws->getCell([$c, $r])));

                if ($text !== '') {
                    $rows[$r][$c] = $text;
                }
            }
        }

        return $rows;
    }

    /**
     * The sheet that looks most like a BOM data sheet, and its title row.
     *
     * @param  list<string>  $sheets
     * @param  array<string, array<int, array<int, string>>>  $tops  already-read sheets
     * @return array{0: string, 1: int, 2: array}
     */
    private function bestSheet(string $path, array $sheets, Collection $headers, array $tops): array
    {
        $best = [$sheets[0], 1, -1];

        foreach (array_slice($sheets, 0, 12) as $name) {
            $tops[$name] ??= $this->topRows($path, $name);
            $scored = $this->matcher->scoreHeaderRow($tops[$name], $headers);

            if ($scored['score'] > $best[2]) {
                $best = [$name, $scored['row'], $scored['score']];
            }
        }

        return [$best[0], $best[1], $tops];
    }

    /**
     * The buyer's saved profile that fits this file, if one does.
     *
     * @return array{0: ?BuyerImportProfile, 1: array}
     */
    private function autoProfile(string $path, array $sheets, Collection $profiles): array
    {
        $tops = [];

        foreach ($profiles as $profile) {
            $sheet = in_array($profile->sheet_name, $sheets, true) ? $profile->sheet_name : $sheets[0];
            $tops[$sheet] ??= $this->topRows($path, $sheet);
            $columns = collect($tops[$sheet][$profile->header_row] ?? [])->all();

            if ($this->matcher->bestProfile(collect([$profile]), $columns)) {
                return [$profile, $tops];
            }
        }

        return [null, $tops];
    }

    /**
     * A few example values per column, from the rows under the title row.
     *
     * @return array<int, list<string>>
     */
    private function samples(array $top, int $headerRow, array $columnIndexes): array
    {
        $out = [];

        foreach ($columnIndexes as $c) {
            $out[$c] = collect(range($headerRow + 1, $headerRow + 4))
                ->map(fn ($r) => $top[$r][$c] ?? '')
                ->filter(fn ($v) => $v !== '')
                ->take(2)
                ->values()
                ->all();
        }

        return $out;
    }

    /**
     * Read the data rows under the title row into header-id keyed rows.
     *
     * @param  array<int, int>  $mapping  column index => header id
     * @return array{rows: array<int, array<int, ?string>>, titles: array<int, string>}
     */
    private function readRows(string $path, string $sheetName, int $headerRow, array $mapping, Collection $headers): array
    {
        $ws = $this->loadSheet($path, $sheetName);
        $maxColumn = Coordinate::columnIndexFromString($ws->getHighestColumn());

        $titles = [];
        for ($c = 1; $c <= $maxColumn; $c++) {
            $text = $this->cleanHeaderName($this->plainText($ws->getCell([$c, $headerRow])));

            if ($text !== '') {
                $titles[$c] = $text;
            }
        }

        $rows = [];
        $n = 0;

        for ($r = $headerRow + 1, $last = $ws->getHighestRow(); $r <= $last; $r++) {
            $values = [];
            $any = false;

            foreach ($mapping as $column => $headerId) {
                $value = $this->extractUploadedCellValue($ws, $column, $r, $headers[$headerId]);
                $values[$headerId] = $value;
                $any = $any || trim((string) ($value ?? '')) !== '';
            }

            if ($any) {
                $rows[++$n] = $values;
            }
        }

        return ['rows' => $rows, 'titles' => $titles];
    }

    /**
     * What the Style Library will look like after this file: mothers, children,
     * colours, and the rows that need a look. Uses StyleHierarchy, the same rule
     * the import itself applies, so the preview never promises something else.
     *
     * @param  array<int, array<int, mixed>>  $rows  row => (header id => value)
     * @param  array<int, int>  $mapping  file column => header id
     * @return array{styles: Collection, mothers: int, children: int, colors: int, no_style_rows: int, mother_fallback_rows: int, mother_column: bool}
     */
    private function summariseStyles(array $rows, ?int $styleId, ?int $motherId, ?int $colorId, array $mapping): array
    {
        $mapped = fn (?int $id) => $id !== null && in_array($id, $mapping, true);
        $text = fn (array $row, ?int $id) => $id === null ? '' : trim((string) ($row[$id] ?? ''));

        $motherColumn = $mapped($motherId);
        $groups = [];
        $colors = [];
        $noStyle = 0;
        $fallback = 0;

        if ($mapped($styleId)) {
            foreach ($rows as $row) {
                $code = $text($row, $styleId);

                if ($code === '') {
                    $noStyle++;

                    continue;
                }

                $motherCell = $motherColumn ? $text($row, $motherId) : '';
                if ($motherColumn && $motherCell === '') {
                    $fallback++;
                }

                $place = StyleHierarchy::resolve($code, $motherCell);
                $key = $place['mother'] . "\0" . ($place['child'] ?? '');
                $groups[$key] ??= $place + ['rows' => 0];
                $groups[$key]['rows']++;

                if ($mapped($colorId) && ($color = $text($row, $colorId)) !== '') {
                    $colors[mb_strtolower($color)] = true;
                }
            }
        }

        $styles = collect($groups)
            ->map(fn ($g) => ['style_no' => $g['mother'], 'child' => $g['child'], 'rows' => $g['rows'], 'source' => $g['source']])
            ->sortBy([['style_no', 'asc'], ['child', 'asc']])
            ->values();

        return [
            'styles' => $styles,
            'mothers' => $styles->pluck('style_no')->unique()->count(),
            'children' => $styles->pluck('child')->filter()->unique()->count(),
            'colors' => count($colors),
            'no_style_rows' => $noStyle,
            'mother_fallback_rows' => $fallback,
            'mother_column' => $motherColumn,
        ];
    }

    private function saveProfile(array $state, array $titles, array $mapping, int $userId): void
    {
        $profile = BuyerImportProfile::updateOrCreate(
            ['buyer_id' => $state['buyer_id'], 'name' => $state['profile_name'] !== '' ? $state['profile_name'] : 'Default'],
            ['sheet_name' => $state['sheet'], 'header_row' => $state['header_row'], 'created_by' => $userId]
        );

        $profile->columns()->delete();

        $seen = [];

        foreach ($titles as $column => $title) {
            $key = mb_substr($this->matcher->key($title), 0, 190);

            // A title repeated in the sheet is remembered once, first column wins.
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $profile->columns()->create([
                'source_header' => $title,
                'source_key' => $key,
                'excel_header_id' => $mapping[$column] ?? null,
            ]);
        }
    }
}
