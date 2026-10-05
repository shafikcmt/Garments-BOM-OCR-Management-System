<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Services\StockItemSku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin > Settings > Item SKU (owner, 2026-09-29).
 *
 * The SKU format (tokens, live preview), the 2-letter category codes with a
 * warning on every clash, the dry-run preview of the SKUs missing items
 * would get, the real "Generate missing SKUs", and the Admin correction of
 * one SKU with a reason. Admin only (the role:admin route group).
 */
class ItemSkuSettingController extends Controller
{
    public function edit()
    {
        $itemCounts = StockItem::query()->whereNotNull('item_category_id')
            ->select('item_category_id', DB::raw('COUNT(*) as n'))->groupBy('item_category_id')
            ->pluck('n', 'item_category_id');

        // Active categories, and any other that still holds items.
        $categories = ItemCategory::withTrashed()->orderBy('name')->get()
            ->filter(fn ($c) => ($c->is_active && ! $c->trashed()) || ($itemCounts[$c->id] ?? 0) > 0)
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'effective' => StockItemSku::categoryCode($c),
                'items' => (int) ($itemCounts[$c->id] ?? 0),
            ])->values();

        // A code two or more categories use. Sharing is allowed on purpose
        // (Needle + Machine Needles); the warning makes sure it IS on purpose.
        // A category with no code and no items (a ZZ… placeholder) gives no
        // SKU, so it clashes with nothing.
        $clashes = $categories->filter(fn ($c) => $c['code'] || $c['items'] > 0)
            ->groupBy('effective')->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->pluck('name')->all());

        $samples = StockItem::query()->with('itemCategory')->orderBy('id')->limit(5)->get()
            ->map(fn ($i) => [
                'name' => $i->name,
                'brand' => (string) $i->brand,
                'code' => StockItemSku::categoryCode($i->itemCategory),
            ]);

        return view('admin.item-sku.edit', [
            'pattern' => StockItemSku::pattern(),
            'defaultPattern' => StockItemSku::DEFAULT_PATTERN,
            'categories' => $categories,
            'clashes' => $clashes,
            'samples' => $samples,
            'missing' => StockItem::query()->whereNull('sku')->count(),
            'withSku' => StockItem::query()->whereNotNull('sku')->count(),
            'noCategoryItems' => StockItem::query()->whereNull('item_category_id')->count(),
            'changes' => DB::table('stock_item_sku_changes')->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function updatePattern(Request $request)
    {
        $data = $request->validate(['pattern' => ['required', 'string', 'max:120']]);

        if ($problem = StockItemSku::patternProblem($data['pattern'])) {
            return back()->withInput()->withErrors(['pattern' => $problem]);
        }

        AppSetting::put(StockItemSku::SETTING_PATTERN, trim($data['pattern']));

        return back()->with('success', 'SKU format saved. It applies to new items only; existing SKUs never change.');
    }

    public function updateCodes(Request $request)
    {
        $data = $request->validate([
            'codes' => ['array'],
            'codes.*' => ['nullable', 'string', 'regex:/^[A-Za-z]{1,6}$/'],
        ], ['codes.*.regex' => 'A category code is 1 to 6 letters.']);

        foreach ($data['codes'] ?? [] as $id => $code) {
            ItemCategory::withTrashed()->whereKey((int) $id)->first()
                ?->forceFill(['code' => $code === null || $code === '' ? null : strtoupper($code)])->save();
        }

        return back()->with('success', 'Category codes saved. They apply to new SKUs only.');
    }

    /** The dry-run preview, downloaded. Nothing is written to the items. */
    public function preview(StockItemSku $sku)
    {
        $preview = $sku->writePreview();

        return response()->download($preview['path'], basename($preview['path']));
    }

    public function apply(Request $request, StockItemSku $sku)
    {
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => 'Tick the box to confirm the preview has been checked.',
        ]);

        $count = $sku->generateMissing();

        return back()->with('success', $count.' item(s) now have an SKU.');
    }

    public function correct(Request $request, StockItem $stockItem, StockItemSku $sku)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:'.StockItemSku::MAX_LENGTH],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['reason.required' => 'Give a reason for changing an SKU.']);

        $old = $stockItem->sku;
        $sku->correct($stockItem, $data['sku'], $data['reason'], $request->user());

        return back()->with('success', 'SKU of "'.$stockItem->name.'" changed from '.($old ?: '—').' to '.$stockItem->fresh()->sku.'.');
    }
}
