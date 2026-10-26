<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\WorkspaceController;
use App\Http\Controllers\Account\PaymentController;

Route::prefix('account')
    ->middleware(['auth', 'role:account'])
    ->name('account.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments/{paymentRequest}/record', [PaymentController::class, 'record'])->name('payments.record');
    });