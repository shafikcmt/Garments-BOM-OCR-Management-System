<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HeaderController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\BuyerController;
use App\Http\Controllers\Admin\BookingDeliveryDestinationController;
use App\Http\Controllers\Admin\BookingInstructionController;
use App\Http\Controllers\Admin\PoGenerateControlController;
use App\Http\Controllers\Admin\AlertSettingController;
use App\Http\Controllers\Admin\PaymentSettingController;
use App\Http\Controllers\Admin\RequisitionSignatureSettingController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\PraApproverController;

/*
 * User Management is the one admin screen a Department Admin can also reach,
 * over the users of their own department only. It therefore sits outside the
 * role:admin group below — that guard admits nobody but a super admin, and
 * every other admin route still lives under it, unchanged.
 *
 * Access is decided by App\Policies\UserPolicy instead, which the controller
 * calls on every action including the two password routes. Route middleware
 * could only ask "which role"; the real question is "which users", and only a
 * policy holding both the actor and the target can answer that.
 */
Route::prefix('admin')
    ->middleware(['auth'])
    ->name('admin.')
    ->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
        Route::put('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/send-reset-link', [UserController::class, 'sendPasswordResetLink'])->name('users.send-reset-link');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    });

Route::prefix('admin')
    ->middleware(['auth', 'role:admin'])
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::resource('roles', RoleController::class)->except(['show']);
        Route::resource('headers', HeaderController::class)->except(['show']);
        Route::get('unmapped-columns', [\App\Http\Controllers\Admin\UnmappedColumnController::class, 'index'])->name('unmapped.index');
        Route::post('unmapped-columns/teach', [\App\Http\Controllers\Admin\UnmappedColumnController::class, 'teach'])->name('unmapped.teach');
        Route::post('unmapped-columns/ignore', [\App\Http\Controllers\Admin\UnmappedColumnController::class, 'ignore'])->name('unmapped.ignore');
        Route::post('unmapped-columns/reopen', [\App\Http\Controllers\Admin\UnmappedColumnController::class, 'reopen'])->name('unmapped.reopen');
        Route::put('tna-settings', [\App\Http\Controllers\Admin\TnaTemplateController::class, 'settings'])->name('tna.settings');
        Route::resource('tna', \App\Http\Controllers\Admin\TnaTemplateController::class)->except(['show'])->parameters(['tna' => 'tna']);

        Route::get('suppliers/export', [SupplierController::class, 'export'])->name('suppliers.export');
        Route::get('suppliers/template', [SupplierController::class, 'template'])->name('suppliers.template');
        Route::post('suppliers/import', [SupplierController::class, 'import'])->name('suppliers.import');
        Route::resource('suppliers', SupplierController::class)->except(['show']);
        Route::resource('supplier-options', \App\Http\Controllers\Admin\SupplierOptionController::class)->only(['index', 'store', 'destroy']);
        Route::resource('buyers', BuyerController::class)->except(['show']);

        // Who works on a buyer. Assignment only — buyer_scope_mode is never
        // written here; Admin > Users is the one screen that changes it.
        Route::post('buyers/{buyer}/users', [BuyerController::class, 'attachUser'])->name('buyers.users.attach');
        Route::delete('buyers/{buyer}/users/{user}', [BuyerController::class, 'detachUser'])->name('buyers.users.detach');

        // Which workspace columns a buyer uses (per-buyer column view).
        Route::get('buyers/{buyer}/columns', [\App\Http\Controllers\Admin\BuyerColumnController::class, 'edit'])->name('buyers.columns.edit');
        Route::put('buyers/{buyer}/columns', [\App\Http\Controllers\Admin\BuyerColumnController::class, 'update'])->name('buyers.columns.update');
        Route::post('buyers/{buyer}/columns/from-profile', [\App\Http\Controllers\Admin\BuyerColumnController::class, 'fromProfile'])->name('buyers.columns.from_profile');

        // Saved import profiles per buyer (column matching + style rule).
        Route::get('buyers/{buyer}/profiles', [\App\Http\Controllers\Admin\BuyerImportProfileController::class, 'index'])->name('buyers.profiles.index');
        Route::put('buyers/{buyer}/profiles/{profile}', [\App\Http\Controllers\Admin\BuyerImportProfileController::class, 'update'])->name('buyers.profiles.update');
        Route::post('buyers/{buyer}/profiles/{profile}/copy', [\App\Http\Controllers\Admin\BuyerImportProfileController::class, 'copy'])->name('buyers.profiles.copy');
        Route::delete('buyers/{buyer}/profiles/{profile}', [\App\Http\Controllers\Admin\BuyerImportProfileController::class, 'destroy'])->name('buyers.profiles.destroy');
        Route::resource('booking-delivery-destinations', BookingDeliveryDestinationController::class)->except(['show']);

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

        // The steps and signature boxes of a departmental PRA (how many, order, who for, box titles).
        Route::get('/pra-steps', [\App\Http\Controllers\Admin\PraStepController::class, 'index'])->name('pra-steps.index');
        Route::post('/pra-steps', [\App\Http\Controllers\Admin\PraStepController::class, 'store'])->name('pra-steps.store');
        Route::post('/pra-steps/prepared-box', [\App\Http\Controllers\Admin\PraStepController::class, 'savePrepared'])->name('pra-steps.prepared');
        Route::post('/pra-steps/data-locks', [\App\Http\Controllers\Admin\PraStepController::class, 'saveDataLocks'])->name('pra-steps.data-locks');
        Route::put('/pra-steps/{praStep}', [\App\Http\Controllers\Admin\PraStepController::class, 'update'])->name('pra-steps.update');
        Route::post('/pra-steps/{praStep}/move', [\App\Http\Controllers\Admin\PraStepController::class, 'move'])->name('pra-steps.move');
        Route::post('/pra-steps/{praStep}/toggle', [\App\Http\Controllers\Admin\PraStepController::class, 'toggle'])->name('pra-steps.toggle');

        // Admin correction: send PRAs that are part-way through back to an earlier step.
        Route::get('/pra-send-back', [\App\Http\Controllers\Admin\PraSendBackController::class, 'index'])->name('pra-send-back.index');
        Route::post('/pra-send-back', [\App\Http\Controllers\Admin\PraSendBackController::class, 'store'])->name('pra-send-back.store');

        // Who approves each step of a departmental PRA (per step, per buyer).
        Route::get('/pra-routing', [\App\Http\Controllers\Admin\PraRoutingController::class, 'index'])->name('pra-routing.index');
        Route::post('/pra-routing/{sequence}', [\App\Http\Controllers\Admin\PraRoutingController::class, 'saveStep'])->whereNumber('sequence')->name('pra-routing.save');
        Route::post('/pra-routing/{sequence}/buyer', [\App\Http\Controllers\Admin\PraRoutingController::class, 'saveBuyer'])->whereNumber('sequence')->name('pra-routing.save_buyer');
        Route::delete('/pra-routing/{sequence}/buyer/{buyer}', [\App\Http\Controllers\Admin\PraRoutingController::class, 'removeBuyer'])->whereNumber('sequence')->name('pra-routing.remove_buyer');

        Route::get('/payment-settings', [PaymentSettingController::class, 'edit'])->name('payment-settings.edit');
        Route::post('/payment-settings', [PaymentSettingController::class, 'update'])->name('payment-settings.update');

        // General Stock item SKU: format, category codes, the dry-run preview,
        // generating the missing SKUs, and the Admin correction of one SKU.
        Route::get('/item-sku', [\App\Http\Controllers\Admin\ItemSkuSettingController::class, 'edit'])->name('item-sku.edit');
        Route::post('/item-sku/pattern', [\App\Http\Controllers\Admin\ItemSkuSettingController::class, 'updatePattern'])->name('item-sku.pattern');
        Route::post('/item-sku/codes', [\App\Http\Controllers\Admin\ItemSkuSettingController::class, 'updateCodes'])->name('item-sku.codes');
        Route::get('/item-sku/preview', [\App\Http\Controllers\Admin\ItemSkuSettingController::class, 'preview'])->name('item-sku.preview');
        Route::post('/item-sku/apply', [\App\Http\Controllers\Admin\ItemSkuSettingController::class, 'apply'])->name('item-sku.apply');
        Route::post('/item-sku/items/{stockItem}', [\App\Http\Controllers\Admin\ItemSkuSettingController::class, 'correct'])->name('item-sku.correct');

        // The signature boxes printed on Purchase Requisitions.
        Route::get('/requisition-signatures', [RequisitionSignatureSettingController::class, 'edit'])->name('requisition-signatures.edit');
        Route::post('/requisition-signatures', [RequisitionSignatureSettingController::class, 'update'])->name('requisition-signatures.update');

        // The signature boxes a new General Stock Comparative Statement starts with.
        Route::get('/cs-signatures', [\App\Http\Controllers\Admin\CsSignatureSettingController::class, 'edit'])->name('cs-signatures.edit');
        Route::post('/cs-signatures', [\App\Http\Controllers\Admin\CsSignatureSettingController::class, 'update'])->name('cs-signatures.update');

        // Departments, their roles and heads, and their sections with heads —
        // what routes a department's requisition and what the user form offers.
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::post('/departments/{department}/sections', [DepartmentController::class, 'storeSection'])->name('departments.sections.store');
        Route::delete('/departments/sections/{section}', [DepartmentController::class, 'detachSection'])->name('departments.sections.detach');

        Route::get('/workspace', [DashboardController::class, 'workspace'])->name('workspace');

        // PRA approver pool management + approval history + notification toggle
        Route::get('/pra-approvers', [PraApproverController::class, 'index'])->name('pra-approvers.index');
        Route::post('/pra-approvers', [PraApproverController::class, 'store'])->name('pra-approvers.store');
        Route::patch('/pra-approvers/{praApprover}', [PraApproverController::class, 'update'])->name('pra-approvers.update');
        Route::patch('/pra-approvers/{praApprover}/checker', [PraApproverController::class, 'toggleChecker'])->name('pra-approvers.checker');
        Route::delete('/pra-approvers/{praApprover}', [PraApproverController::class, 'destroy'])->name('pra-approvers.destroy');
        Route::get('/pra-approvals/history', [PraApproverController::class, 'history'])->name('pra-approvals.history');
    });

/*
 * The two template screens Supply Chain maintains for itself.
 *
 * They keep their admin.* names and /admin URLs — renaming them would break
 * every existing link, breadcrumb and redirect for no gain — but they sit
 * outside the role:admin group so a second, narrower door can open onto them.
 *
 * role_or_perm admits either the admin role or the permission, the pattern
 * routes/store.php already uses. Listing admin explicitly matters: Gate::before
 * makes admin pass every can() check, but Spatie's middleware asks the role and
 * permission tables directly and never consults the gate, so an admin whose
 * role was never granted the permission would otherwise be locked out of a
 * screen they have always owned.
 *
 * What the permission does NOT buy is inside the controllers: is_default on an
 * instruction, and default_to / default_cc on an email template, stay admin-only
 * and are re-checked there on every write.
 */
Route::prefix('admin')
    ->middleware(['auth', 'role_or_perm:admin|booking.instructions.manage'])
    ->name('admin.')
    ->group(function () {
        Route::resource('booking-instructions', BookingInstructionController::class)->except(['show']);
    });

Route::prefix('admin')
    ->middleware(['auth', 'role_or_perm:admin|email.templates.edit'])
    ->name('admin.')
    ->group(function () {
        Route::get('/email-templates', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
        Route::put('/email-templates', [EmailTemplateController::class, 'update'])->name('email-templates.update');
    });
