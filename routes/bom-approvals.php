<?php

use App\Http\Controllers\BomApprovalController;
use Illuminate\Support\Facades\Route;

// BOM approval. Merchandising submits and decides (department admins), Management
// decides at the second level when it is switched on, Admin sets the switches.
// Who may do what on one BOM is checked in BomApprovals, not here.
Route::prefix('bom-approvals')
    ->middleware(['auth', 'role:merchant,admin,management'])
    ->name('bom_approvals.')
    ->group(function () {
        Route::get('/', [BomApprovalController::class, 'index'])->name('index');
        Route::post('/settings', [BomApprovalController::class, 'settings'])->name('settings');
        Route::post('/styles/{mother}/submit', [BomApprovalController::class, 'submit'])->name('submit');
        Route::post('/{approval}/approve', [BomApprovalController::class, 'approve'])->name('approve');
        Route::post('/{approval}/return', [BomApprovalController::class, 'returnBack'])->name('return');
    });
