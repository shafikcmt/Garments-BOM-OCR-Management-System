<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Merchant\DashboardController;
use App\Http\Controllers\Merchant\WorkspaceController;
use App\Http\Controllers\Merchant\ExcelUploadController;
use App\Http\Controllers\Merchant\ReportController;
use App\Http\Controllers\Merchant\StyleLibraryController;
use App\Http\Controllers\Merchant\ImportWizardController;
use App\Http\Controllers\Merchant\StatusController;
use App\Http\Controllers\Merchant\BomBuilderController;
use App\Http\Controllers\Merchant\OcrSummaryController;
use App\Http\Controllers\Merchant\SizeBreakdownController;
use App\Http\Controllers\Merchant\VendorEntryController;

// Merchandising reports — read-only summaries of the BOM columns this
// department owns. Kept in their own group because they are shared with Admin /
// Management, while the main merchant group below stays merchant-only. The
// download routes re-check the role in ReportController.
Route::prefix('merchant/reports')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.reports.')
    ->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/pdf', [ReportController::class, 'pdf'])->name('pdf');
        Route::get('/excel', [ReportController::class, 'excel'])->name('excel');
    });

// Style / season status — read-only progress views shared with Admin and
// Management. The editable Style Library stays merchant-only below.
Route::prefix('merchant/status')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.status.')
    ->group(function () {
        Route::get('/', [StatusController::class, 'index'])->name('index');
        Route::get('/seasons/{season}', [StatusController::class, 'season'])->name('season');
        Route::get('/styles/{mother}', [StyleLibraryController::class, 'show'])->name('style');
    });

// OCR summaries (Contract, Material, Payment, Clearance) and the OCR workbook
// download. Read-only, shared with Admin and Management; buyer-scoped in the
// controller.
Route::prefix('merchant/ocr')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.ocr.')
    ->group(function () {
        Route::get('/', [OcrSummaryController::class, 'index'])->name('index');
        Route::get('/excel', [OcrSummaryController::class, 'excel'])->name('excel');
    });

// Colour x size breakdown of a style's order. Merchandising and Admin edit,
// Management reads; the controller checks the buyer.
Route::prefix('merchant/sizes')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.sizes.')
    ->group(function () {
        Route::get('/styles/{mother}', [SizeBreakdownController::class, 'show'])->name('show');
        Route::put('/styles/{mother}', [SizeBreakdownController::class, 'update'])->name('update');
        Route::put('/styles/{mother}/set', [SizeBreakdownController::class, 'chooseSet'])->name('set');
        Route::post('/styles/{mother}/sets', [SizeBreakdownController::class, 'storeSet'])->name('sets.store');
    });

// Apply a TnA template to a style (Merchandising, Admin, Management).
Route::post('merchant/tna/styles/{mother}', [\App\Http\Controllers\Merchant\TnaApplyController::class, 'store'])
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.tna.apply');

// Sample tracking. Merchandising and Admin edit, Management reads; the
// controller checks the buyer.
Route::prefix('merchant/samples')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.samples.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Merchant\SampleController::class, 'index'])->name('index');
        Route::post('/styles/{mother}', [\App\Http\Controllers\Merchant\SampleController::class, 'store'])->name('store');
        Route::put('/{sample}', [\App\Http\Controllers\Merchant\SampleController::class, 'update'])->name('update');
        Route::delete('/{sample}', [\App\Http\Controllers\Merchant\SampleController::class, 'destroy'])->name('destroy');
        Route::post('/types', [\App\Http\Controllers\Merchant\SampleController::class, 'storeType'])->name('types.store');
        Route::put('/types/{type}', [\App\Http\Controllers\Merchant\SampleController::class, 'toggleType'])->name('types.toggle');
    });

// Costing templates: ready cost sheets per product group. Merchandising and
// Admin manage, Management reads; the controller checks the buyer.
Route::prefix('merchant/costing-templates')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.costing_templates.')
    ->group(function () {
        $c = \App\Http\Controllers\Merchant\CostingTemplateController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/create', [$c, 'create'])->name('create');
        Route::post('/', [$c, 'store'])->name('store');
        Route::post('/from-costing/{costing}', [$c, 'fromCosting'])->name('from_costing');
        Route::get('/{template}/edit', [$c, 'edit'])->name('edit');
        Route::put('/{template}', [$c, 'update'])->name('update');
        Route::delete('/{template}', [$c, 'destroy'])->name('destroy');
    });

// Merchandising analytics: order value, margin, win rate. Read-only; buyer-scoped.
Route::get('merchant/analytics', [\App\Http\Controllers\Merchant\AnalyticsController::class, 'index'])
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.analytics.index');

// Buyer inquiries and quotations. Merchandising and Admin work them,
// Management reads; the controller checks the buyer and the role per action.
Route::prefix('merchant/inquiries')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.inquiries.')
    ->group(function () {
        $c = \App\Http\Controllers\Merchant\InquiryController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->name('store');
        Route::get('/{inquiry}', [$c, 'show'])->name('show');
        Route::put('/{inquiry}', [$c, 'update'])->name('update');
        Route::post('/{inquiry}/start-costing', [$c, 'startCosting'])->name('start_costing');
        Route::post('/{inquiry}/quote', [$c, 'quote'])->name('quote');
        Route::post('/{inquiry}/won', [$c, 'won'])->name('won');
        Route::post('/{inquiry}/lost', [$c, 'lost'])->name('lost');
        Route::post('/{inquiry}/cancel', [$c, 'cancel'])->name('cancel');
        Route::post('/{inquiry}/reopen', [$c, 'reopen'])->name('reopen');
    });

// Consumption master: standard materials per product group. Merchandising and
// Admin manage, Management reads; the controller checks the buyer.
Route::prefix('merchant/consumption-master')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.consumption.')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\Merchant\ConsumptionMasterController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Merchant\ConsumptionMasterController::class, 'store'])->name('store');
        Route::post('/from-style', [\App\Http\Controllers\Merchant\ConsumptionMasterController::class, 'fromStyle'])->name('from_style');
        Route::put('/{row}', [\App\Http\Controllers\Merchant\ConsumptionMasterController::class, 'update'])->name('update');
        Route::delete('/{row}', [\App\Http\Controllers\Merchant\ConsumptionMasterController::class, 'destroy'])->name('destroy');
    });

// Size-wise BOM of a style. Merchandising and Admin edit, Management reads.
Route::prefix('merchant/size-bom')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.sizebom.')
    ->group(function () {
        Route::get('/styles/{mother}', [\App\Http\Controllers\Merchant\SizeBomController::class, 'show'])->name('show');
        Route::put('/styles/{mother}', [\App\Http\Controllers\Merchant\SizeBomController::class, 'update'])->name('update');
    });

// Buyer orders (customer POs) of a style: register fields and order approval.
// Merchandising and Admin edit, Management reads and may approve; the
// controller checks the buyer and the role per action.
Route::prefix('merchant/orders')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.orders.')
    ->group(function () {
        Route::get('/styles/{mother}', [\App\Http\Controllers\Merchant\BuyerOrderController::class, 'show'])->name('show');
        Route::put('/styles/{mother}', [\App\Http\Controllers\Merchant\BuyerOrderController::class, 'update'])->name('update');
        Route::put('/styles/{mother}/submit', [\App\Http\Controllers\Merchant\BuyerOrderController::class, 'submit'])->name('submit');
        Route::post('/{order}/approve', [\App\Http\Controllers\Merchant\BuyerOrderController::class, 'approve'])->name('approve');
        Route::post('/{order}/return', [\App\Http\Controllers\Merchant\BuyerOrderController::class, 'returnOrder'])->name('return');
    });

// Document bank of a style. Merchandising and Admin upload / delete,
// Management downloads; the controller checks the buyer.
Route::prefix('merchant/documents')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('merchant.documents.')
    ->group(function () {
        Route::post('/styles/{mother}', [\App\Http\Controllers\Merchant\StyleDocumentController::class, 'store'])->name('store');
        Route::get('/{document}', [\App\Http\Controllers\Merchant\StyleDocumentController::class, 'download'])->name('download');
        Route::delete('/{document}', [\App\Http\Controllers\Merchant\StyleDocumentController::class, 'destroy'])->name('destroy');
    });

// BOM builder: make a style's BOM on a screen. Merchandising and Admin; the
// controller checks the buyer.
Route::prefix('merchant/bom')
    ->middleware(['auth', 'role:merchant,admin'])
    ->name('merchant.bom.')
    ->group(function () {
        Route::get('/styles/{mother}', [BomBuilderController::class, 'create'])->name('create');
        Route::post('/styles/{mother}', [BomBuilderController::class, 'store'])->name('store');
        Route::get('/styles/{mother}/materials', [BomBuilderController::class, 'suggest'])->name('suggest');
    });

Route::prefix('merchant')
    ->middleware(['auth', 'role:merchant'])
    ->name('merchant.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');

        // See, add, edit, import and export vendors (bank details included) without going through Admin.
        Route::get('/vendors', [VendorEntryController::class, 'index'])->name('vendors.index');
        Route::get('/vendors/export', [VendorEntryController::class, 'export'])->name('vendors.export');
        Route::get('/vendors/template', [VendorEntryController::class, 'template'])->name('vendors.template');
        Route::post('/vendors/import', [VendorEntryController::class, 'import'])->name('vendors.import');
        Route::get('/vendors/create', [VendorEntryController::class, 'create'])->name('vendors.create');
        Route::post('/vendors', [VendorEntryController::class, 'store'])->name('vendors.store');
        Route::get('/vendors/{supplier}/edit', [VendorEntryController::class, 'edit'])->name('vendors.edit');
        Route::put('/vendors/{supplier}', [VendorEntryController::class, 'update'])->name('vendors.update');

        // Buyer -> Season -> Mother Style -> Child Style.
        Route::get('/styles', [StyleLibraryController::class, 'index'])->name('styles.index');
        Route::get('/styles/mothers/{mother}', [StyleLibraryController::class, 'show'])->name('styles.mothers.show');
        Route::post('/styles/seasons', [StyleLibraryController::class, 'storeSeason'])->name('styles.seasons.store');
        Route::post('/styles/seasons/{season}/mothers', [StyleLibraryController::class, 'storeMother'])->name('styles.mothers.store');
        Route::post('/styles/mothers/{mother}/children', [StyleLibraryController::class, 'storeChild'])->name('styles.children.store');
        Route::delete('/styles/mothers/{mother}', [StyleLibraryController::class, 'destroyMother'])->name('styles.mothers.destroy');
        Route::delete('/styles/children/{child}', [StyleLibraryController::class, 'destroyChild'])->name('styles.children.destroy');

        // Import any buyer's Excel: choose -> match columns -> preview.
        Route::get('/import', [ImportWizardController::class, 'index'])->name('import.index');
        Route::post('/import', [ImportWizardController::class, 'upload'])->name('import.upload');
        Route::get('/import/map', [ImportWizardController::class, 'map'])->name('import.map');
        Route::post('/import/preview', [ImportWizardController::class, 'preview'])->name('import.preview');
        Route::post('/import/confirm', [ImportWizardController::class, 'confirm'])->name('import.confirm');
        Route::post('/import/cancel', [ImportWizardController::class, 'cancel'])->name('import.cancel');

        Route::get('/excel/sample', [ExcelUploadController::class, 'downloadSample'])->name('excel.sample');
        Route::post('/excel/upload', [ExcelUploadController::class, 'store'])->name('excel.store');
    });
