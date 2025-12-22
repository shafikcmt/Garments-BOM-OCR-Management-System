<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Merchant\DashboardController as MerchantDashboardController;
use App\Http\Controllers\Admin\FieldController;


/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::get('/', fn() => view('welcome'));

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    Route::get('/dashboard', fn() => view('dashboard'))
        ->middleware('verified')
        ->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth', 'role:admin'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('admin.dashboard');

    // Orders CRUD & Approval
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('admin.orders.index');
    
    // Show order + role-wise dynamic fields
    Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('admin.orders.show');
    Route::post('/orders/{order}/fields', [AdminOrderController::class, 'storeFieldData'])->name('admin.orders.storeFieldData');

    // Approve / Reject
    Route::post('/orders/{order}/approve', [AdminOrderController::class, 'approve'])->name('admin.orders.approve');
    Route::post('/orders/{order}/reject', [AdminOrderController::class, 'reject'])->name('admin.orders.reject');
});


// Admin dynamic fields management
Route::prefix('admin')->middleware(['auth', 'role:admin'])->group(function() {
    // Sections
    Route::get('/fields', [FieldController::class, 'index'])->name('admin.fields.index');
    Route::get('/fields/create', [FieldController::class, 'create'])->name('admin.fields.create');
    Route::post('/fields', [FieldController::class, 'store'])->name('admin.fields.store');
    Route::get('/fields/{section}/edit', [FieldController::class, 'edit'])->name('admin.fields.edit');
    Route::put('/fields/{section}', [FieldController::class, 'update'])->name('admin.fields.update');
    Route::delete('/fields/{section}', [FieldController::class, 'destroy'])->name('admin.fields.destroy');

    // Fields inside a section
    Route::get('/fields/{section}/field/create', [FieldController::class, 'createField'])->name('admin.fields.create_field');
    Route::post('/fields/{section}/field', [FieldController::class, 'storeField'])->name('admin.fields.store_field');
    Route::get('/fields/{section}/field/{field}/edit', [FieldController::class, 'editField'])->name('admin.fields.edit_field');
    Route::put('/fields/{section}/field/{field}', [FieldController::class, 'updateField'])->name('admin.fields.update_field');
    Route::delete('/fields/{section}/field/{field}', [FieldController::class, 'destroyField'])->name('admin.fields.destroy_field');

    // ✅ Reorder fields via AJAX (drag & drop)
    Route::post('/fields/{section}/fields/reorder', [FieldController::class, 'reorderFields'])
        ->name('admin.fields.reorder_fields');
});

/*
|--------------------------------------------------------------------------
| Merchant Routes (Single Page Orders)
|--------------------------------------------------------------------------
*/

Route::prefix('merchant')
    ->middleware(['auth', 'role:merchant'])
    ->name('merchant.')
    ->group(function () {

        // Merchant Dashboard
        Route::get('/dashboard', [MerchantDashboardController::class, 'index'])
            ->name('dashboard');

        // Orders
        Route::get('/orders', [MerchantOrderController::class, 'index'])
            ->name('orders.index');

        // Store (Create + Edit)
        Route::post('/orders', [MerchantOrderController::class, 'store'])
            ->name('orders.store');

        // Delete Order
        Route::delete('/orders/{id}', [MerchantOrderController::class, 'destroy'])
            ->name('orders.destroy');
});



require __DIR__.'/auth.php';
