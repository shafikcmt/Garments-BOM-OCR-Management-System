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

// Admin and Management are included alongside Store for the same reason the
// bulk-issue group below already includes them: corrections are their
// responsibility, so they have to be able to OPEN the screen that carries the
// record. Reaching a screen is not the same as being able to change it — every
// edit/delete action inside still requires the store.edit / store.delete
// permission, which Store does not hold.
Route::prefix('store')
    ->middleware(['auth', 'role:store,admin,management'])
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
            // PO lookup by PO No / PI No / Invoice No.
            Route::get('/receivings/po-search', [MaterialReceivingController::class, 'poSearch'])->name('receivings.po-search');
            // Buyer/style lookup for an Independent receiving, which has no PO.
            Route::get('/receivings/style-search', [MaterialReceivingController::class, 'styleSearch'])->name('receivings.style-search');
            // The material lines that style already carries on its BOM, used to
            // suggest values on the Independent form.
            Route::get('/receivings/style-bom', [MaterialReceivingController::class, 'styleBom'])->name('receivings.style-bom');
            // Every material line under one PO, for the item picker.
            Route::get('/receivings/po-items/{bookingPo}', [MaterialReceivingController::class, 'poItems'])->name('receivings.po-items');
            Route::post('/receivings', [MaterialReceivingController::class, 'store'])->name('receivings.store');
            // Record a receiving that matches no PO / PI / Invoice yet.
            Route::post('/receivings/independent', [MaterialReceivingController::class, 'storeIndependent'])->name('receivings.independent');
            // Attach an Independent receiving to the PO it turned out to be for.
            Route::post('/receivings/{materialReceiving}/link', [MaterialReceivingController::class, 'link'])->name('receivings.link');
            Route::delete('/receivings/{materialReceiving}', [MaterialReceivingController::class, 'destroy'])->name('receivings.destroy');

            // NOTE: bulk-issue routes live in their own group below — they are
            // shared with Admin / Management, who hold the correction rights the
            // store role does not.

            Route::get('/requisitions', [MaterialRequisitionController::class, 'index'])->name('requisitions.index');
            Route::post('/requisitions', [MaterialRequisitionController::class, 'store'])->name('requisitions.store');
            Route::patch('/requisitions/{materialRequisition}/approve', [MaterialRequisitionController::class, 'approve'])->name('requisitions.approve');
            Route::delete('/requisitions/{materialRequisition}', [MaterialRequisitionController::class, 'destroy'])->name('requisitions.destroy');
        });
    });

// Bulk Issuing — same URLs and route names as before, lifted out of the
// store-only group because Admin / Management need access too: a Store user
// records an issue but may not edit or delete it afterwards (every change
// recomputes closing stock), so corrections belong to Admin / Management.
// Access here is role-based; the edit/delete actions themselves are gated on
// the store.edit / store.delete permissions inside the controller and views.
Route::prefix('store/material-stock')
    ->middleware(['auth', 'role:store,admin,management'])
    ->name('store.material.')
    ->group(function () {
        Route::get('/bulk-issues', [MaterialBulkIssueController::class, 'index'])->name('bulk-issues.index');
        // Auto-fill lookup for the Record Bulk Issue form's PO/Material summary.
        Route::get('/bulk-issues/po-details/{bookingPo}', [MaterialBulkIssueController::class, 'poDetails'])->name('bulk-issues.po-details');
        // Item picker cascade: PO/PI/Invoice lookup, then the lines under one PO.
        // Bulk Issuing keeps its own pair rather than borrowing Receiving's,
        // which is store-only and would 403 for Admin / Management here.
        Route::get('/bulk-issues/po-search', [MaterialBulkIssueController::class, 'poSearch'])->name('bulk-issues.po-search');
        Route::get('/bulk-issues/po-items/{bookingPo}', [MaterialBulkIssueController::class, 'poItems'])->name('bulk-issues.po-items');
        Route::post('/bulk-issues', [MaterialBulkIssueController::class, 'store'])->name('bulk-issues.store');
        // Selection actions — static paths, declared before the {id} wildcard.
        Route::post('/bulk-issues/bulk-destroy', [MaterialBulkIssueController::class, 'bulkDestroy'])->name('bulk-issues.bulk-destroy');
        Route::post('/bulk-issues/export/excel', [MaterialBulkIssueController::class, 'exportExcel'])->name('bulk-issues.export.excel');
        Route::post('/bulk-issues/export/pdf', [MaterialBulkIssueController::class, 'exportPdf'])->name('bulk-issues.export.pdf');
        // Single-record read (edit prefill) + update.
        Route::get('/bulk-issues/{materialBulkIssue}', [MaterialBulkIssueController::class, 'show'])->name('bulk-issues.show');
        Route::put('/bulk-issues/{materialBulkIssue}', [MaterialBulkIssueController::class, 'update'])->name('bulk-issues.update');
        Route::delete('/bulk-issues/{materialBulkIssue}', [MaterialBulkIssueController::class, 'destroy'])->name('bulk-issues.destroy');
    });
