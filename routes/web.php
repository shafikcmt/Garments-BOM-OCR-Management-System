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
    /*
     * Where a signed-in user lands.
     *
     * This used to test one role name at a time and abort when none matched,
     * which meant every role added after it was written had no landing page:
     * a user on Store — General Stock was refused at /dashboard the moment they
     * signed in, even though every screen behind it would have let them in.
     *
     * It now resolves the user's DEPARTMENT and sends them to that department's
     * dashboard, so a role added later lands correctly as soon as it is mapped —
     * which is the same map the user form already offers roles from.
     */
    Route::get('/dashboard', function () {
        $user = auth()->user();

        $dashboards = [
            'management' => 'admin.dashboard',
            'merchandising' => 'merchant.dashboard',
            'accounts' => 'account.dashboard',
            'commercial' => 'commercial.dashboard',
            'store' => 'store.dashboard',
            'supply_chain' => 'supply_chain.dashboard',
        ];

        // Admin and management share the Management / Admin department but not
        // the same screen, so that one pair is still decided by role.
        if ($user->hasRole('management')) {
            return redirect()->route('management.dashboard');
        }

        $department = \App\Support\DepartmentRoles::departmentOf($user->getRoleNames()->first());

        /*
         * Store now has a dashboard per module, so the department alone no
         * longer says where to land. General Stock first, because it is the
         * broader of the two and the one most Store users work in; Buyer /
         * Style only if they hold nothing in General Stock.
         *
         * Asked as permissions, not roles, so a user granted one module
         * screen at a time lands somewhere useful without anybody remembering
         * to update a list here.
         */
        if ($department === 'store') {
            $generalStock = ['store.stock_report.view', 'store.items.view', 'store.receiving.view',
                'store.issues.view', 'store.requisition.view', 'store.setup.view'];

            $materialStock = ['material.closing_stock.view', 'material.receiving.view',
                'material.bulk_issue.view', 'material.requisitions.view'];

            if ($user->hasRole('store') || $user->canAny($generalStock)) {
                return redirect()->route('store.dashboard');
            }

            if ($user->canAny($materialStock)) {
                return redirect()->route('store.material.dashboard');
            }

            /*
             * A Store user holding neither module. Reachable today by revoking
             * a narrow role's permissions, and it used to be a bare 403 from
             * whichever dashboard they were sent to — a dead end that did not
             * say what was wrong or who could fix it. Their profile is a page
             * every signed-in user can open, so it is somewhere to land while
             * being told what is missing.
             */
            return redirect()->route('profile.edit')->with(
                'warning',
                'You do not have access to any Store module yet. Ask your administrator to grant it.'
            );
        }

        if ($department && isset($dashboards[$department])) {
            return redirect()->route($dashboards[$department]);
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