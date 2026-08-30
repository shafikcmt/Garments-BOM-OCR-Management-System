<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    use \App\Support\Concerns\ScopesToAssignedBuyers;

    public function index()
    {
        /*
         * Scoped to the buyers this user holds — see Merchant\WorkspaceController
         * for the full reasoning; this closes the same gap on Store's copy of
         * the same screen.
         *
         * THE ROLE CARVE-OUT STILL HOLDS, and it is handled inside the trait
         * rather than here: 'store' is in BuyerScope::SCOPED_DEPARTMENTS, but
         * store_general_stock is in UNSCOPED_ROLES because General Stock data
         * carries no buyer column at all. A General Stock user reaching this
         * screen answers false to isBuyerConfined() and is never narrowed.
         * This screen is Buyer/Style BOM browsing, which is the scoped half of
         * the department.
         *
         * Untagged files (buyer_id null) stay visible, and an unconfined viewer
         * is never narrowed — both live in the trait, not here.
         */
        $files = ExcelFile::with(['uploader'])
            ->tap(fn ($query) => $this->applyBuyerScopeToFiles($query))
            ->latest()
            ->get();

        $fileSummaries = app(\App\Services\ExcelFileSummaryService::class)->for($files);

        /*
         * Store's own share of the BOM columns.
         *
         * This sat on the Store dashboard, where it was the only BOM figure on
         * a screen otherwise about stock quantities, and was shown to people
         * who could not open a BOM at all. It measures work done in this
         * workspace, so it belongs on the workspace — and lands behind
         * store.workspace.view for free, which is the permission that decides
         * who does that work.
         *
         * Same service the Admin Dashboard reads, scoped to this department.
         */
        $activity = app(\App\Services\DepartmentActivityService::class);
        $workspace = $activity->forRole('store') ?? $activity->emptyProgressFor('store');

        return view('store.workspace', compact('files', 'fileSummaries', 'workspace'));
    }
}