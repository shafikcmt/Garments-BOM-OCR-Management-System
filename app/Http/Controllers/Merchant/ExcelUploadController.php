<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExcelUploadRequest;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Str;
use Carbon\Carbon;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ExcelUploadController extends Controller
{
    public function create()
    {
        return redirect()->route('merchant.dashboard');
    }

    public function downloadSample()
    {
        $headers = $this->merchantInputHeaders();

        $spreadsheet = $this->buildMerchantInputWorkbook($headers);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 'MERCHANT_INPUT_FORMAT.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(ExcelUploadRequest $request)
    {
        DB::disableQueryLog();
        @set_time_limit(300);

        $file = $request->file('file');

        $allowedExtensions = ['csv', 'xls', 'xlsx'];
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions, true)) {
            return back()->withErrors([
                'file' => 'Only csv, xls, xlsx file is allowed.',
            ])->withInput();
        }

        $uploadableHeaders = $this->merchantInputHeaders();

        if ($uploadableHeaders->isEmpty()) {
            return back()->withErrors([
                'file' => 'No merchant input headers found. Please configure merchant input headers first.',
            ])->withInput();
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        if ($highestRow < 1) {
            return back()->withErrors(['file' => 'Empty file found.'])->withInput();
        }

        $allHeaders = $this->allActiveHeaders();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $uploadedHeaders = [];
        $uploadedHeaderKeyToColumn = [];
        $duplicateUploadedHeaders = [];

        for ($columnIndex = 1; $columnIndex <= $highestColumnIndex; $columnIndex++) {
            $headerText = $this->cleanHeaderName(
                $sheet->getCell($this->cellAddress($columnIndex, 1))->getFormattedValue()
            );

            if ($headerText === '') {
                continue;
            }

            $headerKey = $this->normalizeHeaderName($headerText);
            $uploadedHeaders[$columnIndex] = $headerText;

            if (isset($uploadedHeaderKeyToColumn[$headerKey])) {
                $duplicateUploadedHeaders[] = $headerText;
                continue;
            }

            $uploadedHeaderKeyToColumn[$headerKey] = $columnIndex;
        }

        if (! empty($duplicateUploadedHeaders)) {
            return back()->withErrors([
                'file' => 'Duplicate header found: ' . implode(', ', array_unique($duplicateUploadedHeaders)) . '.',
            ])->withInput();
        }

        $allActiveHeaderKeys = $this->allKnownUploadHeaderKeys($allHeaders);

        $missingHeaders = [];
        $headerColumnIndexByHeaderId = [];

        foreach ($uploadableHeaders as $header) {
            $matchedColumnIndex = null;

            foreach ($this->possibleUploadHeaderKeys($header) as $headerKey) {
                if (isset($uploadedHeaderKeyToColumn[$headerKey])) {
                    $matchedColumnIndex = $uploadedHeaderKeyToColumn[$headerKey];
                    break;
                }
            }

            if (! $matchedColumnIndex) {
                $missingHeaders[] = $header->header_name;
                continue;
            }

            $headerColumnIndexByHeaderId[$header->id] = $matchedColumnIndex;
        }

        $unknownHeaders = [];
        foreach ($uploadedHeaders as $headerText) {
            $headerKey = $this->normalizeHeaderName($headerText);
            if (! isset($allActiveHeaderKeys[$headerKey])) {
                $unknownHeaders[] = $headerText;
            }
        }

        if (! empty($missingHeaders) || ! empty($unknownHeaders)) {
            $message = 'Header mismatch. Please download the latest sample file.';

            if (! empty($missingHeaders)) {
                $message .= ' Missing: ' . implode(', ', $missingHeaders) . '.';
            }

            if (! empty($unknownHeaders)) {
                $message .= ' Unknown: ' . implode(', ', array_unique($unknownHeaders)) . '.';
            }

            return back()->withErrors(['file' => $message])->withInput();
        }

        $preparedRows = [];
        $savedRows = 0;

        for ($sheetRow = 2; $sheetRow <= $highestRow; $sheetRow++) {
            $valueByHeaderId = [];
            $hasAnyData = false;

            foreach ($uploadableHeaders as $header) {
                $columnIndex = $headerColumnIndexByHeaderId[$header->id] ?? null;
                $value = $columnIndex
                    ? $this->extractUploadedCellValue($sheet, $columnIndex, $sheetRow, $header)
                    : null;

                $valueByHeaderId[$header->id] = $value;

                if (trim((string) ($value ?? '')) !== '') {
                    $hasAnyData = true;
                }
            }

            if (! $hasAnyData) {
                continue;
            }

            $savedRows++;
            $preparedRows[$savedRows] = $valueByHeaderId;
        }
        if ($savedRows === 0) {
            return back()->withErrors([
                'file' => 'No data row found in uploaded file.',
            ])->withInput();
        }

        $fileName = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('excel_uploads', $fileName);
        $userId = auth()->id();

        $excelFile = DB::transaction(function () use ($request, $file, $fileName, $path, $allHeaders, $preparedRows, $savedRows, $userId) {
            $excelFile = ExcelFile::create([
                'file_name' => $fileName,
                'original_file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'uploaded_by' => $userId,
                'upload_batch_no' => 'BATCH-' . now()->format('YmdHis'),
                'total_rows' => 0,
                'status' => 'pending',
                'remarks' => $request->remarks,
                'submitted_at' => now(),
            ]);

            $this->bulkCreateRowsAndCells($excelFile, $allHeaders, $preparedRows, $userId);

            $excelFile->update([
                'total_rows' => $savedRows,
            ]);

            return $excelFile;
        });

        app(\App\Http\Controllers\Shared\ExcelFileController::class)
            ->recalculateFile($excelFile, $userId);

        $this->notifyOtherRoles(
            $excelFile,
            'excel_uploaded',
            'New file uploaded',
            auth()->user()->name . ' uploaded file: ' . $excelFile->original_file_name
        );

        return redirect()
            ->route('merchant.workspace', ['tab' => 'files'])
            ->with('success', 'Excel file uploaded successfully. Formula and conditional fields calculated automatically.');
    }

    public function manualStore(Request $request)
    {
        $request->validate([
            'manual_rows' => ['required', 'array'],
            'manual_rows.*' => ['array'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $uploadableHeaders = $this->merchantInputHeaders();

        if ($uploadableHeaders->isEmpty()) {
            return back()->withErrors([
                'manual_rows' => 'No merchant input headers found. Please configure merchant input headers first.',
            ])->withInput();
        }

        $allowedHeaderIds = $uploadableHeaders->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $allHeaders = $this->allActiveHeaders();
        $manualRows = (array) $request->input('manual_rows', []);

        $cleanRows = [];
        foreach ($manualRows as $rowValues) {
            $rowValues = (array) $rowValues;
            $cleanValues = [];

            foreach ($rowValues as $headerId => $value) {
                $headerId = (int) $headerId;

                if (! $allowedHeaderIds->has($headerId)) {
                    continue;
                }

                $value = is_string($value) ? trim($value) : $value;
                $cleanValues[$headerId] = $value === '' ? null : $value;
            }

            $hasAnyData = collect($cleanValues)->contains(function ($value) {
                return trim((string) ($value ?? '')) !== '';
            });

            if ($hasAnyData) {
                $cleanRows[] = $cleanValues;
            }
        }

        if (empty($cleanRows)) {
            return back()->withErrors([
                'manual_rows' => 'Please enter at least one row value before creating order.',
            ])->withInput();
        }

        $fileName = 'MANUAL_ORDER_' . now()->format('YmdHis') . '.xlsx';
        $path = 'excel_uploads/' . $fileName;
        $this->storeManualWorkbook($uploadableHeaders, $cleanRows, $path);

        $excelFile = DB::transaction(function () use ($request, $fileName, $path, $allHeaders, $cleanRows) {
            $excelFile = ExcelFile::create([
                'file_name' => $fileName,
                'original_file_name' => 'Manual Order ' . now()->format('Y-m-d H:i:s'),
                'file_path' => $path,
                'uploaded_by' => auth()->id(),
                'upload_batch_no' => 'BATCH-' . now()->format('YmdHis'),
                'total_rows' => 0,
                'status' => 'pending',
                'remarks' => $request->remarks,
                'submitted_at' => now(),
            ]);

            foreach ($cleanRows as $rowIndex => $valueByHeaderId) {
                $excelRow = ExcelRow::create([
                    'excel_file_id' => $excelFile->id,
                    'row_number' => $rowIndex + 1,
                ]);

                $this->createCellsForRow($excelRow, $allHeaders, $valueByHeaderId);
            }

            $excelFile->update([
                'total_rows' => count($cleanRows),
            ]);

            return $excelFile;
        });

        app(\App\Http\Controllers\Shared\ExcelFileController::class)
            ->recalculateFile($excelFile, auth()->id());

            $this->notifyOtherRoles(
                $excelFile,
                'excel_created',
                'New order created',
                auth()->user()->name . ' created a new order file: ' . $excelFile->original_file_name
            );

        return redirect()
            ->route('merchant.workspace', ['tab' => 'files'])
            ->with('success', 'New order created successfully. Formula and conditional fields calculated automatically.');
    }

    private function extractUploadedCellValue($sheet, int $columnIndex, int $rowNumber, $header): ?string
    {
        $cell = $sheet->getCell($this->cellAddress($columnIndex, $rowNumber));
        $rawValue = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        $formattedValue = trim((string) $cell->getFormattedValue());

        if ($this->isDateHeader($header)) {
            return $this->formatUploadedDate($rawValue, $formattedValue);
        }

        if ($rawValue instanceof DateTimeInterface) {
            return Carbon::instance($rawValue)->format('Y-m-d');
        }

        if (is_object($rawValue) && method_exists($rawValue, 'getPlainText')) {
            $rawValue = $rawValue->getPlainText();
        }

        if (is_bool($rawValue)) {
            return $rawValue ? '1' : '0';
        }

        if (is_int($rawValue) || is_float($rawValue) || (is_string($rawValue) && is_numeric(trim($rawValue)))) {
            return $this->cleanUploadedNumber($rawValue);
        }

        $value = trim((string) ($rawValue ?? ''));

        if ($value === '' && $formattedValue !== '') {
            $value = $formattedValue;
        }

        $value = str_replace(["\xC2\xA0", '–', '—'], [' ', '-', '-'], $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));

        if (str_contains($value, '%')) {
            $numericPercent = str_replace([',', ' ', '%'], '', $value);
            if (is_numeric($numericPercent)) {
                return $this->cleanUploadedNumber($numericPercent);
            }
        }

        return $value !== '' ? $value : null;
    }

    private function cleanUploadedNumber($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(["\xC2\xA0", ',', ' ', '%'], '', trim($value));
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (floor($number) == $number) {
            return (string) (int) $number;
        }

        $text = rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    private function formatUploadedDate($rawValue, ?string $formattedValue = null): ?string
    {
        if ($rawValue instanceof DateTimeInterface) {
            return Carbon::instance($rawValue)->format('Y-m-d');
        }

        if (is_numeric($rawValue)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $rawValue))->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        $value = trim((string) ($formattedValue ?: $rawValue));

        if ($value === '' || $value === '-' || preg_match('/^[mdy\/-]+$/i', $value)) {
            return null;
        }

        $formats = [
            'Y-m-d', 'Y/m/d', 'd-m-Y', 'd/m/Y', 'm-d-Y', 'm/d/Y',
            'd-M-Y', 'd M Y', 'M d, Y', 'm/d/y', 'd/m/y',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable $e) {
                // Try next format.
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isDateHeader($header): bool
    {
        $type = strtolower((string) ($header->field_type ?? $header->data_type ?? $header->input_type ?? $header->type ?? ''));

        if (in_array($type, ['date', 'datetime'], true)) {
            return true;
        }

        $key = $this->normalizeHeaderName($header->header_key ?? $header->header_name ?? null);

        if ($key === null || $key === '') {
            return false;
        }

        return Str::contains($key, ['date', '_dt', 'etd', 'eta', 'ata', 'inhouse', 'in_house']);
    }

    private function cleanHeaderName($value): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')));
    }

    private function allKnownUploadHeaderKeys(Collection $headers): Collection
    {
        return $headers
            ->flatMap(function ($header) {
                $keys = $this->possibleUploadHeaderKeys($header);
                $formulaKey = $this->normalizeHeaderName($header->formula_key ?? null);

                if ($formulaKey !== null && $formulaKey !== '') {
                    $keys[] = $formulaKey;
                }

                return $keys;
            })
            ->filter()
            ->unique()
            ->flip();
    }

    private function possibleUploadHeaderKeys($header): array
    {
        $names = array_filter([
            $header->header_name ?? null,
            $header->header_key ?? null,
        ]);

        $baseKeys = array_filter(array_map(fn ($name) => $this->normalizeHeaderName($name), $names));

        foreach ($this->uploadHeaderAliases() as $canonical => $aliases) {
            $aliasNames = array_merge([$canonical], $aliases);
            $aliasKeys = array_filter(array_map(fn ($name) => $this->normalizeHeaderName($name), $aliasNames));

            if (count(array_intersect($baseKeys, $aliasKeys)) > 0) {
                $names = array_merge($names, $aliasNames);
            }
        }

        return collect($names)
            ->map(fn ($name) => $this->normalizeHeaderName($name))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function uploadHeaderAliases(): array
    {
        return [
            'BOM Quantity' => ['BOM Qty', 'BOM QTY', 'Bom Quantity'],
            'Costing YY in SMS' => ['Costing YY', 'Costing YY SMS', 'YY in SMS', 'Costing YY (SMS)'],
            'Booking Consumption from CAD' => ['Booking Consumption', 'CAD Consumption', 'Booking Cons from CAD'],
            '% Wastage for ordering' => ['Wastage for ordering', 'Wastage % for ordering', 'Wastage for ordering %', 'Waste %', 'Wastage %'],
            'Consumption based on which materials order including YY' => ['Consumption including YY', 'Consumption incl YY', 'YY + Waste %'],
            'GMTS Order Qty' => ['GMTS Order Quantity', 'GMT Order Qty', 'Customer Contract Quantity'],
        ];
    }

    private function normalizeHeaderName($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower($this->cleanHeaderName($value));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = str_replace(["'", '’'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }

    private function cellAddress(int $columnIndex, int $rowNumber): string
    {
        return Coordinate::stringFromColumnIndex($columnIndex) . $rowNumber;
    }

    private function merchantInputHeaders(): Collection
    {
        $merchantRoleId = Role::where('name', 'merchant')->value('id');

        if (! $merchantRoleId) {
            return collect();
        }

        return ExcelHeader::where('is_active', true)
            ->where('owner_role_id', $merchantRoleId)
            ->where(function ($query) {
                $query->whereNull('value_mode')
                    ->orWhere('value_mode', 'input');
            })
            ->orderBy('position')
            ->get();
    }

    private function allActiveHeaders(): Collection
    {
        return ExcelHeader::where('is_active', true)
            ->orderBy('position')
            ->get();
    }

    private function bulkCreateRowsAndCells(ExcelFile $excelFile, Collection $allHeaders, array $rows, int $userId): void
    {
        if (empty($rows)) {
            return;
        }

        $now = now()->toDateTimeString();
        $rowInsertBatch = [];

        foreach (array_keys($rows) as $rowNumber) {
            $rowInsertBatch[] = [
                'excel_file_id' => $excelFile->id,
                'row_number' => $rowNumber,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rowInsertBatch, 500) as $chunk) {
            ExcelRow::insert($chunk);
        }

        $rowIdsByNumber = ExcelRow::where('excel_file_id', $excelFile->id)
            ->pluck('id', 'row_number');

        $cellInsertBatch = [];

        foreach ($rows as $rowNumber => $valueByHeaderId) {
            $rowId = $rowIdsByNumber[(int) $rowNumber] ?? null;

            if (! $rowId) {
                continue;
            }

            foreach ($allHeaders as $header) {
                $cellInsertBatch[] = [
                    'row_id' => $rowId,
                    'header_id' => $header->id,
                    'value' => $valueByHeaderId[$header->id] ?? null,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($cellInsertBatch) >= 1000) {
                    ExcelCell::insert($cellInsertBatch);
                    $cellInsertBatch = [];
                }
            }
        }

        if (! empty($cellInsertBatch)) {
            ExcelCell::insert($cellInsertBatch);
        }
    }

    private function createCellsForRow(ExcelRow $excelRow, Collection $allHeaders, array $valueByHeaderId): void
    {
        foreach ($allHeaders as $header) {
            ExcelCell::create([
                'row_id' => $excelRow->id,
                'header_id' => $header->id,
                'value' => $valueByHeaderId[$header->id] ?? null,
                'updated_by' => auth()->id(),
            ]);
        }
    }

    private function storeManualWorkbook(Collection $headers, array $rows, string $path): void
    {
        $spreadsheet = $this->buildMerchantInputWorkbook($headers, $rows);

        $tempPath = tempnam(sys_get_temp_dir(), 'manual_order_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        Storage::put($path, file_get_contents($tempPath));

        @unlink($tempPath);
    }

    private function buildMerchantInputWorkbook(Collection $headers, array $rows = []): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Merchant Input');

        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $cell = $column . '1';

            $sheet->setCellValue($cell, $header->header_name);

            $sheet->getStyle($cell)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => '1D4ED8'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DBEAFE'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'D1D5DB'],
                    ],
                ],
            ]);

            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        foreach ($rows as $rowIndex => $rowValues) {
            foreach ($headers as $colIndex => $header) {
                $column = Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->setCellValue($column . ($rowIndex + 2), $rowValues[$header->id] ?? null);
            }
        }

        if ($headers->isNotEmpty()) {
            $lastColumn = Coordinate::stringFromColumnIndex($headers->count());
            $sheet->getStyle('A1:' . $lastColumn . '1')
                ->getAlignment()
                ->setShrinkToFit(true);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:' . $lastColumn . '1');
        }

        $sheet->getRowDimension(1)->setRowHeight(28);

        return $spreadsheet;
    }

    private function notifyOtherRoles($excelFile, string $type, string $title, string $message, ?string $batchId = null): void
    {
        $users = User::where('id', '!=', auth()->id())
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'merchant');
            })
            ->get();

        foreach ($users as $user) {
            $notification = AppNotification::create([
                'user_id' => $user->id,
                'actor_id' => auth()->id(),
                'excel_file_id' => $excelFile->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => [
                    'batch_id' => $batchId,
                ],
            ]);

            $notification->update([
                'url' => route('uploaded-files.show', [
                    'excelFile' => $excelFile->id,
                    'notification' => $notification->id,
                    'batch' => $batchId,
                ]),
            ]);
        }
    }
}
