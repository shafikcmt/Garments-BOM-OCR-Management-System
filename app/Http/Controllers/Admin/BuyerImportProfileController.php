<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Support\StyleHierarchy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin > Buyers > Import Profiles: the column matchings and style rules saved
 * from Import Buyer Excel, listed per buyer so they can be renamed, copied and
 * deleted without importing a file. The matching itself is changed by importing
 * with the new layout and saving it again.
 */
class BuyerImportProfileController extends Controller
{
    public function index(Buyer $buyer)
    {
        $profiles = BuyerImportProfile::with('columns.header')
            ->where('buyer_id', $buyer->id)
            ->orderBy('name')
            ->get();

        return view('admin.buyers.profiles', compact('buyer', 'profiles'));
    }

    public function update(Request $request, Buyer $buyer, BuyerImportProfile $profile)
    {
        $this->own($buyer, $profile);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('buyer_import_profiles', 'name')->where('buyer_id', $buyer->id)->ignore($profile->id)],
            'mother_source' => ['nullable', 'in:auto,' . implode(',', StyleHierarchy::RULES)],
            'fit_words' => ['nullable', 'string', 'max:500'],
        ], ['name.unique' => 'This buyer already has a profile with that name.']);

        $profile->update([
            'name' => trim($data['name']),
            'mother_source' => in_array($data['mother_source'] ?? null, StyleHierarchy::RULES, true) ? $data['mother_source'] : null,
            'fit_words' => trim((string) ($data['fit_words'] ?? '')) ?: null,
        ]);

        return back()->with('success', 'Profile "' . e($profile->name) . '" saved.');
    }

    public function copy(Buyer $buyer, BuyerImportProfile $profile)
    {
        $this->own($buyer, $profile);

        $name = $profile->name . ' (copy)';
        for ($i = 2; BuyerImportProfile::where('buyer_id', $buyer->id)->where('name', $name)->exists(); $i++) {
            $name = $profile->name . ' (copy ' . $i . ')';
        }

        $copy = $profile->replicate(['name'])->fill(['name' => $name]);
        $copy->save();

        foreach ($profile->columns as $column) {
            $copy->columns()->create($column->only(['source_header', 'source_key', 'excel_header_id']));
        }

        return back()->with('success', 'Profile copied as "' . e($name) . '".');
    }

    public function destroy(Buyer $buyer, BuyerImportProfile $profile)
    {
        $this->own($buyer, $profile);

        $name = $profile->name;
        $profile->delete();

        return back()->with('success', 'Profile "' . e($name) . '" deleted. Files already imported are not changed.');
    }

    private function own(Buyer $buyer, BuyerImportProfile $profile): void
    {
        abort_unless($profile->buyer_id === $buyer->id, 404);
    }
}
