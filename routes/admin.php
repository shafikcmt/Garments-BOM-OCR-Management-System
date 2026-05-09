<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HeaderController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\BookingDeliveryDestinationController;
use App\Http\Controllers\Admin\BookingInstructionController;

Route::prefix('admin')
    ->middleware(['auth', 'role:admin'])
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::resource('users', UserController::class)->except(['show']);
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('headers', HeaderController::class)->except(['show']);

        Route::resource('suppliers', SupplierController::class)->except(['show']);
        Route::resource('booking-delivery-destinations', BookingDeliveryDestinationController::class)->except(['show']);
        Route::resource('booking-instructions', BookingInstructionController::class)->except(['show']);
        
        Route::get('/workspace', [DashboardController::class, 'workspace'])->name('workspace');
    });