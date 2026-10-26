<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Models\ExcelHeader;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Admin > Buyers > Workspace Columns: which workspace columns a buyer uses.
 * Unticked columns are hidden from that buyer's files on the workspace (one
 * common format inside, each buyer sees its own columns). Display only.
 */
class BuyerColumnController extends Controller
{
    public function edit(Buyer $buyer)
    {
        $headers = ExcelHeader::with('ownerRole')->where('is_active', true)->orderBy('position')->get();
        $hidden = $buyer->hiddenHeaders()->pluck('excel_headers.id')->map(fn ($id) => (int) $id)->all();
        $profile = $this->profile($buyer);

        $groups = $headers->groupBy(fn ($h) => ucfirst(str_replace('_', ' ', $h->ownerRole?->name ?? 'Other')));

        return view('admin.buyers.columns', compact('buyer', 'groups', 'hidden', 'profile'));
    }

    public function update(Request $request, Buyer $buyer)
    {
        $data = $request->validate([
            'shown' => ['nullable', 'array'],
            'shown.*' => ['integer', 'exists:excel_headers,id'],
        ]);

        $shown = collect($data['shown'] ?? [])->map(fn ($id) => (int) $id);
        $hidden = ExcelHeader::where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->diff($shown)->values()->all();
        $buyer->hiddenHeaders()->sync($hidden);

        return redirect()->route('admin.buyers.columns.edit', $buyer)
            ->with('success', count($hidden) === 0 ? 'All columns are shown for ' . $buyer->buyer_name . '.' : count($hidden) . ' column(s) hidden for ' . $buyer->buyer_name . '.');
    }

    /**
     * Start from the buyer's saved column matching (Import Buyer Excel): show
     * the Merchandising columns the buyer's file maps to, hide the other
     * Merchandising input columns. Other departments' columns stay shown.
     */
    public function fromProfile(Buyer $buyer)
    {
        $profile = $this->profile($buyer);
        if (! $profile) {
            return back()->with('warning', 'No column matching is saved for ' . $buyer->buyer_name . '. Import one of the buyer\'s files and tick "Save this matching" first.');
        }

        $mapped = $profile->columns->pluck('excel_header_id')->filter()->map(fn ($id) => (int) $id)->all();
        $merchantRoleId = (int) Role::where('name', 'merchant')->value('id');

        $hidden = ExcelHeader::where('is_active', true)
            ->where('owner_role_id', $merchantRoleId)
            ->where('value_mode', 'input')
            ->whereNotIn('id', $mapped ?: [0])
            ->pluck('id')->all();
        $buyer->hiddenHeaders()->sync($hidden);

        return redirect()->route('admin.buyers.columns.edit', $buyer)
            ->with('success', 'Columns set from ' . $buyer->buyer_name . "'s saved matching: " . count($hidden) . ' Merchandising column(s) hidden. Review and save if you change anything.');
    }

    private function profile(Buyer $buyer): ?BuyerImportProfile
    {
        return BuyerImportProfile::with('columns')->where('buyer_id', $buyer->id)->latest('id')->first();
    }
}
