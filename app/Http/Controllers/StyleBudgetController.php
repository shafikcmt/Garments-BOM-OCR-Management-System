<?php

namespace App\Http\Controllers;

use App\Models\BookingPo;
use App\Models\StyleBudget;
use Illuminate\Http\Request;

/**
 * Manage per-style purchasing budgets. Gated by the `manage-style-budgets`
 * permission (admin + merchandising). Budgets are consulted when a PRA is
 * created; see StyleBudgetService.
 */
class StyleBudgetController extends Controller
{
    public function index(Request $request)
    {
        $budgets = StyleBudget::with('setBy')
            ->orderBy('style_name')
            ->orderBy('season_name')
            ->paginate(25)
            ->withQueryString();

        // Suggestions for the add/edit form.
        $styleOptions = BookingPo::query()
            ->whereNotNull('style_name')
            ->distinct()
            ->orderBy('style_name')
            ->pluck('style_name')
            ->filter()
            ->take(500)
            ->values();

        $buyerOptions = BookingPo::query()->whereNotNull('buyer_name')->distinct()->orderBy('buyer_name')->pluck('buyer_name')->filter()->values();
        $seasonOptions = BookingPo::query()->whereNotNull('season_name')->distinct()->orderBy('season_name')->pluck('season_name')->filter()->values();

        return view('style-budgets.index', compact('budgets', 'styleOptions', 'buyerOptions', 'seasonOptions'));
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        // Upsert on the scope tuple so re-saving the same style/buyer/season
        // updates the amount instead of erroring on the unique index.
        $budget = StyleBudget::firstOrNew([
            'style_name' => $validated['style_name'],
            'buyer_name' => $validated['buyer_name'] ?: null,
            'season_name' => $validated['season_name'] ?: null,
        ]);

        $budget->fill([
            'budget_amount' => $validated['budget_amount'],
            'note' => $validated['note'] ?? null,
            'set_by' => auth()->id(),
        ])->save();

        return back()->with('success', 'Style budget saved for "' . $validated['style_name'] . '".');
    }

    public function update(Request $request, StyleBudget $styleBudget)
    {
        $validated = $this->validated($request);

        $styleBudget->update([
            'style_name' => $validated['style_name'],
            'buyer_name' => $validated['buyer_name'] ?: null,
            'season_name' => $validated['season_name'] ?: null,
            'budget_amount' => $validated['budget_amount'],
            'note' => $validated['note'] ?? null,
            'set_by' => auth()->id(),
        ]);

        return back()->with('success', 'Style budget updated.');
    }

    public function destroy(StyleBudget $styleBudget)
    {
        $styleBudget->delete();

        return back()->with('success', 'Style budget removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'style_name' => ['required', 'string', 'max:255'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'season_name' => ['nullable', 'string', 'max:255'],
            'budget_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
