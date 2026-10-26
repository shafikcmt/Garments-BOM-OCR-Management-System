<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MotherStyle;
use App\Models\OrderSizeQuantity;
use App\Models\SizeSet;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Colour x size breakdown of a style's order: one row per colour / customer
 * PO, one column per size of the style's size set. Sizes are data (size sets
 * per buyer, made on this screen), never coded per buyer.
 *
 * Merchandising and Admin edit; Management reads.
 */
class SizeBreakdownController extends Controller
{
    public function show(Request $request, MotherStyle $mother)
    {
        abort_unless($request->user()->hasBuyerAccess($mother->buyer_id), 403);
        $mother->load(['buyer', 'season', 'sizeSet']);

        $sets = SizeSet::where(fn ($q) => $q->where('buyer_id', $mother->buyer_id)->orWhereNull('buyer_id'))->orderBy('name')->get();
        $set = $mother->sizeSet;
        $rows = $this->rows($mother);
        $sizes = $set?->sizes ?? [];

        // Sizes saved earlier that are no longer in the set are still shown,
        // so nothing entered disappears silently.
        $extraSizes = collect($rows)->flatMap(fn ($r) => array_keys($r['sizes']))->unique()->diff($sizes)->values()->all();

        return view('merchant.size-breakdown', [
            'mother' => $mother,
            'sets' => $sets,
            'set' => $set,
            'sizes' => array_merge($sizes, $extraSizes),
            'rows' => $rows,
            'canEdit' => $request->user()->hasAnyRole(['merchant', 'admin']),
            'backUrl' => $request->user()->hasRole('merchant') ? route('merchant.styles.mothers.show', $mother) : route('merchant.status.style', $mother),
        ]);
    }

    public function update(Request $request, MotherStyle $mother)
    {
        $this->authorizeEdit($request, $mother);

        // Quantities are posted by size position (sizes[]), since a size name
        // such as "2.5" or "S/M" cannot be an input key.
        $data = $request->validate([
            'sizes' => ['array', 'max:60'],
            'sizes.*' => ['string', 'max:40'],
            'rows' => ['array'],
            'rows.*.color' => ['nullable', 'string', 'max:191'],
            'rows.*.po' => ['nullable', 'string', 'max:191'],
            'rows.*.qty' => ['array'],
            'rows.*.qty.*' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], ['rows.*.qty.*.numeric' => 'Size quantities must be numbers.', 'rows.*.qty.*.min' => 'Size quantities cannot be negative.']);

        DB::transaction(function () use ($data, $mother, $request) {
            foreach ($data['rows'] ?? [] as $row) {
                $color = (string) ($row['color'] ?? '');
                $po = (string) ($row['po'] ?? '');
                foreach ($row['qty'] ?? [] as $index => $qty) {
                    $size = $data['sizes'][$index] ?? null;
                    if ($size === null) {
                        continue;
                    }
                    $key = ['mother_style_id' => $mother->id, 'gmts_color' => $color, 'customer_po_no' => $po, 'size' => (string) $size];
                    if ($qty === null || $qty === '' || (float) $qty == 0.0) {
                        OrderSizeQuantity::where($key)->delete();
                    } else {
                        OrderSizeQuantity::updateOrCreate($key, ['qty' => $qty, 'updated_by' => $request->user()->id]);
                    }
                }
            }
        });

        $warnings = collect($this->rows($mother))->filter(fn ($r) => $r['order_qty'] !== null && $r['total'] > 0 && abs($r['total'] - $r['order_qty']) > 0.001)
            ->map(fn ($r) => trim($r['color'] . ' ' . $r['po']) . ': sizes ' . number_format($r['total']) . ' vs order ' . number_format($r['order_qty']));

        $redirect = redirect()->route('merchant.sizes.show', $mother)->with('success', 'Size breakdown saved.');

        return $warnings->isNotEmpty()
            ? $redirect->with('warning', 'Size total differs from the order quantity — ' . $warnings->implode('; '))
            : $redirect;
    }

    /** Choose the style's size set. */
    public function chooseSet(Request $request, MotherStyle $mother)
    {
        $this->authorizeEdit($request, $mother);

        $data = $request->validate([
            'size_set_id' => ['required', Rule::exists('size_sets', 'id')->where(fn ($q) => $q->where('buyer_id', $mother->buyer_id)->orWhereNull('buyer_id'))],
        ]);
        $mother->update(['size_set_id' => $data['size_set_id']]);

        return redirect()->route('merchant.sizes.show', $mother)->with('success', 'Size set chosen.');
    }

    /** Make a new size set for this style's buyer and use it. */
    public function storeSet(Request $request, MotherStyle $mother)
    {
        $this->authorizeEdit($request, $mother);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sizes' => ['required', 'string', 'max:1000'],
        ], [], ['sizes' => 'sizes']);

        $sizes = SizeSet::parseSizes($data['sizes']);
        if ($sizes === [] || count($sizes) > 40) {
            return back()->withInput()->withErrors(['sizes' => 'Type between 1 and 40 sizes, separated by commas.']);
        }

        $set = SizeSet::create(['buyer_id' => $mother->buyer_id, 'name' => $data['name'], 'sizes' => $sizes, 'created_by' => $request->user()->id]);
        $mother->update(['size_set_id' => $set->id]);

        return redirect()->route('merchant.sizes.show', $mother)->with('success', 'Size set "' . $set->name . '" made and chosen.');
    }

    /**
     * One row per colour / customer PO of the style, with its order quantity
     * and the saved size quantities.
     *
     * @return list<array{color: string, po: string, ship_date: mixed, order_qty: ?float, sizes: array<string, float>, total: float}>
     */
    private function rows(MotherStyle $mother): array
    {
        $saved = OrderSizeQuantity::where('mother_style_id', $mother->id)->get()
            ->groupBy(fn ($q) => $this->key($q->gmts_color, $q->customer_po_no));

        $rows = $mother->orderLines()->orderBy('customer_po_no')->orderBy('gmts_color')->get()
            ->groupBy(fn ($l) => $this->key($l->gmts_color, $l->customer_po_no))
            ->map(function (Collection $lines, string $key) use ($saved) {
                $first = $lines->first();
                $sizes = ($saved->get($key) ?? collect())->mapWithKeys(fn ($q) => [$q->size => (float) $q->qty])->all();

                return [
                    'color' => (string) $first->gmts_color,
                    'po' => (string) $first->customer_po_no,
                    'ship_date' => $lines->pluck('ship_date')->filter()->min(),
                    // The same colour / PO in two files is one order, not two.
                    'order_qty' => $lines->pluck('order_qty')->filter()->isNotEmpty() ? (float) $lines->max('order_qty') : null,
                    'sizes' => $sizes,
                    'total' => (float) array_sum($sizes),
                ];
            });

        return $rows->values()->all();
    }

    private function key(?string $color, ?string $po): string
    {
        return mb_strtolower(trim((string) $color)) . '|' . mb_strtolower(trim((string) $po));
    }

    private function authorizeEdit(Request $request, MotherStyle $mother): void
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess($mother->buyer_id), 403);
    }
}
