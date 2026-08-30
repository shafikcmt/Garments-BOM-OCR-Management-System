<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    use \App\Support\Concerns\ScopesToAssignedBuyers;

    public function index()
    {
        /*
         * Scoped to the buyers this user holds — see Merchant\WorkspaceController
         * for the full reasoning; this closes the same gap on the Accounts copy
         * of the same screen.
         *
         * The department key is 'accounts', not 'account' — the singular is the
         * ROLE name. It is already in BuyerScope::SCOPED_DEPARTMENTS and the
         * Accounts dashboard reads through the scope; this list did not.
         *
         * Untagged files (buyer_id null) stay visible, and an unconfined viewer
         * is never narrowed — both live in the trait, not here.
         */
        $files = ExcelFile::with(['uploader'])
            ->tap(fn ($query) => $this->applyBuyerScopeToFiles($query))
            ->latest()
            ->get();

        $fileSummaries = app(\App\Services\ExcelFileSummaryService::class)->for($files);

        return view('account.workspace', compact('files', 'fileSummaries'));
    }
}