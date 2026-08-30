<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    use \App\Support\Concerns\ScopesToAssignedBuyers;

    public function index()
    {
        /*
         * Scoped to the buyers this user holds — see Merchant\WorkspaceController
         * for the full reasoning; this closes the same gap on Commercial's copy
         * of the same screen.
         *
         * 'commercial' is already in BuyerScope::SCOPED_DEPARTMENTS and its
         * dashboard and report both read through the scope. This list was the
         * remaining unscoped screen in the department.
         *
         * Untagged files (buyer_id null) stay visible, and an unconfined viewer
         * is never narrowed — both live in the trait, not here.
         */
        $files = ExcelFile::with(['uploader'])
            ->tap(fn ($query) => $this->applyBuyerScopeToFiles($query))
            ->latest()
            ->get();

        $fileSummaries = app(\App\Services\ExcelFileSummaryService::class)->for($files);

        return view('commercial.workspace', compact('files', 'fileSummaries'));
    }
}