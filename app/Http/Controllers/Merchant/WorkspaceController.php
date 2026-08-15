<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use Spatie\Permission\Models\Role;

class WorkspaceController extends Controller
{
    public function index()
    {
        $files = ExcelFile::with(['uploader'])
            ->latest()
            ->get();

        $fileSummaries = app(\App\Services\ExcelFileSummaryService::class)->for($files);

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

        // Only active buyers are offered on upload. Inactive ones stay valid on
        // files already tagged with them.
        $buyers = Buyer::active()->orderBy('buyer_name')->get();

        // Buyer scoping, Merchandising only. All three are null/true for an
        // unscoped merchant, which is every merchant that exists today, so the
        // upload panel renders exactly as it does now for them.
        $user = auth()->user();
        $scopedBuyer = $user?->merchantBuyerId()
            ? $buyers->firstWhere('id', $user->merchantBuyerId()) ?? Buyer::find($user->merchantBuyerId())
            : null;
        $canUpload = $user?->mayUploadBom() ?? false;

        return view('merchant.workspace', compact(
            'files', 'merchantInputHeaders', 'fileSummaries', 'buyers', 'scopedBuyer', 'canUpload'
        ));
    }
}
