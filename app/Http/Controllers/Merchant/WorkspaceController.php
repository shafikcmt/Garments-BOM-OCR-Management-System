<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use Spatie\Permission\Models\Role;

class WorkspaceController extends Controller
{
    public function index()
    {
        $files = ExcelFile::with(['uploader', 'rows.cells.header'])
            ->latest()
            ->get();

        $merchantRoleId = Role::where('name', 'merchant')->value('id');

        $merchantInputHeaders = $merchantRoleId
            ? ExcelHeader::where('is_active', true)
                ->where('owner_role_id', $merchantRoleId)
                ->where(function ($query) {
                    $query->whereNull('value_mode')
                        ->orWhere('value_mode', 'input');
                })
                ->orderBy('position')
                ->get()
            : collect();

        return view('merchant.workspace', compact('files', 'merchantInputHeaders'));
    }
}
