<?php

use App\Http\Controllers\CostingController;
use Illuminate\Support\Facades\Route;

// Pre-costing. Merchandising and Admin edit, Management reads; the controller
// checks the buyer and the role per action.
Route::prefix('costing')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('costing.')
    ->group(function () {
        Route::get('/styles/{mother}', [CostingController::class, 'show'])->name('show');
        Route::post('/styles/{mother}/start', [CostingController::class, 'start'])->name('start');
        Route::post('/styles/{mother}/revise', [CostingController::class, 'revise'])->name('revise');
        Route::put('/{costing}', [CostingController::class, 'update'])->name('update');
        Route::post('/{costing}/finalize', [CostingController::class, 'finalize'])->name('finalize');
        Route::post('/{costing}/send-for-approval', [CostingController::class, 'sendForApproval'])->name('send');
        Route::post('/{costing}/approve', [CostingController::class, 'approve'])->name('approve');
        Route::post('/{costing}/return', [CostingController::class, 'returnForChanges'])->name('return');
    });
