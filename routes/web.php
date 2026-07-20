<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Shared\ExcelFileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\PraApprovalController;
use App\Http\Controllers\StyleBudgetController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();

        if ($user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        if ($user->hasRole('merchant')) {
            return redirect()->route('merchant.dashboard');
        }

        if ($user->hasRole('account')) {
            return redirect()->route('account.dashboard');
        }

        if ($user->hasRole('commercial')) {
            return redirect()->route('commercial.dashboard');
        }

        if ($user->hasRole('store')) {
            return redirect()->route('store.dashboard');
        }

        if ($user->hasRole('supply_chain')) {
            return redirect()->route('supply_chain.dashboard');
        }

        if ($user->hasRole('management')) {
            return redirect()->route('management.dashboard');
        }

        abort(403, 'No dashboard route assigned for this role.');
    })->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/signature', [ProfileController::class, 'updateSignature'])->name('profile.signature.update');
    Route::delete('/profile/signature', [ProfileController::class, 'destroySignature'])->name('profile.signature.destroy');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/uploaded-files/{excelFile}', [ExcelFileController::class, 'show'])->name('uploaded-files.show');
    Route::put('/uploaded-files/{excelFile}', [ExcelFileController::class, 'update'])->name('uploaded-files.update');
    Route::post('/uploaded-files/{excelFile}/rows', [ExcelFileController::class, 'addRow'])->name('uploaded-files.rows.store');
    Route::get('/uploaded-files/{excelFile}/download', [ExcelFileController::class, 'download'])->name('uploaded-files.download');
    Route::delete('/uploaded-files/{excelFile}', [ExcelFileController::class, 'destroy'])->name('uploaded-files.destroy');
    Route::patch('/uploaded-files/{excelFile}/lock', [ExcelFileController::class, 'updateLock'])->name('uploaded-files.lock');
     Route::get('/notifications/{notification}', [NotificationController::class, 'open'])
        ->name('notifications.open');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::delete('/emails/{emailLog}', [EmailLogController::class, 'destroy'])->name('emails.destroy');

    // PRA digital approval — approver-facing screens (any role, gated by the
    // approve-pra permission granted through the admin approver pool).
    Route::middleware('can:approve-pra')->group(function () {
        Route::get('/pra-approvals', [PraApprovalController::class, 'index'])->name('pra_approvals.index');
        Route::get('/pra-approvals/{paymentRequest}', [PraApprovalController::class, 'show'])->name('pra_approvals.show');
        Route::post('/pra-approvals/{paymentRequest}/approve', [PraApprovalController::class, 'approve'])->name('pra_approvals.approve');
        Route::post('/pra-approvals/{paymentRequest}/reject', [PraApprovalController::class, 'reject'])->name('pra_approvals.reject');
    });

    // Style-wise budgets — set/edit by admin + merchandising (manage-style-budgets).
    Route::middleware('can:manage-style-budgets')->group(function () {
        Route::get('/style-budgets', [StyleBudgetController::class, 'index'])->name('style-budgets.index');
        Route::post('/style-budgets', [StyleBudgetController::class, 'store'])->name('style-budgets.store');
        Route::patch('/style-budgets/{styleBudget}', [StyleBudgetController::class, 'update'])->name('style-budgets.update');
        Route::delete('/style-budgets/{styleBudget}', [StyleBudgetController::class, 'destroy'])->name('style-budgets.destroy');
    });
});




require __DIR__.'/admin.php';
require __DIR__.'/merchant.php';
require __DIR__.'/account.php';
require __DIR__.'/commercial.php';
require __DIR__.'/store.php';
require __DIR__.'/supply-chain.php';
require __DIR__.'/management.php';
require __DIR__.'/auth.php';