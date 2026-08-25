<?php

namespace App\Console\Commands;

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelHeader;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill: set excel_files.buyer_id on files uploaded before buyer
 * tagging existed, so the Merchandising report's buyer scope has something to
 * scope by.
 *
 * WHY A COMMAND AND NOT A MIGRATION. A migration runs unattended on deploy and
 * would tag production the first time somebody ran migrate. This has to be
 * looked at before it writes — hence dry-run by default and an explicit
 * --commit.
 *
 * WHAT IT WRITES. excel_files.buyer_id, and nothing else. It never touches a
 * buyer_name cell, a row, or the buyers table, and it never creates a buyer to
 * force a match. Read the only write in tag(): a single update of one column.
 *
 * THE RULE, deliberately narrow. A file is tagged only when EVERY one of its
 * rows carries the SAME non-empty Buyer Name, and that name matches an existing
 * buyers record exactly. Anything else is reported and left alone:
 *
 *   mixed buyers    excel_files.buyer_id holds ONE buyer, so a file carrying
 *                   several cannot be represented by it at all. Picking the
 *                   most common one would be a guess that silently hides the
 *                   rest from everybody else. See the note on
 *                   ExcelFile::buyer().
 *   unknown buyer   a name with no buyers record belongs to nobody, and
 *                   inventing the record to make the match work is how test
 *                   data becomes master data.
 *   any blank       a file where some rows name a buyer and others do not has
 *                   not actually agreed on one.
 *   already tagged  never overwritten. A value someone set deliberately is not
 *                   this command's to revise.
 *
 * Untagged is a safe resting state: under the report's scope rule an untagged
 * file stays visible to everyone, exactly as it is today.
 *
 * Matching is done in PHP against the loaded buyers list rather than in SQL, so
 * it behaves identically on MySQL and PostgreSQL — no LIKE, no collation.
 */
class TagExcelFileBuyers extends Command
{
    protected $signature = 'buyer:tag-excel-files {--commit : Write the changes. Without this the command only reports.}';

    protected $description = 'Tag excel_files.buyer_id for files whose rows all name one known buyer (dry-run by default)';

    public function handle(): int
    {
        $header = ExcelHeader::where('header_key', 'buyer_name')->first();

        if (! $header) {
            $this->error('No "buyer_name" header found. Nothing to read a buyer from.');

            return self::FAILURE;
        }

        $buyersByName = Buyer::pluck('id', 'buyer_name');

        $toTag = [];
        $skipped = [];

        foreach (ExcelFile::orderBy('id')->get() as $file) {
            [$decision, $detail, $buyerId] = $this->classify($file, $header->id, $buyersByName);

            if ($decision === 'tag') {
                $toTag[] = ['file' => $file, 'buyer_id' => $buyerId, 'detail' => $detail];

                continue;
            }

            $skipped[] = ['file' => $file, 'reason' => $decision, 'detail' => $detail];
        }

        $this->report($toTag, $skipped);

        if ($toTag === []) {
            $this->newLine();
            $this->info('Nothing to tag.');

            return self::SUCCESS;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->warn('DRY RUN - nothing was written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        $this->write($toTag);

        return self::SUCCESS;
    }

    /**
     * Decide what to do with one file.
     *
     * @param  \Illuminate\Support\Collection<string, int>  $buyersByName
     * @return array{0: string, 1: string, 2: int|null} decision, detail, buyer id
     */
    private function classify(ExcelFile $file, int $headerId, $buyersByName): array
    {
        if ($file->buyer_id !== null) {
            return ['already tagged', 'buyer_id = '.$file->buyer_id, null];
        }

        $rowIds = ExcelRow::where('excel_file_id', $file->id)->pluck('id');

        if ($rowIds->isEmpty()) {
            return ['no rows', 'file has no rows', null];
        }

        $values = ExcelCell::where('header_id', $headerId)
            ->whereIn('row_id', $rowIds)
            ->pluck('value')
            ->map(fn ($value) => trim((string) $value));

        // A row with no Buyer Name cell at all counts the same as a blank one:
        // it is a row that has not named a buyer.
        $missing = $rowIds->count() - $values->count();
        $blank = $values->filter(fn ($value) => $value === '')->count() + $missing;

        if ($blank > 0) {
            return ['blank buyer name', $blank.' of '.$rowIds->count().' rows name no buyer', null];
        }

        $distinct = $values->unique()->values();

        if ($distinct->count() > 1) {
            return [
                'mixed buyers',
                $distinct->count().' different buyer names: '.$distinct->implode(', '),
                null,
            ];
        }

        $name = $distinct->first();
        $buyerId = $buyersByName[$name] ?? null;

        if ($buyerId === null) {
            return ['unknown buyer', '"'.$name.'" matches no buyers record', null];
        }

        return ['tag', '"'.$name.'" on all '.$rowIds->count().' rows', (int) $buyerId];
    }

    /**
     * @param  list<array{file: ExcelFile, buyer_id: int, detail: string}>  $toTag
     * @param  list<array{file: ExcelFile, reason: string, detail: string}>  $skipped
     */
    private function report(array $toTag, array $skipped): void
    {
        $this->newLine();
        $this->info('Excel file buyer tagging'.($this->option('commit') ? '' : ' (dry run)'));
        $this->newLine();

        if ($toTag !== []) {
            $this->line('WOULD TAG:');
            $this->table(
                ['File ID', 'File name', 'Rows', 'Buyer', 'buyer_id', 'Why'],
                collect($toTag)->map(fn (array $row) => [
                    $row['file']->id,
                    $row['file']->original_file_name,
                    $row['file']->rows()->count(),
                    Buyer::find($row['buyer_id'])?->buyer_name ?? '?',
                    $row['buyer_id'],
                    $row['detail'],
                ])->all()
            );
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->line('LEFT UNTAGGED (needs a person, not a guess):');
            $this->table(
                ['File ID', 'File name', 'Reason', 'Detail'],
                collect($skipped)->map(fn (array $row) => [
                    $row['file']->id,
                    $row['file']->original_file_name,
                    $row['reason'],
                    $row['detail'],
                ])->all()
            );
        }
    }

    /**
     * The only write in this command: excel_files.buyer_id, one column.
     *
     * @param  list<array{file: ExcelFile, buyer_id: int, detail: string}>  $toTag
     */
    private function write(array $toTag): void
    {
        DB::transaction(function () use ($toTag) {
            foreach ($toTag as $row) {
                DB::table('excel_files')
                    ->where('id', $row['file']->id)
                    // Re-checked inside the transaction: a file tagged between
                    // the dry run and now is left as somebody else set it.
                    ->whereNull('buyer_id')
                    ->update(['buyer_id' => $row['buyer_id']]);

                $this->line('  tagged file '.$row['file']->id.' -> buyer_id '.$row['buyer_id']);
            }
        });

        $this->newLine();
        $this->info('Done. '.count($toTag).' file(s) tagged. No cell, row or buyer record was changed.');
    }
}
