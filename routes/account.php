<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\WorkspaceController;

Route::prefix('account')
    ->middleware(['auth', 'role:account'])
    ->name('account.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
    });