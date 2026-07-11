<?php

namespace App\Models\Concerns;

use App\Services\MaterialStockLedgerService;

// Attach to any Buyer/Style stock event model (receiving / bulk issue /
// liability movement / dead movement). After the row is saved or deleted the
// cached material_stock_ledgers row for its (excel_row_id, size) key is
// recalculated. Also recalculates the OLD key when the row's key changes.
trait TriggersMaterialStockLedger
{
    public static function bootTriggersMaterialStockLedger(): void
    {
        static::saved(function ($model) {
            // If the key columns changed on update, refresh the previous key too.
            foreach (['excel_row_id', 'size'] as $keyColumn) {
                if ($model->wasChanged($keyColumn)) {
                    app(MaterialStockLedgerService::class)->recalculateKey(
                        (int) $model->getOriginal('excel_row_id'),
                        $model->getOriginal('size')
                    );
                    break;
                }
            }

            app(MaterialStockLedgerService::class)->recalculateFor($model);
        });

        static::deleted(function ($model) {
            app(MaterialStockLedgerService::class)->recalculateFor($model);
        });
    }
}
