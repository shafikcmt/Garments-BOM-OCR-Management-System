<?php

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Services\PurchaseRequisitionService;
use Illuminate\Database\Migrations\Migration;

/**
 * Sheets still on their way to the Store now follow the live stock
 * (PurchaseRequisitionService::refreshOpenSheetsFor). This brings the ones
 * already open up to today once, so they do not wait for the next receiving
 * or issue of their items. Data only: Stock in Hand, Safety Stock, To Be
 * Procured and Amount on those lines. down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = PurchaseRequisitionItem::query()
            ->whereNotNull('stock_item_id')
            ->whereHas('requisition', fn ($q) => $q
                ->where('level', PurchaseRequisition::LEVEL_DEPARTMENT)
                ->where('status', PurchaseRequisition::STATUS_SUBMITTED)
                ->whereNull('merged_into_id'))
            ->distinct()
            ->pluck('stock_item_id')
            ->all();

        app(PurchaseRequisitionService::class)->refreshOpenSheetsFor($ids);
    }

    public function down(): void
    {
        //
    }
};
