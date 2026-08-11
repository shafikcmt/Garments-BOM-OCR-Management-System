<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    public function index()
    {
        $files = ExcelFile::with(['uploader'])
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