<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Commercial\DashboardController;
use App\Http\Controllers\Commercial\WorkspaceController;
use App\Http\Controllers\Commercial\ReportController;

// Commercial reports — read-only summaries of the payment, document and
// shipment columns this department owns. Kept in their own group because they
// are shared with Admin / Management, while the main commercial group below
// stays commercial-only. The download routes re-check the role in
// ReportController.
Route::prefix('commercial/reports')
    ->middleware(['auth', 'role:commercial,admin,management'])
    ->name('commercial.reports.')
    ->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/pdf', [ReportController::class, 'pdf'])->name('pdf');
        Route::get('/excel', [ReportController::class, 'excel'])->name('excel');
    });

Route::prefix('commercial')
    ->middleware(['auth', 'role:commercial'])
    ->name('commercial.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
    });
