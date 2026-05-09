<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Merchant\DashboardController;
use App\Http\Controllers\Merchant\WorkspaceController;
use App\Http\Controllers\Merchant\ExcelUploadController;

Route::prefix('merchant')
    ->middleware(['auth', 'role:merchant'])
    ->name('merchant.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');

        Route::get('/excel/sample', [ExcelUploadController::class, 'downloadSample'])->name('excel.sample');
        Route::post('/excel/upload', [ExcelUploadController::class, 'store'])->name('excel.store');
        Route::post('/excel/manual-store', [ExcelUploadController::class, 'manualStore'])->name('excel.manual-store');
    });
