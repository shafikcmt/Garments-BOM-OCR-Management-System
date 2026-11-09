<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Services\BomBuilder;
use App\Services\BomMaterialLibrary;
use Illuminate\Http\Request;

/**
 * Make a style's BOM on a screen: the order lines (color, PO, ship date, garment
 * quantity) and the materials (with consumption and wastage). Every order line
 * gets every material. The rules, and the writing into the workspace, are
 * BomBuilder's; this class checks the buyer, the role and the input.
 */
class BomBuilderController extends Controller
{
    public function create(Request $request, MotherStyle $mother, BomBuilder $builder, BomMaterialLibrary $library)
    {
        $this->authorizeBuild($request, $mother);

        $mother->load(['buyer', 'season', 'children']);

        // Start from the order lines the style already has, so more materials can
        // be added without typing them again.
        $lines = OrderLine::where('mother_style_id', $mother->id)->with('childStyle')->orderBy('customer_po_no')->orderBy('gmts_color')->get()
            ->map(fn (OrderLine $l) => [
                'color' => $l->gmts_color, 'po' => $l->customer_po_no, 'ship_date' => $l->ship_date?->toDateString(),
                'qty' => $l->order_qty !== null ? (float) $l->order_qty : '', 'child' => $l->child_style_id,
            ])->all();

        // Copy the materials of an earlier BOM of the SAME buyer into the form.
        $copied = null;
        $copyNote = null;

        if ($request->filled('from')) {
            $source = MotherStyle::where('buyer_id', $mother->buyer_id)->find($request->integer('from'));
            $lineId = $request->integer('line') ?: null;

            if ($source && $lineId && ! OrderLine::where('id', $lineId)->where('mother_style_id', $source->id)->exists()) {
                $lineId = null;
            }

            $rows = $source ? $library->materialsOf($source, $lineId) : [];

            if ($rows === []) {
                $copyNote = $source ? 'Style ' . $source->style_no . ' has no materials to copy.' : 'That style is not available to copy from.';
            } else {
                $copied = ['rows' => $rows, 'from' => $source->style_no];
            }
        }

        // Fill the materials from the Consumption Master (product group of this style).
        $fromMaster = null;
        $masterRows = \App\Models\ConsumptionMaster::rowsForStyle($mother);
        if ($request->boolean('master') && ! $copied) {
            $fromMaster = $masterRows === [] ? ['rows' => [], 'note' => 'The Consumption Master has no materials for this style yet.'] : ['rows' => $masterRows, 'note' => null];
        }

        // Styles (and their order lines) of this buyer that have a BOM, to copy from.
        $sources = MotherStyle::where('buyer_id', $mother->buyer_id)
            ->whereHas('orderLines.bomItems')
            ->with(['season:id,code', 'orderLines' => fn ($q) => $q->withCount('bomItems')->orderBy('customer_po_no')->orderBy('gmts_color')])
            ->orderBy('style_no')->limit(200)->get();

        return view('merchant.bom-builder', [
            'mother' => $mother,
            'sources' => $sources,
            'copied' => $copied,
            'copyNote' => $copyNote,
            'copyFrom' => $request->integer('from') ?: null,
            'copyLine' => $request->integer('line') ?: null,
            'prefill' => old('lines', $lines ?: [['color' => '', 'po' => '', 'ship_date' => '', 'qty' => '', 'child' => '']]),
            'masterCount' => count($masterRows),
            'fromMaster' => $fromMaster,
            'materials' => $copied ? $copied['rows'] : ($fromMaster && $fromMaster['rows'] ? $fromMaster['rows'] : old('materials', [['type' => '', 'description' => '', 'sap_code' => '', 'color' => '', 'uom' => '', 'consumption' => '', 'wastage' => '']])),
            'columns' => $builder->fieldColumns($request->user()),
            'limits' => ['lines' => BomBuilder::MAX_LINES, 'materials' => BomBuilder::MAX_MATERIALS],
        ]);
    }

    /** Past materials of this buyer that match what is being typed (JSON, for the form). */
    public function suggest(Request $request, MotherStyle $mother, BomMaterialLibrary $library)
    {
        $this->authorizeBuild($request, $mother);

        return response()->json($library->suggest($mother, (string) $request->query('q', '')));
    }

    public function store(Request $request, MotherStyle $mother, BomBuilder $builder)
    {
        $this->authorizeBuild($request, $mother);

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:' . BomBuilder::MAX_LINES],
            'lines.*.color' => ['nullable', 'string', 'max:120'],
            'lines.*.po' => ['nullable', 'string', 'max:80'],
            'lines.*.ship_date' => ['nullable', 'date'],
            'lines.*.qty' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'lines.*.child' => ['nullable', 'integer'],
            'materials' => ['required', 'array', 'min:1', 'max:' . BomBuilder::MAX_MATERIALS],
            'materials.*.type' => ['nullable', 'string', 'max:60'],
            'materials.*.description' => ['required', 'string', 'max:255'],
            'materials.*.sap_code' => ['nullable', 'string', 'max:60'],
            'materials.*.color' => ['nullable', 'string', 'max:120'],
            'materials.*.uom' => ['nullable', 'string', 'max:20'],
            'materials.*.consumption' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'materials.*.wastage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'lines.*.qty.required' => 'Every order line needs the garment quantity.',
            'lines.*.qty.min' => 'The garment quantity must be at least 1.',
            'materials.*.description.required' => 'Every material needs a description.',
            'materials.*.consumption.required' => 'Every material needs a consumption.',
        ]);

        // A child style must belong to THIS style.
        $children = $mother->children()->pluck('style_no', 'id');
        $lines = [];

        foreach ($data['lines'] as $line) {
            $childId = $line['child'] ?? null;

            if ($childId && ! $children->has((int) $childId)) {
                return back()->withInput()->with('warning', 'One of the child styles does not belong to this style.');
            }

            $lines[] = ['child' => $childId ? $children[(int) $childId] : null] + $line;
        }

        try {
            $result = $builder->create($mother, $lines, $data['materials'], $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->with('warning', $e->getMessage());
        }

        $route = $request->user()->hasRole('merchant') ? 'merchant.styles.mothers.show' : 'merchant.status.style';
        $message = $result['created'] . ' BOM line(s) created'
            . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped because they already exist' : '') . '.';

        $back = redirect()->route($route, $mother)->with($result['created'] > 0 ? 'success' : 'warning', $result['created'] > 0 ? $message : 'Nothing was added: ' . $message);

        return $result['not_saved'] !== []
            ? $back->with('info', 'Not filled (your role cannot edit these workspace columns): ' . implode(', ', $result['not_saved']) . '.')
            : $back;
    }

    private function authorizeBuild(Request $request, MotherStyle $mother): void
    {
        $user = $request->user();

        abort_unless($user->hasAnyRole(['merchant', 'admin']) && $user->hasBuyerAccess((int) $mother->buyer_id), 403);
    }
}
