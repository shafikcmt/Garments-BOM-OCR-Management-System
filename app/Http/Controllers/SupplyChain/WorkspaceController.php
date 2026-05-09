<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;

class WorkspaceController extends Controller
{
    public function index()
    {
        $files = ExcelFile::with(['uploader', 'rows.cells.header'])
            ->latest()
            ->get();

        return view('supply-chain.workspace', compact('files'));
    }
}