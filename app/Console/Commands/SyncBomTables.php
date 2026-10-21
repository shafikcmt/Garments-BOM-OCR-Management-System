<?php

namespace App\Console\Commands;

use App\Models\ExcelFile;
use App\Services\BomTableSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds (or rebuilds) order_lines / bom_items from the BOM workspace.
 *
 * Safe to run any number of times: it only inserts or updates the standard
 * tables and never touches the workspace. --dry-run does the whole job inside a
 * transaction and rolls it back, so the counts are exactly what a real run
 * would produce.
 */
class SyncBomTables extends Command
{
    protected $signature = 'bom:sync-tables {--file= : Only this excel_files id} {--dry-run : Show what would change, save nothing}';

    protected $description = 'Fill order_lines and bom_items from the BOM workspace and link styles to the Style Library';

    public function handle(BomTableSync $sync): int
    {
        $files = ExcelFile::query()
            ->when($this->option('file'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($files->isEmpty()) {
            $this->warn('No workspace files found.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $totals = ['rows' => 0, 'order_lines' => 0, 'bom_items' => 0, 'skipped' => 0];

        if ($dry) {
            DB::beginTransaction();
        }

        try {
            foreach ($files as $file) {
                $result = $sync->syncFile($file);
                $this->line(sprintf(
                    '#%d %s — rows %d, new order lines %d, new bom items %d, skipped (no style) %d',
                    $file->id, $file->original_file_name ?? $file->file_name, $result['rows'],
                    $result['order_lines'], $result['bom_items'], $result['skipped']
                ));

                foreach ($totals as $key => $value) {
                    $totals[$key] += $result[$key];
                }
            }
        } finally {
            if ($dry) {
                DB::rollBack();
            }
        }

        $this->info(($dry ? 'DRY RUN, nothing saved. ' : 'Done. ') . sprintf(
            'Rows %d, new order lines %d, new bom items %d, skipped %d.',
            $totals['rows'], $totals['order_lines'], $totals['bom_items'], $totals['skipped']
        ));

        return self::SUCCESS;
    }
}
