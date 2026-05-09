<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Commercial\DashboardController;
use App\Http\Controllers\Commercial\WorkspaceController;

Route::prefix('commercial')
    ->middleware(['auth', 'role:commercial'])
    ->name('commercial.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/workspace', [WorkspaceController::class, 'index'])->name('workspace');
    });