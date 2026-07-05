<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Management\DashboardController;

Route::prefix('management')
    ->middleware(['auth', 'role:management'])
    ->name('management.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });
