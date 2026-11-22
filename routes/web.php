<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Shared\ExcelFileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\PraApprovalController;
use App\Http\Controllers\PraDepartmentalApprovalController;
use App\Http\Controllers\StyleBudgetController;

// The root is not a page of its own: a signed-in user goes to their
// dashboard, anyone else to the ONE login screen (auth/login.blade.php).
// It used to render a second copy of the login form, which drifted.
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

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

        // The General Stock purchasing desk (Supply Chain General). Asked as a
        // permission so the live role name does not matter. Roles that have a
        // dashboard of their own keep it: admin is excluded because Gate::before
        // answers yes to every permission for them.
        if (! $user->hasAnyRole(['admin', 'supply_chain', 'store', 'merchant', 'account', 'commercial'])
            && $user->can('store.purchase_cs.view')) {
            return redirect()->route('store.purchasing.queue');
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
            // store.requisition.view is deliberately NOT in this list: the
            // Store dashboard's guard no longer admits it on its own (see
            // routes/store.php), so a Store-department user holding only the
            // requisition screen is sent to that screen below, not to a 403.
            $generalStock = ['store.stock_report.view', 'store.items.view', 'store.receiving.view',
                'store.issues.view', 'store.setup.view'];

            $materialStock = ['material.closing_stock.view', 'material.receiving.view',
                'material.bulk_issue.view', 'material.requisitions.view'];

            if ($user->hasRole('store') || $user->canAny($generalStock)) {
                return redirect()->route('store.dashboard');
            }

            if ($user->canAny($materialStock)) {
                return redirect()->route('store.material.dashboard');
            }

            if ($user->can('store.requisition.view')) {
                return redirect()->route('store.stock.requisitions.index');
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

        // A role mapped to no department — the Requisition Raiser, or any
        // narrow role an admin makes later — has no dashboard of its own.
        // Whoever may open the Purchase Requisition list lands on it; that is
        // the one screen such a role exists for. Asked as a permission so a
        // future narrow role lands here too without being listed.
        if ($user->can('store.requisition.view')) {
            return redirect()->route('store.stock.requisitions.index');
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
    // Duplicate one or many existing rows. Same guards and same persistence
    // behaviour as rows.store above — see duplicateRows().
    Route::post('/uploaded-files/{excelFile}/rows/duplicate', [ExcelFileController::class, 'duplicateRows'])->name('uploaded-files.rows.duplicate');
    // Duplicate a whole style group as a block. Takes the three style FILTER
    // VALUES, not row ids — the server resolves the rows itself, so a stale or
    // tampered page cannot name rows outside the group it is looking at. See
    // duplicateMatchingRows().
    Route::post('/uploaded-files/{excelFile}/rows/duplicate-matching', [ExcelFileController::class, 'duplicateMatchingRows'])->name('uploaded-files.rows.duplicate_matching');
    // The Add New Order dialog: a JSON picker for the source row, then one
    // write that goes through the same gate and the same copy routine as every
    // other duplication. See orderSourceOptions() / storeDuplicatedOrder().
    Route::get('/uploaded-files/{excelFile}/rows/order-sources', [ExcelFileController::class, 'orderSourceOptions'])->name('uploaded-files.rows.order_sources');
    Route::post('/uploaded-files/{excelFile}/rows/add-order', [ExcelFileController::class, 'storeDuplicatedOrder'])->name('uploaded-files.rows.add_order');
    // Clears the "Duplicated" badge on one row; the lineage is kept.
    Route::patch('/uploaded-files/{excelFile}/rows/{row}/dismiss-duplicate', [ExcelFileController::class, 'dismissDuplicateFlag'])->name('uploaded-files.rows.dismiss_duplicate');
    // Undo a row somebody has just added. NARROW: refused for any row that
    // something downstream depends on, or that has been edited since it was
    // created. ExcelRow::deletionBlockers() decides, and is the same method the
    // worksheet asks before offering the button. See deleteRow().
    Route::delete('/uploaded-files/{excelFile}/rows/{row}', [ExcelFileController::class, 'deleteRow'])->name('uploaded-files.rows.destroy');
    // The same, for a checkbox selection. Deletes the eligible rows and names
    // the rest with their reasons — a selection made for Duplicate Selected has
    // no reason to be all-eligible. Same batched check, no second rule.
    Route::post('/uploaded-files/{excelFile}/rows/delete-selected', [ExcelFileController::class, 'deleteRows'])->name('uploaded-files.rows.destroy_many');
    Route::get('/uploaded-files/{excelFile}/download', [ExcelFileController::class, 'download'])->name('uploaded-files.download');
    Route::delete('/uploaded-files/{excelFile}', [ExcelFileController::class, 'destroy'])->name('uploaded-files.destroy');
    Route::patch('/uploaded-files/{excelFile}/lock', [ExcelFileController::class, 'updateLock'])->name('uploaded-files.lock');
     Route::get('/notifications/{notification}', [NotificationController::class, 'open'])
        ->name('notifications.open');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::delete('/emails/{emailLog}', [EmailLogController::class, 'destroy'])->name('emails.destroy');

    // Supporting documents attached to a PRA. Authorised per record in the controller.
    Route::get('/pra-documents/{paymentRequest}/{index}', [\App\Http\Controllers\PraDocumentController::class, 'download'])
        ->whereNumber('index')->name('pra_documents.download');
    Route::get('/pra-documents/{paymentRequest}/{index}/view', [\App\Http\Controllers\PraDocumentController::class, 'view'])
        ->whereNumber('index')->name('pra_documents.view');

    // PRA digital approval — approver-facing screens (any role, gated by the
    // approve-pra permission granted through the admin approver pool).
    Route::middleware('can:approve-pra')->group(function () {
        Route::get('/pra-approvals', [PraApprovalController::class, 'index'])->name('pra_approvals.index');
        Route::get('/pra-approvals/{paymentRequest}', [PraApprovalController::class, 'show'])->name('pra_approvals.show');
        Route::post('/pra-approvals/{paymentRequest}/approve', [PraApprovalController::class, 'approve'])->name('pra_approvals.approve');
        Route::post('/pra-approvals/{paymentRequest}/reject', [PraApprovalController::class, 'reject'])->name('pra_approvals.reject');
    });

    // PRA departmental chain — the 6-step Merchant/Accounts/Commercial flow.
    // A SEPARATE PERMISSION from approve-pra above, on purpose: these roles must
    // reach flow-2 PRAs and must NOT reach the legacy hand-routed ones. The
    // permission is the outer gate only; PraStageResolver decides per record.
    // The read-only "All PRAs" list. role_or_perm rather than can:, so a super
    // admin reaches their oversight view even on a database where the
    // departmental permission was never granted to the admin role. Declared
    // BEFORE the {paymentRequest} routes so "all" is never read as an id.
    // The detail page sits behind the same gate, so every row the list shows
    // can be opened; the controller's own participation rule decides per record.
    Route::middleware('role_or_perm:admin|pra.departmental.act')->group(function () {
        Route::get('/pra-departmental/all', [PraDepartmentalApprovalController::class, 'all'])->name('pra_departmental.all');
        Route::get('/pra-departmental/{paymentRequest}', [PraDepartmentalApprovalController::class, 'show'])->name('pra_departmental.show');
    });

    Route::middleware('can:pra.departmental.act')->group(function () {
        Route::get('/pra-departmental', [PraDepartmentalApprovalController::class, 'index'])->name('pra_departmental.index');
        Route::post('/pra-departmental/{paymentRequest}/approve', [PraDepartmentalApprovalController::class, 'approve'])->name('pra_departmental.approve');
        Route::post('/pra-departmental/{paymentRequest}/reject', [PraDepartmentalApprovalController::class, 'reject'])->name('pra_departmental.reject');
    });

    // Style-wise budgets — set/edit by admin + merchandising (manage-style-budgets).
    Route::middleware('can:manage-style-budgets')->group(function () {
        Route::get('/style-budgets', [StyleBudgetController::class, 'index'])->name('style-budgets.index');
        Route::post('/style-budgets', [StyleBudgetController::class, 'store'])->name('style-budgets.store');
        Route::patch('/style-budgets/{styleBudget}', [StyleBudgetController::class, 'update'])->name('style-budgets.update');
        Route::delete('/style-budgets/{styleBudget}', [StyleBudgetController::class, 'destroy'])->name('style-budgets.destroy');
    });
});




require __DIR__.'/vendor-master.php';
require __DIR__.'/admin.php';
require __DIR__.'/merchant.php';
require __DIR__.'/account.php';
require __DIR__.'/commercial.php';
require __DIR__.'/store.php';
require __DIR__.'/supply-chain.php';
require __DIR__.'/management.php';
require __DIR__.'/follow-ups.php';
require __DIR__.'/bom-approvals.php';
require __DIR__.'/costing.php';
require __DIR__.'/auth.php';
