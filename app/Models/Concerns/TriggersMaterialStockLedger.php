<?php

namespace App\Models\Concerns;

use App\Services\MaterialStockLedgerCellSyncService;
use App\Services\MaterialStockLedgerService;

// Attach to any Buyer/Style stock event model (receiving / bulk issue /
// liability movement / dead movement). After the row is saved or deleted the
// cached material_stock_ledgers row for its (excel_row_id, size) key is
// recalculated, then the matching BOM Workspace cells are re-synced from the
// ledger. Also recalculates the OLD key when the row's key changes.
trait TriggersMaterialStockLedger
{
    public static function bootTriggersMaterialStockLedger(): void
    {
        static::saved(function ($model) {
            // If the key columns changed on update, refresh the previous key too.
            if ($model->wasChanged('excel_row_id') || $model->wasChanged('size')) {
                $oldRowId = (int) $model->getOriginal('excel_row_id');
                app(MaterialStockLedgerService::class)->recalculateKey(
                    $oldRowId,
                    $model->getOriginal('size')
                );
                self::syncWorkspaceCells($oldRowId);
            }

            app(MaterialStockLedgerService::class)->recalculateFor($model);
            self::syncWorkspaceCells((int) $model->excel_row_id);
        });

        static::deleted(function ($model) {
            app(MaterialStockLedgerService::class)->recalculateFor($model);
            self::syncWorkspaceCells((int) $model->excel_row_id);
        });
    }

    /**
     * Push the freshly recalculated ledger figures into the BOM Workspace cells
     * for one row. Isolated so a sync hiccup never breaks the ledger write.
     */
    protected static function syncWorkspaceCells(?int $excelRowId): void
    {
        if (! $excelRowId) {
            return;
        }

        app(MaterialStockLedgerCellSyncService::class)->syncRow($excelRowId);
    }
}
