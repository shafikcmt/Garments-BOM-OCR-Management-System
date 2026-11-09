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

// Inbound shipments (BL / AWB, dates, the POs they carry) and the clearance
// summary. Commercial and Admin record, Management reads; the controller
// checks the buyer and the role per action.
Route::prefix('commercial/shipments')
    ->middleware(['auth', 'role:commercial,admin,management'])
    ->name('commercial.shipments.')
    ->group(function () {
        $c = \App\Http\Controllers\Commercial\ShipmentController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/clearance', [$c, 'clearance'])->name('clearance');
        Route::get('/create', [$c, 'create'])->name('create');
        Route::post('/', [$c, 'store'])->name('store');
        Route::get('/{shipment}', [$c, 'show'])->name('show');
        Route::put('/{shipment}', [$c, 'update'])->name('update');
        Route::delete('/{shipment}', [$c, 'destroy'])->name('destroy');
        Route::post('/{shipment}/pos', [$c, 'addPo'])->name('pos.add');
        Route::put('/{shipment}/pos/{link}', [$c, 'updatePo'])->name('pos.update');
        Route::delete('/{shipment}/pos/{link}', [$c, 'removePo'])->name('pos.remove');
    });

Route::prefix('commercial')
    ->middleware(['auth', 'role:commercial'])
    ->name('commercial.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
    });
