<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Store\DashboardController;
use App\Http\Controllers\Store\MaterialDashboardController;
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
use App\Http\Controllers\Store\ReceivingReportController;
use App\Http\Controllers\Store\IssueReportController;
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

/*
 * Phase 3 — the store guards accept a PERMISSION as well as a role.
 *
 * role_or_perm passes when the user holds ANY of the listed roles OR any of the
 * listed permissions. The three roles that could reach these screens yesterday
 * are still listed first and still pass exactly as before, so this step cannot
 * take access from anyone; it only opens a second door for a role that holds
 * the permission but is not one of those three. That is what makes a narrow
 * role — one scoped to General Stock only, say — possible at all: until now a
 * user who was not store, admin or management was refused at the door however
 * many permissions they held.
 *
 * The guards are module-level: General Stock and Buyer / Style Stock can now be
 * granted independently of each other, which is the split the business asked
 * for. Section-level guards (Issues without Receiving) are the next step and
 * need the route order left alone, so they are deliberately not done here.
 *
 * Phase 4 removes the role names, at which point these become permission-only.
 * Not before every module runs on this and has been verified.
 */

// Entry to the store area. Wide on purpose: it only has to admit somebody as
// far as the dashboard, and the module guards below decide what they can then
// open. A user holding any single General Stock or Buyer/Style permission gets
// a landing page rather than a 403 with nowhere to go.
$storeEntry = 'role_or_perm:store|admin|management'
    .'|store.stock_report.view|store.items.view|store.receiving.view'
    .'|store.issues.view|store.requisition.view|store.setup.view'
    .'|material.closing_stock.view|material.receiving.view'
    .'|material.bulk_issue.view|material.requisitions.view';

// Module A — General Stock. The outer gate: holding ANY General Stock
// permission gets you into the module, and the section guards below decide
// which of its screens you can actually open.
$generalStock = 'role_or_perm:store|admin|management'
    .'|store.stock_report.view|store.items.view|store.receiving.view'
    .'|store.issues.view|store.requisition.view|store.setup.view';

/*
 * Section guards inside General Stock.
 *
 * The module gate above cannot tell Issues from Receiving, so a user granted
 * one was given both. These separate them: each run of routes is wrapped in the
 * guard for the section it belongs to, and a user reaches only the sections
 * they hold a permission for.
 *
 * The three roles are still listed on every one of them, so store, admin and
 * management pass exactly as before and nothing narrows for anyone who has
 * access today. What changes is that a NEW user can now be given
 * store.issues.view alone and get Issues without Receiving.
 *
 * ROUTE ORDER IS UNCHANGED. Wrapping a run in a group does not move it —
 * Laravel registers routes in declaration order either way — so every ordering
 * rule the comments below describe still holds. Setup is declared in three
 * separate runs with Items between them; they are deliberately left where they
 * are and given three groups rather than being gathered into one, because
 * gathering them would be a genuine reorder for no benefit.
 *
 * These decide whether a section can be OPENED. What may then be done inside it
 * is the action guards below (create) and AuthorizesStoreCorrections in the
 * controllers (edit / delete).
 */
$secStockReport = 'role_or_perm:store|admin|management|store.stock_report.view';
$secSetup = 'role_or_perm:store|admin|management|store.setup.view';
$secItems = 'role_or_perm:store|admin|management|store.items.view';
$secReceiving = 'role_or_perm:store|admin|management|store.receiving.view';
$secIssues = 'role_or_perm:store|admin|management|store.issues.view';
$secRequisition = 'role_or_perm:store|admin|management|store.requisition.view';

/*
 * Action guards, for the routes that CREATE a record.
 *
 * Edit and delete were already covered, one layer down: every General Stock
 * controller uses AuthorizesStoreCorrections, which refuses a change to an
 * existing record without store.edit / store.delete. Creation had no check at
 * anything — reaching a section was enough to post a new record into it — so
 * this is the half that was missing.
 *
 * Roles are still listed first, so store, admin and management create exactly
 * what they could create before. The guard bites only for a user who was let
 * into a section by a view permission alone.
 */
$act = fn (string $permission) => 'role_or_perm:store|admin|management|'.$permission;

// Module B — Buyer / Style Stock. Bulk Issuing has its own group further down.
$materialStock = 'role_or_perm:store|admin|management'
    .'|material.closing_stock.view|material.receiving.view|material.requisitions.view';

// Admin and Management are included alongside Store for the same reason the
// bulk-issue group below already includes them: corrections are their
// responsibility, so they have to be able to OPEN the screen that carries the
// record. Reaching a screen is not the same as being able to change it — every
// edit/delete action inside still requires the store.edit / store.delete
// permission, which Store does not hold.
/*
 * Workspace.
 *
 * Deliberately OUTSIDE the store entry group below. That guard asks whether
 * somebody may enter the Store area at all, and any single General Stock
 * permission satisfies it — which is how a Store — General Stock user came to
 * open BOM files nobody had decided they should see. Workspace asks its own
 * question now, and store.workspace.view is the only answer to it.
 *
 * `can:` rather than the project's usual role_or_perm: this one is a
 * permission and nothing else, no role names admitted. It routes through the
 * Gate, so Gate::before keeps super admin working, and the store and
 * management roles reach it by carrying the permission in their bundle rather
 * than by being named here.
 *
 * Granting it per user is the Additional Permissions matrix's job, on the user
 * edit screen, where it appears under General Stock / Workspace like any other
 * permission. It briefly had a screen of its own; one permission did not
 * justify a second place to look, a second menu item and a second set of
 * guard rails to keep in step with the first.
 */
Route::prefix('store')
    ->middleware(['auth'])
    ->name('store.')
    ->group(function () {
        Route::get('/workspace', [WorkspaceController::class, 'index'])
            ->middleware('can:store.workspace.view')
            ->name('workspace');
    });

Route::prefix('store')
    ->middleware(['auth', $storeEntry])
    ->name('store.')
    ->group(function () use ($generalStock, $materialStock, $secStockReport, $secSetup, $secItems, $secReceiving, $secIssues, $secRequisition, $act) {
        // The dashboard is General Stock's own now, so it sits behind that
        // module's guard rather than the area-wide one. A Buyer / Style user
        // is sent to their own dashboard instead — see the resolver on
        // /dashboard in routes/web.php.
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware($generalStock)
            ->name('dashboard');

        // --- Module A: General Stock (non-BOM) ---
        Route::prefix('stock')->middleware($generalStock)->name('stock.')->group(function () use ($secStockReport, $secSetup, $secItems, $secReceiving, $secIssues, $secRequisition, $act) {
            // Consumable Stock Report. PDF/Excel take the same query string as
            // the screen, so a download always matches what was previewed.
            Route::middleware($secStockReport)->group(function () {
                Route::get('/ledger', [GeneralStockLedgerController::class, 'index'])->name('ledger');
                Route::get('/ledger/pdf', [GeneralStockLedgerController::class, 'pdf'])->name('ledger.pdf');
                Route::get('/ledger/excel', [GeneralStockLedgerController::class, 'excel'])->name('ledger.excel');
            });

            // Master Setup — one screen for every General Stock master list.
            // Composes only: each form on it still posts to the Purchase Setup
            // or Issue Setup routes below, which are unchanged.
            Route::middleware($secSetup)->group(function () {
                Route::get('/setup', [StockSetupController::class, 'index'])->name('setup');
            });

            // Issue Setup masters: Indent Section / Indent Person / Approved By
            // / Category. {type} is whitelisted inside IssueSetupController.
            //
            // The index now lives on Master Setup; this redirect keeps old
            // bookmarks and any link still pointing here working.
            Route::middleware($secSetup)->group(function () {
            Route::get('/issue-setup', fn () => redirect()->route('store.stock.setup', ['tab' => 'sections']))
                ->name('issue-setup.index');
            // Blank sample template, one per master type.
            Route::get('/issue-setup/{type}/template', [IssueSetupController::class, 'template'])->name('issue-setup.template');
            Route::post('/issue-setup/{type}', [IssueSetupController::class, 'store'])->name('issue-setup.store');
            Route::post('/issue-setup/{type}/bulk', [IssueSetupController::class, 'bulk'])->name('issue-setup.bulk');
            Route::delete('/issue-setup/{type}/bulk-delete', [IssueSetupController::class, 'bulkDestroy'])->name('issue-setup.bulk-delete');
            Route::put('/issue-setup/{type}/{id}', [IssueSetupController::class, 'update'])->name('issue-setup.update');
            Route::delete('/issue-setup/{type}/{id}', [IssueSetupController::class, 'destroy'])->name('issue-setup.destroy');
            });

            Route::middleware($secItems)->group(function () use ($act) {
            Route::get('/items', [StockItemController::class, 'index'])->name('items.index');
            // Bulk item upload + its blank sample template.
            Route::get('/items/template', [StockItemController::class, 'template'])->name('items.template');
            Route::post('/items/import', [StockItemController::class, 'import'])->middleware($act('store.items.create'))->name('items.import');
            Route::post('/items', [StockItemController::class, 'store'])->middleware($act('store.items.create'))->name('items.store');
            Route::put('/items/{stockItem}', [StockItemController::class, 'update'])->name('items.update');
            Route::delete('/items/{stockItem}', [StockItemController::class, 'destroy'])->name('items.destroy');
            });

            // Purchase Setup — General Stock's own supplier list, behind the
            // Record Purchase supplier dropdown.
            // As with Issue Setup, the index moved to Master Setup and this
            // redirect keeps every existing link and bookmark working.
            Route::middleware($secSetup)->group(function () {
            Route::get('/purchase-setup', fn () => redirect()->route('store.stock.setup', ['tab' => 'suppliers']))
                ->name('purchase-setup.index');
            Route::get('/purchase-setup/template', [PurchaseSetupController::class, 'template'])->name('purchase-setup.template');
            Route::post('/purchase-setup/import', [PurchaseSetupController::class, 'import'])->name('purchase-setup.import');
            Route::post('/purchase-setup', [PurchaseSetupController::class, 'store'])->name('purchase-setup.store');
            // Declared before /{supplier} so "bulk-delete" is never taken for a
            // supplier id by the DELETE route below.
            Route::delete('/purchase-setup/bulk-delete', [PurchaseSetupController::class, 'bulkDestroy'])->name('purchase-setup.bulk-delete');
            // One supplier: contact details and its purchase history. Read-only,
            // so it needs nothing beyond reaching the Setup section. Declared
            // after "template" and "import" so neither is taken for an id.
            Route::get('/purchase-setup/{supplier}', [PurchaseSetupController::class, 'show'])->name('purchase-setup.show');
            Route::put('/purchase-setup/{supplier}', [PurchaseSetupController::class, 'update'])->name('purchase-setup.update');
            Route::delete('/purchase-setup/{supplier}', [PurchaseSetupController::class, 'destroy'])->name('purchase-setup.destroy');
            });

            Route::middleware($secReceiving)->group(function () use ($act) {
            // Bulk receiving upload + its blank sample template. Declared above
            // the model-bound purchase routes so "template" and "import" are
            // never taken for a purchase id.
            Route::get('/purchases/template', [StockPurchaseController::class, 'template'])->name('purchases.template');
            Route::post('/purchases/import', [StockPurchaseController::class, 'import'])->middleware($act('store.receiving.create'))->name('purchases.import');

            // Receiving report — read-only, and declared with the other fixed
            // paths ABOVE the model-bound routes so "report" is never taken for
            // a purchase id. Access is this group's: store, admin, management.
            Route::get('/purchases/report', [ReceivingReportController::class, 'index'])->name('purchases.report');
            Route::get('/purchases/report/pdf', [ReceivingReportController::class, 'pdf'])->name('purchases.report.pdf');
            Route::get('/purchases/report/excel', [ReceivingReportController::class, 'excel'])->name('purchases.report.excel');

            Route::get('/purchases', [StockPurchaseController::class, 'index'])->name('purchases.index');
            Route::post('/purchases', [StockPurchaseController::class, 'store'])->middleware($act('store.receiving.create'))->name('purchases.store');
            // Correct one line of a recorded receiving. No matching GET, for the
            // reason the issues.update route gives. RV No / Challan No / Challan
            // Date are not accepted here at all — they identify the delivery.
            Route::put('/purchases/{stockPurchase}', [StockPurchaseController::class, 'update'])->name('purchases.update');
            Route::delete('/purchases/{stockPurchase}', [StockPurchaseController::class, 'destroy'])->name('purchases.destroy');
            });

            Route::middleware($secIssues)->group(function () use ($act) {
            // Read-only stock position for one item — the Issue form calls this
            // to warn about low / zero stock before the issue is confirmed.
            Route::get('/issues/item-status/{stockItem}', [StockIssueController::class, 'itemStatus'])->name('issues.item-status');

            // Issues report — read-only, declared above /issues/{stockIssue}
            // for the same reason as the receiving one.
            Route::get('/issues/report', [IssueReportController::class, 'index'])->name('issues.report');
            Route::get('/issues/report/pdf', [IssueReportController::class, 'pdf'])->name('issues.report.pdf');
            Route::get('/issues/report/excel', [IssueReportController::class, 'excel'])->name('issues.report.excel');

            Route::get('/issues', [StockIssueController::class, 'index'])->name('issues.index');
            Route::post('/issues', [StockIssueController::class, 'store'])->middleware($act('store.issues.create'))->name('issues.store');
            // Bulk consumption upload. Guarded exactly as the receiving pair
            // is: the blank template rides the section's own guard, and the
            // upload itself needs the create right, because importing issues
            // is issuing.
            Route::get('/issues/template', [StockIssueController::class, 'template'])->name('issues.template');
            Route::post('/issues/import', [StockIssueController::class, 'import'])->middleware($act('store.issues.create'))->name('issues.import');
            // The rows the last import could not take, to fix and upload again.
            // Guarded with create rather than view: it is held in the importing
            // user's own session and is only reachable by whoever just ran the
            // import that produced it.
            Route::get('/issues/import/skipped-rows', [StockIssueController::class, 'skippedRows'])->middleware($act('store.issues.create'))->name('issues.skipped-rows');
            // Closing the notice. POST rather than GET because it changes
            // server state — a GET here would be followed by a link prefetcher
            // and throw the rows away before the user had touched anything.
            Route::post('/issues/import/skipped-rows/dismiss', [StockIssueController::class, 'dismissSkippedRows'])->middleware($act('store.issues.create'))->name('issues.skipped-rows.dismiss');
            // Correct one recorded issue. No matching GET: the form is a modal
            // prefilled from the row already on screen, so an edit page would be
            // an endpoint nothing calls. Gated inside the controller on
            // store.issues.edit / store.edit, the same pair the Delete below
            // uses for its own action.
            Route::put('/issues/{stockIssue}', [StockIssueController::class, 'update'])->name('issues.update');
            Route::delete('/issues/{stockIssue}', [StockIssueController::class, 'destroy'])->name('issues.destroy');
            });

            Route::middleware($secRequisition)->group(function () use ($act) {
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
            Route::get('/requisitions/create', [PurchaseRequisitionController::class, 'create'])->middleware($act('store.requisition.create'))->name('requisitions.create');
            Route::post('/requisitions', [PurchaseRequisitionController::class, 'store'])->middleware($act('store.requisition.create'))->name('requisitions.store');
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
        });

        // --- Module B: Buyer/Style Stock (BOM/PO-linked) ---
        Route::prefix('material-stock')->middleware($materialStock)->name('material.')->group(function () {
            // This module's own dashboard. It used to share one screen with
            // General Stock, which meant its figures were shown to users who
            // cannot open any of the screens below.
            Route::get('/dashboard', [MaterialDashboardController::class, 'index'])->name('dashboard');
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
    ->middleware(['auth', 'role_or_perm:store|admin|management|material.bulk_issue.view'])
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
