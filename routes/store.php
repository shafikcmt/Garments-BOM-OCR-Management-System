<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Store\DashboardController;
use App\Http\Controllers\Store\WorkspaceController;
use App\Http\Controllers\Store\StockItemController;
use App\Http\Controllers\Store\StockPurchaseController;
use App\Http\Controllers\Store\StockIssueController;
use App\Http\Controllers\Store\GeneralStockLedgerController;
use App\Http\Controllers\Store\IssueSetupController;
use App\Http\Controllers\Store\PurchaseSetupController;
use App\Http\Controllers\Store\StockSetupController;
use App\Http\Controllers\Store\PurchaseRequisitionController;
use App\Http\Controllers\Store\MonthlyRequisitionReportController;
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
            // Consumable Stock Report. PDF/Excel take the same query string as
            // the screen, so a download always matches what was previewed.
            Route::get('/ledger', [GeneralStockLedgerController::class, 'index'])->name('ledger');
            Route::get('/ledger/pdf', [GeneralStockLedgerController::class, 'pdf'])->name('ledger.pdf');
            Route::get('/ledger/excel', [GeneralStockLedgerController::class, 'excel'])->name('ledger.excel');

            // Master Setup — one screen for every General Stock master list.
            // Composes only: each form on it still posts to the Purchase Setup
            // or Issue Setup routes below, which are unchanged.
            Route::get('/setup', [StockSetupController::class, 'index'])->name('setup');

            // Issue Setup masters: Indent Section / Indent Person / Approved By
            // / Category. {type} is whitelisted inside IssueSetupController.
            //
            // The index now lives on Master Setup; this redirect keeps old
            // bookmarks and any link still pointing here working.
            Route::get('/issue-setup', fn () => redirect()->route('store.stock.setup', ['tab' => 'sections']))
                ->name('issue-setup.index');
            // Blank sample template, one per master type.
            Route::get('/issue-setup/{type}/template', [IssueSetupController::class, 'template'])->name('issue-setup.template');
            Route::post('/issue-setup/{type}', [IssueSetupController::class, 'store'])->name('issue-setup.store');
            Route::post('/issue-setup/{type}/bulk', [IssueSetupController::class, 'bulk'])->name('issue-setup.bulk');
            Route::delete('/issue-setup/{type}/bulk-delete', [IssueSetupController::class, 'bulkDestroy'])->name('issue-setup.bulk-delete');
            Route::put('/issue-setup/{type}/{id}', [IssueSetupController::class, 'update'])->name('issue-setup.update');
            Route::delete('/issue-setup/{type}/{id}', [IssueSetupController::class, 'destroy'])->name('issue-setup.destroy');

            Route::get('/items', [StockItemController::class, 'index'])->name('items.index');
            // Bulk item upload + its blank sample template.
            Route::get('/items/template', [StockItemController::class, 'template'])->name('items.template');
            Route::post('/items/import', [StockItemController::class, 'import'])->name('items.import');
            Route::post('/items', [StockItemController::class, 'store'])->name('items.store');
            Route::put('/items/{stockItem}', [StockItemController::class, 'update'])->name('items.update');
            Route::delete('/items/{stockItem}', [StockItemController::class, 'destroy'])->name('items.destroy');

            // Purchase Setup — General Stock's own supplier list, behind the
            // Record Purchase supplier dropdown.
            // As with Issue Setup, the index moved to Master Setup and this
            // redirect keeps every existing link and bookmark working.
            Route::get('/purchase-setup', fn () => redirect()->route('store.stock.setup', ['tab' => 'suppliers']))
                ->name('purchase-setup.index');
            Route::get('/purchase-setup/template', [PurchaseSetupController::class, 'template'])->name('purchase-setup.template');
            Route::post('/purchase-setup/import', [PurchaseSetupController::class, 'import'])->name('purchase-setup.import');
            Route::post('/purchase-setup', [PurchaseSetupController::class, 'store'])->name('purchase-setup.store');
            // Declared before /{supplier} so "bulk-delete" is never taken for a
            // supplier id by the DELETE route below.
            Route::delete('/purchase-setup/bulk-delete', [PurchaseSetupController::class, 'bulkDestroy'])->name('purchase-setup.bulk-delete');
            Route::put('/purchase-setup/{supplier}', [PurchaseSetupController::class, 'update'])->name('purchase-setup.update');
            Route::delete('/purchase-setup/{supplier}', [PurchaseSetupController::class, 'destroy'])->name('purchase-setup.destroy');

            Route::get('/purchases', [StockPurchaseController::class, 'index'])->name('purchases.index');
            Route::post('/purchases', [StockPurchaseController::class, 'store'])->name('purchases.store');
            Route::delete('/purchases/{stockPurchase}', [StockPurchaseController::class, 'destroy'])->name('purchases.destroy');

            // Read-only stock position for one item — the Issue form calls this
            // to warn about low / zero stock before the issue is confirmed.
            Route::get('/issues/item-status/{stockItem}', [StockIssueController::class, 'itemStatus'])->name('issues.item-status');

            Route::get('/issues', [StockIssueController::class, 'index'])->name('issues.index');
            Route::post('/issues', [StockIssueController::class, 'store'])->name('issues.store');
            Route::delete('/issues/{stockIssue}', [StockIssueController::class, 'destroy'])->name('issues.destroy');

            // Purchase Requisition — replaces the hand-kept
            // "Month_Of_<Month>.xlsx" workbook. Single-item and multi-item
            // requisitions are one record type differing by `mode`, so they
            // share every route here.
            //
            // The two read-only lookups are declared BEFORE /{requisition} so
            // "item-snapshot" and "next-number" are never taken for a
            // requisition id by the model-bound routes below.
            Route::get('/requisitions/item-snapshot/{stockItem}', [PurchaseRequisitionController::class, 'itemSnapshot'])
                ->name('requisitions.item-snapshot');
            Route::get('/requisitions/next-number', [PurchaseRequisitionController::class, 'nextNumber'])
                ->name('requisitions.next-number');

            // Monthly report — every requisition of a month compiled into one
            // list plus per-category groupings, replacing the hand-built
            // "Month_Of_<Month>.xlsx" workbook. Read-only, and declared with
            // the other fixed paths ABOVE /{requisition} so "report" is never
            // taken for a requisition id.
            Route::get('/requisitions/report', [MonthlyRequisitionReportController::class, 'index'])
                ->name('requisitions.report');
            Route::get('/requisitions/report/pdf', [MonthlyRequisitionReportController::class, 'pdf'])
                ->name('requisitions.report.pdf');
            Route::get('/requisitions/report/excel', [MonthlyRequisitionReportController::class, 'excel'])
                ->name('requisitions.report.excel');

            Route::get('/requisitions', [PurchaseRequisitionController::class, 'index'])->name('requisitions.index');
            Route::get('/requisitions/create', [PurchaseRequisitionController::class, 'create'])->name('requisitions.create');
            Route::post('/requisitions', [PurchaseRequisitionController::class, 'store'])->name('requisitions.store');
            Route::get('/requisitions/{requisition}', [PurchaseRequisitionController::class, 'show'])->name('requisitions.show');

            // One requisition as its own printable document — the same layout
            // as the monthly report, scoped to a single requisition.
            Route::get('/requisitions/{requisition}/pdf', [PurchaseRequisitionController::class, 'pdf'])
                ->name('requisitions.pdf');
            Route::get('/requisitions/{requisition}/excel', [PurchaseRequisitionController::class, 'excel'])
                ->name('requisitions.excel');

            Route::get('/requisitions/{requisition}/edit', [PurchaseRequisitionController::class, 'edit'])->name('requisitions.edit');
            Route::put('/requisitions/{requisition}', [PurchaseRequisitionController::class, 'update'])->name('requisitions.update');
            Route::delete('/requisitions/{requisition}', [PurchaseRequisitionController::class, 'destroy'])->name('requisitions.destroy');
        });

        // --- Module B: Buyer/Style Stock (BOM/PO-linked) ---
        Route::prefix('material-stock')->name('material.')->group(function () {
            Route::get('/ledger', [MaterialStockLedgerController::class, 'index'])->name('ledger');
            Route::post('/ledger/{ledger}/liability-movement', [MaterialStockLedgerController::class, 'storeLiabilityMovement'])->name('ledger.liability');
            Route::post('/ledger/{ledger}/dead-movement', [MaterialStockLedgerController::class, 'storeDeadMovement'])->name('ledger.dead');

            Route::get('/receivings', [MaterialReceivingController::class, 'index'])->name('receivings.index');
            // Receiving History register downloads (whole history, screen order).
            // Read-only, so they sit under the same role gate as the listing —
            // declared before the {materialReceiving} wildcard below.
            // Receiving History rows only, for the filter bar's live reload.
            // Read-only and under the same role gate as the listing; declared
            // before the {materialReceiving} wildcard below.
            Route::get('/receivings/history-data', [MaterialReceivingController::class, 'historyData'])->name('receivings.history-data');
            Route::get('/receivings/export/excel', [MaterialReceivingController::class, 'exportExcel'])->name('receivings.export.excel');
            Route::get('/receivings/export/pdf', [MaterialReceivingController::class, 'exportPdf'])->name('receivings.export.pdf');
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
        // Full-page create / edit. Declared before the {materialBulkIssue}
        // wildcard below, which would otherwise swallow "create" and fail to
        // bind it to a record.
        //
        // The slide-in panel on the index page still works and is being kept
        // until this page has been signed off; both render the same form
        // partial, so there is one form to maintain, not two.
        Route::get('/bulk-issues/create', [MaterialBulkIssueController::class, 'create'])->name('bulk-issues.create');
        Route::get('/bulk-issues/{materialBulkIssue}/edit', [MaterialBulkIssueController::class, 'edit'])->name('bulk-issues.edit');
        // Auto-fill lookup for the Record Bulk Issue form's PO/Material summary.
        Route::get('/bulk-issues/po-details/{bookingPo}', [MaterialBulkIssueController::class, 'poDetails'])->name('bulk-issues.po-details');
        // Item picker cascade: PO/PI/Invoice lookup, then the lines under one PO.
        // Bulk Issuing keeps its own pair rather than borrowing Receiving's,
        // which is store-only and would 403 for Admin / Management here.
        Route::get('/bulk-issues/po-search', [MaterialBulkIssueController::class, 'poSearch'])->name('bulk-issues.po-search');
        Route::get('/bulk-issues/po-items/{bookingPo}', [MaterialBulkIssueController::class, 'poItems'])->name('bulk-issues.po-items');
        // Buyer-first entry: every item under all of one buyer's POs at once.
        // Read-only, and only reached when stock.bulk_issue_multi_po is on.
        Route::get('/bulk-issues/buyer-items', [MaterialBulkIssueController::class, 'buyerItems'])->name('bulk-issues.buyer-items');
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
