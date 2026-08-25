<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Merchant\DashboardController;
use App\Http\Controllers\Merchant\WorkspaceController;
use App\Http\Controllers\Merchant\ExcelUploadController;
use App\Http\Controllers\Merchant\ReportController;

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

        Route::get('/excel/sample', [ExcelUploadController::class, 'downloadSample'])->name('excel.sample');
        Route::post('/excel/upload', [ExcelUploadController::class, 'store'])->name('excel.store');
    });
