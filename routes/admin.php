<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HeaderController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\BookingDeliveryDestinationController;
use App\Http\Controllers\Admin\BookingInstructionController;
use App\Http\Controllers\Admin\PoGenerateControlController;
use App\Http\Controllers\Admin\AlertSettingController;
use App\Http\Controllers\Admin\PaymentSettingController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\PraApproverController;

Route::prefix('admin')
    ->middleware(['auth', 'role:admin'])
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::resource('users', UserController::class)->except(['show']);
        Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/send-reset-link', [UserController::class, 'sendPasswordResetLink'])->name('users.send-reset-link');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('headers', HeaderController::class)->except(['show']);

        Route::resource('suppliers', SupplierController::class)->except(['show']);
        Route::resource('booking-delivery-destinations', BookingDeliveryDestinationController::class)->except(['show']);
        Route::resource('booking-instructions', BookingInstructionController::class)->except(['show']);

        Route::get('/po-generate-control', [PoGenerateControlController::class, 'index'])->name('po-generate-control.index');
        Route::get('/po-generate-control/pending', [PoGenerateControlController::class, 'pending'])->name('po-generate-control.pending');
        Route::get('/po-generate-control/generated', [PoGenerateControlController::class, 'generated'])->name('po-generate-control.generated');
        Route::get('/po-generate-control/po/{bookingPo}', [PoGenerateControlController::class, 'show'])->name('po-generate-control.show');
        Route::post('/po-generate-control/po/{bookingPo}/edit-preview', [PoGenerateControlController::class, 'editPreview'])->name('po-generate-control.edit_preview');
        Route::post('/po-generate-control/po/{bookingPo}/update', [PoGenerateControlController::class, 'update'])->name('po-generate-control.update');
        Route::patch('/po-generate-control/po/{bookingPo}/access', [PoGenerateControlController::class, 'saveAccess'])->name('po-generate-control.access');
        Route::delete('/po-generate-control/po/{bookingPo}', [PoGenerateControlController::class, 'destroy'])->name('po-generate-control.destroy');
        Route::post('/po-generate-control/po/{bookingPo}/regenerate-preview', [PoGenerateControlController::class, 'regeneratePreview'])->name('po-generate-control.regenerate_preview');
        Route::post('/po-generate-control/po/{bookingPo}/regenerate', [PoGenerateControlController::class, 'regenerate'])->name('po-generate-control.regenerate');
        Route::get('/po-generate-control/po/{bookingPo}/print', [PoGenerateControlController::class, 'print'])->name('po-generate-control.print');
        Route::get('/po-generate-control/po/{bookingPo}/download', [PoGenerateControlController::class, 'download'])->name('po-generate-control.download');
        Route::get('/po-generate-control/po/{bookingPo}/download-excel', [PoGenerateControlController::class, 'downloadExcel'])->name('po-generate-control.download_excel');
        
        Route::get('/alert-settings', [AlertSettingController::class, 'edit'])->name('alert-settings.edit');
        Route::put('/alert-settings', [AlertSettingController::class, 'update'])->name('alert-settings.update');

        Route::get('/payment-settings', [PaymentSettingController::class, 'edit'])->name('payment-settings.edit');
        Route::post('/payment-settings', [PaymentSettingController::class, 'update'])->name('payment-settings.update');

        Route::get('/email-templates', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
        Route::put('/email-templates', [EmailTemplateController::class, 'update'])->name('email-templates.update');

        Route::get('/workspace', [DashboardController::class, 'workspace'])->name('workspace');

        // PRA approver pool management + approval history + notification toggle
        Route::get('/pra-approvers', [PraApproverController::class, 'index'])->name('pra-approvers.index');
        Route::post('/pra-approvers', [PraApproverController::class, 'store'])->name('pra-approvers.store');
        Route::patch('/pra-approvers/{praApprover}', [PraApproverController::class, 'update'])->name('pra-approvers.update');
        Route::patch('/pra-approvers/{praApprover}/checker', [PraApproverController::class, 'toggleChecker'])->name('pra-approvers.checker');
        Route::delete('/pra-approvers/{praApprover}', [PraApproverController::class, 'destroy'])->name('pra-approvers.destroy');
        Route::put('/pra-approvers/settings', [PraApproverController::class, 'updateSettings'])->name('pra-approvers.settings');
        Route::get('/pra-approvals/history', [PraApproverController::class, 'history'])->name('pra-approvals.history');
    });