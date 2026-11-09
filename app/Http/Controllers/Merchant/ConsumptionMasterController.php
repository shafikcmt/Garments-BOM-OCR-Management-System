<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\ConsumptionMaster;
use App\Models\MotherStyle;
use App\Services\BomMaterialLibrary;
use Illuminate\Http\Request;

/**
 * Consumption master: standard materials per product group, kept on screen.
 * Merchandising and Admin manage a buyer's own rows; rows common to every buyer
 * are Admin's. Management reads. Create BOM reads the master; nothing here
 * writes a BOM.
 */
class ConsumptionMasterController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->orderBy('buyer_name')->get();
        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));
        $term = trim((string) $request->query('q', ''));

        $rows = ConsumptionMaster::query()->with('buyer:id,buyer_name')
            ->where(fn ($q) => $q->whereIn('buyer_id', $buyer ? [$buyer->id] : $buyers->pluck('id'))->orWhereNull('buyer_id'))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('material_description', '%' . $term . '%')
                ->orWhereLike('product_group', '%' . $term . '%')->orWhereLike('sap_code', '%' . $term . '%')))
            ->orderBy('buyer_id')->orderBy('product_group')->orderBy('position')->orderBy('id')
            ->limit(500)->get();

        return view('merchant.consumption-master', [
            'rows' => $rows,
            'buyers' => $buyers,
            'buyer' => $buyer,
            'term' => $term,
            'groups' => MotherStyle::whereNotNull('product_group')->whereIn('buyer_id', $buyers->pluck('id'))->distinct()->orderBy('product_group')->pluck('product_group'),
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']),
            'isAdmin' => $user->hasRole('admin'),
            'styles' => MotherStyle::whereIn('buyer_id', $buyers->pluck('id'))->whereHas('orderLines.bomItems')->with('buyer:id,buyer_name')->orderBy('style_no')->limit(300)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->authorizeBuyer($request, $data['buyer_id'] ?? null);
        $data['position'] = (int) ConsumptionMaster::max('position') + 1;

        ConsumptionMaster::create($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Material added to the consumption master.');
    }

    public function update(Request $request, ConsumptionMaster $row)
    {
        $this->authorizeBuyer($request, $row->buyer_id);
        $data = $this->validated($request);
        // Moving a row to another buyer needs access to that buyer too.
        $this->authorizeBuyer($request, $data['buyer_id'] ?? null);

        $row->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Material updated.');
    }

    public function destroy(Request $request, ConsumptionMaster $row)
    {
        $this->authorizeBuyer($request, $row->buyer_id);
        $row->delete();

        return back()->with('success', 'Material deleted from the consumption master.');
    }

    /** Learn the master from a style that already has a BOM (a quick way to start). */
    public function fromStyle(Request $request, BomMaterialLibrary $library)
    {
        $data = $request->validate(['mother_style_id' => ['required', 'integer', 'exists:mother_styles,id']]);
        $mother = MotherStyle::findOrFail($data['mother_style_id']);
        $this->authorizeBuyer($request, (int) $mother->buyer_id);

        $existing = ConsumptionMaster::where('buyer_id', $mother->buyer_id)
            ->get()->filter(fn ($r) => mb_strtolower(trim((string) $r->product_group)) === mb_strtolower(trim((string) $mother->product_group)))
            ->map(fn ($r) => ConsumptionMaster::materialKey($r->sap_code, $r->material_description))->all();

        $added = 0;
        $position = (int) ConsumptionMaster::max('position');
        foreach ($library->materialsOf($mother) as $m) {
            if ($m['consumption'] === null || in_array(ConsumptionMaster::materialKey($m['sap_code'], $m['description']), $existing, true)) {
                continue;
            }
            ConsumptionMaster::create([
                'buyer_id' => $mother->buyer_id, 'product_group' => $mother->product_group, 'material_type' => $m['type'],
                'material_description' => $m['description'], 'sap_code' => $m['sap_code'], 'material_color' => $m['color'],
                'uom' => $m['uom'], 'consumption' => $m['consumption'], 'wastage_percent' => $m['wastage'],
                'position' => ++$position, 'updated_by' => $request->user()->id,
            ]);
            $added++;
        }

        $note = $mother->product_group ? '' : ' The style has no product group, so these apply to every style of the buyer.';

        return back()->with($added > 0 ? 'success' : 'warning', $added > 0
            ? $added . ' material(s) added from style ' . $mother->style_no . '.' . $note
            : 'Nothing new to add from style ' . $mother->style_no . '.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'buyer_id' => ['nullable', 'integer', 'exists:buyers,id'],
            'product_group' => ['nullable', 'string', 'max:120'],
            'material_type' => ['nullable', 'string', 'max:60'],
            'material_description' => ['required', 'string', 'max:255'],
            'sap_code' => ['nullable', 'string', 'max:60'],
            'material_color' => ['nullable', 'string', 'max:120'],
            'uom' => ['nullable', 'string', 'max:20'],
            'consumption' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], ['material_description.required' => 'Type the material description.', 'consumption.required' => 'Type the consumption.']);

        $data['buyer_id'] = $data['buyer_id'] ?? null;
        $data['product_group'] = trim((string) ($data['product_group'] ?? '')) ?: null;

        return $data;
    }

    /** Merchandising and Admin, for a buyer they hold; rows common to all buyers are Admin's. */
    private function authorizeBuyer(Request $request, $buyerId): void
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['merchant', 'admin']), 403);
        abort_unless($buyerId === null ? $user->hasRole('admin') : $user->hasBuyerAccess((int) $buyerId), 403);
    }
}
