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

        // Buyer scoping, Merchandising only. Three states, and the upload
        // panel renders differently for each:
        //
        //   none      unscoped — the full dropdown of active buyers, which is
        //             what every merchant sees today.
        //   one       a read-only chip. Nothing to choose, so nothing is asked;
        //             ExcelUploadRequest fills the field in server-side.
        //   several   a dropdown limited to THEIRS. The request rejects
        //             anything outside the same set, so the narrowed list is a
        //             convenience and not the control.
        $user = auth()->user();
        $scopedIds = $user?->merchantBuyerIds() ?? [];

        // Inactive buyers are excluded from the general list but kept when one
        // is assigned: a merchant scoped to a buyer since deactivated must
        // still be able to upload for it.
        $scopedBuyers = $scopedIds === []
            ? collect()
            : Buyer::whereIn('id', $scopedIds)->orderBy('buyer_name')->get();

        $scopedBuyer = $scopedBuyers->count() === 1 ? $scopedBuyers->first() : null;

        $canUpload = $user?->mayUploadBom() ?? false;

        return view('merchant.workspace', compact(
            'files', 'merchantInputHeaders', 'fileSummaries', 'buyers',
            'scopedBuyer', 'scopedBuyers', 'canUpload'
        ));
    }
}
