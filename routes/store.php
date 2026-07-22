<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Store\DashboardController;
use App\Http\Controllers\Store\WorkspaceController;
use App\Http\Controllers\Store\StockItemController;
use App\Http\Controllers\Store\StockPurchaseController;
use App\Http\Controllers\Store\StockIssueController;
use App\Http\Controllers\Store\GeneralStockLedgerController;
use App\Http\Controllers\Store\MaterialReceivingController;
use App\Http\Controllers\Store\MaterialBulkIssueController;
use App\Http\Controllers\Store\MaterialRequisitionController;
use App\Http\Controllers\Store\MaterialStockLedgerController;
use App\Http\Controllers\Store\ReportController;

// Store reports — read-only summaries. Kept in their own group because they are
// shared with Admin / Management (full access) and Merchant (preview only, the
// download routes re-check the role in ReportController). The main store group
// below keeps its original store-only access.
Route::prefix('store/reports')
    ->middleware(['auth', 'role:store,admin,management,merchant'])
    ->name('store.reports.')
    ->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/pdf', [ReportController::class, 'pdf'])->name('pdf');
        Route::get('/excel', [ReportController::class, 'excel'])->name('excel');
    });

Route::prefix('store')
    ->middleware(['auth', 'role:store'])
    ->name('store.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');

        // --- Module A: General Stock (non-BOM) ---
        Route::prefix('stock')->name('stock.')->group(function () {
            Route::get('/ledger', [GeneralStockLedgerController::class, 'index'])->name('ledger');

            Route::get('/items', [StockItemController::class, 'index'])->name('items.index');
            Route::post('/items', [StockItemController::class, 'store'])->name('items.store');
            Route::put('/items/{stockItem}', [StockItemController::class, 'update'])->name('items.update');
            Route::delete('/items/{stockItem}', [StockItemController::class, 'destroy'])->name('items.destroy');

            Route::get('/purchases', [StockPurchaseController::class, 'index'])->name('purchases.index');
            Route::post('/purchases', [StockPurchaseController::class, 'store'])->name('purchases.store');
            Route::delete('/purchases/{stockPurchase}', [StockPurchaseController::class, 'destroy'])->name('purchases.destroy');

            Route::get('/issues', [StockIssueController::class, 'index'])->name('issues.index');
            Route::post('/issues', [StockIssueController::class, 'store'])->name('issues.store');
            Route::delete('/issues/{stockIssue}', [StockIssueController::class, 'destroy'])->name('issues.destroy');
        });

        // --- Module B: Buyer/Style Stock (BOM/PO-linked) ---
        Route::prefix('material-stock')->name('material.')->group(function () {
            Route::get('/ledger', [MaterialStockLedgerController::class, 'index'])->name('ledger');
            Route::post('/ledger/{ledger}/liability-movement', [MaterialStockLedgerController::class, 'storeLiabilityMovement'])->name('ledger.liability');
            Route::post('/ledger/{ledger}/dead-movement', [MaterialStockLedgerController::class, 'storeDeadMovement'])->name('ledger.dead');

            Route::get('/receivings', [MaterialReceivingController::class, 'index'])->name('receivings.index');
            // Auto-fill lookup for the Record Receiving form's PO dropdown.
            Route::get('/receivings/po-details/{bookingPo}', [MaterialReceivingController::class, 'poDetails'])->name('receivings.po-details');
            // PO lookup by PO No / SAP Code / PI No.
            Route::get('/receivings/po-search', [MaterialReceivingController::class, 'poSearch'])->name('receivings.po-search');
            // Every material line under one PO, for the item picker.
            Route::get('/receivings/po-items/{bookingPo}', [MaterialReceivingController::class, 'poItems'])->name('receivings.po-items');
            Route::post('/receivings', [MaterialReceivingController::class, 'store'])->name('receivings.store');
            Route::delete('/receivings/{materialReceiving}', [MaterialReceivingController::class, 'destroy'])->name('receivings.destroy');

            Route::get('/bulk-issues', [MaterialBulkIssueController::class, 'index'])->name('bulk-issues.index');
            // Auto-fill lookup for the Record Bulk Issue form's PO/Material summary.
            Route::get('/bulk-issues/po-details/{bookingPo}', [MaterialBulkIssueController::class, 'poDetails'])->name('bulk-issues.po-details');
            Route::post('/bulk-issues', [MaterialBulkIssueController::class, 'store'])->name('bulk-issues.store');
            // Selection actions — static paths, declared before the {id} wildcard.
            Route::post('/bulk-issues/bulk-destroy', [MaterialBulkIssueController::class, 'bulkDestroy'])->name('bulk-issues.bulk-destroy');
            Route::post('/bulk-issues/export/excel', [MaterialBulkIssueController::class, 'exportExcel'])->name('bulk-issues.export.excel');
            Route::post('/bulk-issues/export/pdf', [MaterialBulkIssueController::class, 'exportPdf'])->name('bulk-issues.export.pdf');
            // Single-record read (edit prefill) + update.
            Route::get('/bulk-issues/{materialBulkIssue}', [MaterialBulkIssueController::class, 'show'])->name('bulk-issues.show');
            Route::put('/bulk-issues/{materialBulkIssue}', [MaterialBulkIssueController::class, 'update'])->name('bulk-issues.update');
            Route::delete('/bulk-issues/{materialBulkIssue}', [MaterialBulkIssueController::class, 'destroy'])->name('bulk-issues.destroy');

            Route::get('/requisitions', [MaterialRequisitionController::class, 'index'])->name('requisitions.index');
            Route::post('/requisitions', [MaterialRequisitionController::class, 'store'])->name('requisitions.store');
            Route::patch('/requisitions/{materialRequisition}/approve', [MaterialRequisitionController::class, 'approve'])->name('requisitions.approve');
            Route::delete('/requisitions/{materialRequisition}', [MaterialRequisitionController::class, 'destroy'])->name('requisitions.destroy');
        });
    });
