<?php

namespace App\Http\Controllers;

use App\Models\Costing;
use App\Models\MotherStyle;
use App\Services\Costings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pre-costing screens. Merchandising and Admin edit; Management reads. The
 * rules (draft only, revisions, amounts) are Costings' — this class checks the
 * buyer, the role and the input.
 */
class CostingController extends Controller
{
    public function show(Request $request, MotherStyle $mother, Costings $costings)
    {
        $user = $request->user();
        abort_unless($user->hasBuyerAccess((int) $mother->buyer_id), 403);

        $mode = array_key_exists($request->query('mode'), Costing::MODES) ? $request->query('mode') : 'buyer';

        $revisions = $mother->costings()->where('mode', $mode)->orderByDesc('revision')->with(['finalizer:id,name', 'approver:id,name'])->get();
        $costing = $revisions->firstWhere('revision', $request->integer('rev')) ?? $revisions->first();
        $costing?->load('lines');

        // Where the two modes stand side by side (latest sheet of each).
        $both = collect(array_keys(Costing::MODES))->mapWithKeys(function ($m) use ($mother, $costings) {
            $latest = $mother->costings()->where('mode', $m)->orderByDesc('revision')->first();

            return [$m => $latest ? ['costing' => $latest, 'summary' => $costings->summary($latest)] : null];
        });

        return view('costing.show', [
            'mother' => $mother->load(['buyer', 'season']),
            'mode' => $mode,
            'revisions' => $revisions,
            'costing' => $costing,
            'summary' => $costing ? $costings->summary($costing) : null,
            'latestRevision' => $revisions->first()?->revision,
            'both' => $both,
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']),
            'canApprove' => $costing ? $costings->canApprove($user, $costing) : false,
            'sizeCost' => $costing ? app(\App\Services\SizeWiseCosting::class)->forCosting($costing) : null,
            'comparison' => $costing ? app(\App\Services\CostingVsBom::class)->compare($costing) : null,
        ]);
    }

    public function start(Request $request, MotherStyle $mother, Costings $costings)
    {
        $this->authorizeEdit($request, $mother);
        $mode = $request->validate(['mode' => ['required', Rule::in(array_keys(Costing::MODES))]])['mode'];

        try {
            $costings->start($mother, $mode, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('costing.show', [$mother, 'mode' => $mode])->with('success', 'Costing started.');
    }

    public function update(Request $request, Costing $costing, Costings $costings)
    {
        $this->authorizeEdit($request, $costing->motherStyle);

        $data = $request->validate([
            'currency' => ['required', Rule::in(Costing::CURRENCIES)],
            'basis' => ['required', Rule::in(array_keys(Costing::BASES))],
            'quoted_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.head' => ['required', Rule::in(array_keys(Costings::HEADS))],
            'lines.*.description' => ['nullable', 'string', 'max:160'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.wastage_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'lines.*.rate.numeric' => 'Every rate must be a number.',
            'lines.*.qty.numeric' => 'Every quantity must be a number.',
            'lines.*.wastage_pct.max' => 'Wastage cannot be more than 100%.',
        ]);

        try {
            $costings->save($costing, $data, $data['lines'] ?? []);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Costing saved.');
    }

    public function finalize(Request $request, Costing $costing, Costings $costings)
    {
        $this->authorizeEdit($request, $costing->motherStyle);

        try {
            $costings->finalize($costing, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Revision ' . $costing->revision . ' finalized.');
    }

    public function revise(Request $request, MotherStyle $mother, Costings $costings)
    {
        $this->authorizeEdit($request, $mother);
        $mode = $request->validate(['mode' => ['required', Rule::in(array_keys(Costing::MODES))]])['mode'];

        try {
            $new = $costings->revise($mother, $mode, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return redirect()->route('costing.show', [$mother, 'mode' => $mode, 'rev' => $new->revision])
            ->with('success', 'Revision ' . $new->revision . ' started from the previous one.');
    }

    public function sendForApproval(Request $request, Costing $costing, Costings $costings)
    {
        $this->authorizeEdit($request, $costing->motherStyle);

        try {
            $costings->sendForApproval($costing, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Revision ' . $costing->revision . ' sent for approval.');
    }

    public function approve(Request $request, Costing $costing, Costings $costings)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $costing->motherStyle->buyer_id), 403);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:1000']])['comment'] ?? null;

        try {
            $costings->approve($costing, $request->user(), $comment);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Costing approved.');
    }

    public function returnForChanges(Request $request, Costing $costing, Costings $costings)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $costing->motherStyle->buyer_id), 403);
        $comment = $request->validate(['comment' => ['required', 'string', 'max:1000']], ['comment.required' => 'Write why the costing is returned.'])['comment'];

        try {
            $costings->returnForChanges($costing, $request->user(), $comment);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Costing returned to the merchant.');
    }

    private function authorizeEdit(Request $request, MotherStyle $mother): void
    {
        $user = $request->user();

        abort_unless($user->hasAnyRole(['merchant', 'admin']) && $user->hasBuyerAccess((int) $mother->buyer_id), 403);
    }
}
