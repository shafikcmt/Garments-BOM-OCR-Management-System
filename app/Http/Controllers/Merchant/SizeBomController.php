<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MotherStyle;
use App\Models\OrderSizeQuantity;
use App\Models\StyleMaterialSize;
use App\Services\SizeWiseBom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Size-wise BOM: consumption of each material per size, and the requirement it
 * gives against the workspace's single-consumption figure. Merchandising and
 * Admin edit, Management reads. Workspace figures are not changed.
 */
class SizeBomController extends Controller
{
    public function show(Request $request, MotherStyle $mother, SizeWiseBom $bom)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
        $mother->load(['buyer', 'season', 'sizeSet']);

        $saved = OrderSizeQuantity::where('mother_style_id', $mother->id)->pluck('size')->unique()->values()->all();
        $setSizes = $mother->sizeSet?->sizes ?? [];
        $sizes = array_values(array_unique(array_merge($setSizes, $saved)));

        return view('merchant.size-bom', [
            'mother' => $mother,
            'sizes' => $sizes,
            'materials' => $bom->materials($mother),
            'hasBreakdown' => $saved !== [],
            'canEdit' => $request->user()->hasAnyRole(['merchant', 'admin']),
            'backUrl' => $request->user()->hasRole('merchant') ? route('merchant.styles.mothers.show', $mother) : route('merchant.status.style', $mother),
        ]);
    }

    public function update(Request $request, MotherStyle $mother)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);

        // Posted by size position (sizes[]), since a size name such as "S/M" cannot be an input key.
        $data = $request->validate([
            'sizes' => ['array', 'max:60'],
            'sizes.*' => ['string', 'max:40'],
            'rows' => ['array', 'max:500'],
            'rows.*.key' => ['required', 'string', 'max:191'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.cons' => ['array'],
            'rows.*.cons.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ], ['rows.*.cons.*.numeric' => 'Consumption must be a number.', 'rows.*.cons.*.min' => 'Consumption cannot be negative.']);

        DB::transaction(function () use ($data, $mother, $request) {
            foreach ($data['rows'] ?? [] as $row) {
                foreach ($row['cons'] ?? [] as $index => $value) {
                    $size = $data['sizes'][$index] ?? null;
                    if ($size === null) {
                        continue;
                    }
                    $key = ['mother_style_id' => $mother->id, 'material_key' => $row['key'], 'size' => (string) $size];
                    if ($value === null || $value === '') {
                        StyleMaterialSize::where($key)->delete();
                    } else {
                        StyleMaterialSize::updateOrCreate($key, ['consumption' => $value, 'material_name' => $row['name'] ?? null, 'updated_by' => $request->user()->id]);
                    }
                }
            }
        });

        return redirect()->route('merchant.sizebom.show', $mother)->with('success', 'Size-wise consumption saved.');
    }
}
