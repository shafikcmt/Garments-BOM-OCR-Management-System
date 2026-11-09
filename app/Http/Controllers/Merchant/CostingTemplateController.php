<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Costing;
use App\Models\CostingTemplate;
use App\Models\MotherStyle;
use App\Services\Costings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Costing templates, kept on screen. Merchandising and Admin manage a buyer's
 * own templates; templates common to every buyer are Admin's. Management reads.
 */
class CostingTemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $buyerIds = Buyer::active()->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))->pluck('id');

        return view('merchant.costing-templates.index', [
            'templates' => CostingTemplate::with('buyer:id,buyer_name')->withCount('lines')
                ->where(fn ($q) => $q->whereIn('buyer_id', $buyerIds)->orWhereNull('buyer_id'))->orderBy('name')->get(),
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']),
            'isAdmin' => $user->hasRole('admin'),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']), 403);

        return view('merchant.costing-templates.form', ['template' => new CostingTemplate(['is_active' => true]), 'lines' => collect()] + $this->options($request));
    }

    public function store(Request $request)
    {
        [$data, $lines] = $this->validated($request);
        $this->authorizeBuyer($request, $data['buyer_id']);

        DB::transaction(function () use ($data, $lines, $request) {
            $t = CostingTemplate::create($data + ['created_by' => $request->user()->id]);
            $this->syncLines($t, $lines);
        });

        return redirect()->route('merchant.costing_templates.index')->with('success', 'Costing template saved.');
    }

    public function edit(Request $request, CostingTemplate $template)
    {
        $this->authorizeBuyer($request, $template->buyer_id);

        return view('merchant.costing-templates.form', ['template' => $template, 'lines' => $template->lines] + $this->options($request));
    }

    public function update(Request $request, CostingTemplate $template)
    {
        $this->authorizeBuyer($request, $template->buyer_id);
        [$data, $lines] = $this->validated($request);
        $this->authorizeBuyer($request, $data['buyer_id']);

        DB::transaction(function () use ($template, $data, $lines) {
            $template->update($data);
            $this->syncLines($template, $lines);
        });

        return redirect()->route('merchant.costing_templates.index')->with('success', 'Costing template updated.');
    }

    public function destroy(Request $request, CostingTemplate $template)
    {
        $this->authorizeBuyer($request, $template->buyer_id);
        $template->delete();

        return redirect()->route('merchant.costing_templates.index')->with('success', 'Costing template deleted. Costings already made from it are not changed.');
    }

    /** Save the costing on screen as a template. */
    public function fromCosting(Request $request, Costing $costing, \App\Services\CostingTemplates $templates)
    {
        $mother = $costing->motherStyle;
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => 'Type a name for the template.']);

        $templates->saveFromCosting($costing, $data['name'], $request->boolean('for_this_buyer', true), $request->user());

        return back()->with('success', 'Template "' . $data['name'] . '" saved. Start a new costing from it on any style' . ($mother->product_group ? ' of ' . $mother->product_group : '') . '.');
    }

    private function options(Request $request): array
    {
        $user = $request->user();

        return [
            'buyers' => Buyer::active()->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))->orderBy('buyer_name')->get(),
            'groups' => MotherStyle::whereNotNull('product_group')->distinct()->orderBy('product_group')->pluck('product_group'),
            'isAdmin' => $user->hasRole('admin'),
            'heads' => Costings::HEADS,
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'buyer_id' => ['nullable', 'integer', 'exists:buyers,id'],
            'product_group' => ['nullable', 'string', 'max:120'],
            'currency' => ['required', Rule::in(Costing::CURRENCIES)],
            'basis' => ['required', Rule::in(array_keys(Costing::BASES))],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.head' => ['required', Rule::in(array_keys(Costings::HEADS))],
            'lines.*.description' => ['nullable', 'string', 'max:160'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.wastage_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], ['name.required' => 'Type a name for the template.', 'lines.required' => 'Add at least one line.']);

        return [[
            'name' => $data['name'], 'buyer_id' => $data['buyer_id'] ?? null,
            'product_group' => trim((string) ($data['product_group'] ?? '')) ?: null,
            'currency' => $data['currency'], 'basis' => $data['basis'], 'is_active' => $request->boolean('is_active'),
        ], array_values($data['lines'])];
    }

    private function syncLines(CostingTemplate $template, array $lines): void
    {
        $template->lines()->delete();
        foreach ($lines as $i => $l) {
            $template->lines()->create([
                'head' => $l['head'], 'description' => ($l['description'] ?? '') !== '' ? $l['description'] : null,
                'qty' => ($l['qty'] ?? '') !== '' ? $l['qty'] : 1, 'unit' => ($l['unit'] ?? '') !== '' ? $l['unit'] : null,
                'wastage_pct' => ($l['wastage_pct'] ?? '') !== '' ? $l['wastage_pct'] : 0, 'rate' => ($l['rate'] ?? '') !== '' ? $l['rate'] : 0, 'position' => $i,
            ]);
        }
    }

    /** Merchandising and Admin, for a buyer they hold; templates common to all buyers are Admin's. */
    private function authorizeBuyer(Request $request, $buyerId): void
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['merchant', 'admin']), 403);
        abort_unless($buyerId === null ? $user->hasRole('admin') : $user->hasBuyerAccess((int) $buyerId), 403);
    }
}
