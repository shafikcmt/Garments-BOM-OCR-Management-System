<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * One-time fill of order_lines / bom_items for BOM files uploaded before those
 * tables existed, so the Merchandising dashboard and Style Status read real
 * orders on the live server without anyone running `bom:sync-tables` by hand.
 *
 * Data only, no schema. The sync is idempotent (insert or update the standard
 * tables, never touches the workspace), so running it here and again by hand
 * is harmless. A failure is logged, not thrown: it must not stop the deploy,
 * and the command can still be run by hand afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('stock.sync_bom_tables', true)) {
            return;
        }

        try {
            Artisan::call('bom:sync-tables');
        } catch (\Throwable $e) {
            Log::warning('Backfilling order_lines / bom_items failed; run `php artisan bom:sync-tables` by hand.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the standard tables are rebuilt from the workspace.
    }
};
