<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Merchant\DashboardController;
use App\Http\Controllers\Merchant\WorkspaceController;
use App\Http\Controllers\Merchant\ExcelUploadController;
use App\Http\Controllers\Merchant\ReportController;
use App\Http\Controllers\Merchant\StyleLibraryController;
use App\Http\Controllers\Merchant\ImportWizardController;

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

Route::prefix('merchant')
    ->middleware(['auth', 'role:merchant'])
    ->name('merchant.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');

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
