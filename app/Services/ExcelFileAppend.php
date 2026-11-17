<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelFileImport;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Adding the rows of a new Excel file to the END of an existing workspace file
 * (the import wizard's "Add to existing file"), instead of making a new file.
 *
 * The rules live here so the wizard screens, the confirm step and the tests all
 * ask one place: which files may take rows, which incoming rows are already in
 * the file, and when an import may be undone.
 */
class ExcelFileAppend
{
    /**
     * Files of the same buyer and season the user may add rows to, newest first.
     *
     * @param  list<int>  $buyerIds
     * @return Collection<int, array{id: int, buyer_id: int, season: string, name: string, rows: int, styles: string, updated: string}>
     */
    public function candidates(User $user, array $buyerIds): Collection
    {
        if ($buyerIds === []) {
            return collect();
        }

        return ExcelFile::query()
            ->with('season')
            ->whereIn('buyer_id', $buyerIds)
            ->whereNotNull('buyer_season_id')
            ->latest('updated_at')
            ->get()
            ->filter(fn (ExcelFile $file) => $this->refusal($file, $user) === null)
            ->map(fn (ExcelFile $file) => [
                'id' => $file->id,
                'buyer_id' => (int) $file->buyer_id,
                'season' => mb_strtoupper((string) $file->season?->code),
                'name' => (string) ($file->original_file_name ?: $file->file_name),
                'rows' => (int) $file->rows()->count(),
                'updated' => $file->updated_at?->format('d M Y') ?? '',
            ])->values();
    }

    /** Why this user cannot add rows to the file, or null when they can. */
    public function refusal(ExcelFile $file, User $user, ?int $buyerId = null, ?string $seasonCode = null): ?string
    {
        if (! $user->hasRole('admin') && ! $user->hasRole('merchant')) {
            return 'Your role cannot add rows to a file.';
        }

        if ($file->isLockedForUser($user)) {
            return 'This file is locked by admin.';
        }

        if ($file->isBuyerLockedForUser($user)) {
            return 'This file belongs to another buyer.';
        }

        if (! $file->buyer_id || ! $file->buyer_season_id) {
            return 'This file has no buyer and season, so it cannot take new rows.';
        }

        if (! $user->hasBuyerAccess((int) $file->buyer_id)) {
            return 'This file belongs to another buyer.';
        }

        if ($buyerId !== null && (int) $file->buyer_id !== $buyerId) {
            return 'The file is for a different buyer.';
        }

        if ($seasonCode !== null && mb_strtoupper((string) $file->season?->code) !== mb_strtoupper(trim($seasonCode))) {
            return 'The file is for a different season.';
        }

        return null;
    }

    /**
     * Which incoming rows the file already holds. A row is a duplicate when every
     * mapped column has the same value as one row of the file; each existing row
     * can be matched once, so a genuinely repeated line is not wrongly dropped.
     *
     * @param  array<int, array<int, mixed>>  $incoming  row number => (header id => value)
     * @param  list<int>  $headerIds  the mapped headers
     * @return list<int> incoming row numbers that are already in the file
     */
    public function duplicates(ExcelFile $file, array $incoming, array $headerIds): array
    {
        if ($incoming === [] || $headerIds === []) {
            return [];
        }

        $headerIds = array_values(array_unique(array_map('intval', $headerIds)));
        sort($headerIds);

        $cells = [];
        ExcelCell::query()
            ->whereIn('header_id', $headerIds)
            ->whereIn('row_id', ExcelRow::where('excel_file_id', $file->id)->select('id'))
            ->orderBy('id')
            ->each(function (ExcelCell $cell) use (&$cells) {
                $cells[$cell->row_id][(int) $cell->header_id] = $cell->value;
            }, 2000);

        $existing = [];
        foreach ($cells as $values) {
            $key = $this->signature($values, $headerIds);
            $existing[$key] = ($existing[$key] ?? 0) + 1;
        }

        $duplicates = [];
        foreach ($incoming as $rowNumber => $values) {
            $key = $this->signature($values, $headerIds);

            if (($existing[$key] ?? 0) > 0) {
                $existing[$key]--;
                $duplicates[] = (int) $rowNumber;
            }
        }

        return $duplicates;
    }

    /** @param array<int, mixed> $values */
    private function signature(array $values, array $headerIds): string
    {
        return implode("\x1f", array_map(function ($id) use ($values) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($values[$id] ?? '')));

            if ($v !== '' && is_numeric(str_replace(',', '', $v))) {
                $v = rtrim(rtrim(number_format((float) str_replace(',', '', $v), 6, '.', ''), '0'), '.');
            }

            return mb_strtolower($v);
        }, $headerIds));
    }

    /** The file's latest import that has not been undone, if any. */
    public function latestActive(ExcelFile $file): ?ExcelFileImport
    {
        return ExcelFileImport::where('excel_file_id', $file->id)->whereNull('undone_at')->latest('id')->first();
    }

    /**
     * Why this import cannot be undone (empty = it can): it must be the latest
     * one, and nobody may have worked on its rows or built anything from them.
     *
     * @return list<string>
     */
    public function undoBlockers(ExcelFileImport $import, User $user): array
    {
        if ($import->undone_at) {
            return ['it was already undone'];
        }

        $file = $import->file;
        $reasons = [];

        if ($refusal = $this->refusal($file, $user)) {
            return [rtrim($refusal, '.')];
        }

        $latest = $this->latestActive($file);
        if (! $latest || $latest->id !== $import->id) {
            $reasons[] = 'a later import was added after it, undo that one first';
        }

        $rows = $import->rows()->get();
        if ($rows->isEmpty()) {
            return ['its rows are no longer in the file'];
        }

        // The ordinary row rules say whether anything was done with the rows;
        // the "came from the uploaded file" reason is expected here, so skipped.
        foreach (ExcelRow::deletionBlockersFor($rows, $user) as $why) {
            foreach ($why as $reason) {
                if (str_contains($reason, 'came from the uploaded file')) {
                    continue;
                }
                $reasons[] = $reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    /** Remove the block of rows again. Call only when undoBlockers() is empty. */
    public function undo(ExcelFileImport $import, User $user): int
    {
        $file = $import->file;
        $count = 0;

        DB::transaction(function () use ($import, $file, $user, &$count) {
            $rows = $import->rows()->get();
            $count = $rows->count();

            ActivityLog::create([
                'excel_file_id' => $file->id,
                'row_id' => null,
                'header_id' => null,
                'old_value' => 'Added '.$count.' rows from '.$import->source_name,
                'new_value' => null,
                'action' => 'import_undone',
                'user_id' => $user->id,
            ]);

            ExcelRow::whereIn('id', $rows->pluck('id'))->delete();

            // Keep the numbers 1..n with no gap.
            $n = 0;
            foreach (ExcelRow::where('excel_file_id', $file->id)->orderBy('row_number')->get(['id', 'row_number']) as $row) {
                $n++;
                if ((int) $row->row_number !== $n) {
                    $row->update(['row_number' => $n]);
                }
            }

            $import->update(['undone_at' => now(), 'undone_by' => $user->id]);
            $file->update(['total_rows' => $n]);
        });

        return $count;
    }
}
