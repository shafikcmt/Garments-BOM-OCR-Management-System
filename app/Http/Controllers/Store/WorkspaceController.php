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

        return view('store.workspace', compact('files', 'fileSummaries'));
    }
}