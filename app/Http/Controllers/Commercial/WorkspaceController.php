<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    public function index()
    {
        $files = ExcelFile::with(['uploader', 'rows.cells.header'])
            ->latest()
            ->get();

        return view('commercial.workspace', compact('files'));
    }
}