<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    use \App\Support\Concerns\ScopesToAssignedBuyers;

    public function index()
    {
        /*
         * Scoped to the buyers this user holds — see Merchant\WorkspaceController
         * for the full reasoning; this closes the same gap on Supply Chain's
         * copy of the same screen.
         *
         * NOT A NEW RESTRICTION ON THIS DEPARTMENT. 'supply_chain' has been in
         * BuyerScope::SCOPED_DEPARTMENTS since its report started reading
         * through the scope, and its PO Generate, PRA, dashboard counts and
         * Sent Emails lists are all filtered already. This file list was simply
         * the one screen the decision was never applied to. Supply Chain's
         * cross-buyer sourcing work runs through the vendor and PO screens,
         * which are scoped on their own terms; this is BOM browsing.
         *
         * Untagged files (buyer_id null) stay visible, and an unconfined viewer
         * is never narrowed — both live in the trait, not here.
         */
        $files = ExcelFile::with(['uploader'])
            ->tap(fn ($query) => $this->applyBuyerScopeToFiles($query))
            ->latest()
            ->get();

        $fileSummaries = app(\App\Services\ExcelFileSummaryService::class)->for($files);

        return view('supply-chain.workspace', compact('files', 'fileSummaries'));
    }
}