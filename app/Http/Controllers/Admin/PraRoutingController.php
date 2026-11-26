<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\PraStepApprover;
use App\Models\User;
use App\Services\PraStageResolver;
use App\Support\DepartmentRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings > PRA Approval Routing.
 *
 * Lets an Admin name who approves each step of a departmental PRA, for every
 * buyer or for one buyer. A step with nobody named keeps the automatic routing
 * (department role + buyer access) PraStageResolver always used.
 */
class PraRoutingController extends Controller
{
    public function __construct(protected PraStageResolver $resolver)
    {
    }

    public function index()
    {
        $available = PraStepApprover::isAvailable();

        $rows = $available
            ? PraStepApprover::with(['user:id,name,email', 'buyer:id,buyer_name'])->get()
            : collect();

        $steps = collect($this->resolver->sequences())->map(function (int $sequence) use ($rows) {
            $definition = $this->resolver->definitionFor($sequence);
            $forStep = $rows->where('sequence', $sequence);

            return [
                'sequence' => $sequence,
                'ordinal' => $this->resolver->ordinal($sequence),
                'label' => $definition['label'],
                'automatic' => $definition['department'] === null
                    ? 'No department: nobody can act until you choose people'
                    : DepartmentRoles::labelOf($definition['department']) . ' '
                        . ($definition['actor_kind'] === 'head' ? 'Head' : 'Team')
                        . ($this->resolver->isBuyerScoped($sequence) ? ', for the buyers they have access to' : ', for all buyers'),
                'global_ids' => $forStep->whereNull('buyer_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                'global_users' => $forStep->whereNull('buyer_id')->pluck('user.name')->filter()->sort()->values()->all(),
                // Buyers that share exactly the same people are shown (and edited) as ONE
                // rule: "Macys, Hugo Boss: Ashikullah, Ripa".
                'buyer_groups' => $forStep->whereNotNull('buyer_id')
                    ->groupBy('buyer_id')
                    ->map(fn ($group) => [
                        'buyer_id' => (int) $group->first()->buyer_id,
                        'buyer' => $group->first()->buyer?->buyer_name ?? ('Buyer #' . $group->first()->buyer_id),
                        'ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                        'users' => $group->pluck('user.name')->filter()->sort()->values()->all(),
                    ])
                    ->groupBy(fn ($rule) => implode(',', $rule['ids']))
                    ->map(fn ($rules) => [
                        'buyer_ids' => $rules->pluck('buyer_id')->all(),
                        'buyers' => $rules->pluck('buyer')->sort()->values()->all(),
                        'ids' => $rules->first()['ids'],
                        'users' => $rules->first()['users'],
                    ])
                    ->sortBy(fn ($group) => $group['buyers'][0] ?? '')
                    ->values()
                    ->all(),
            ];
        })->all();

        return view('admin.pra-routing.index', [
            'available' => $available,
            'steps' => $steps,
            // Everyone who could be chosen, with their role as a small hint so two people
            // with similar names can be told apart.
            'users' => User::query()
                ->where('status', 1)
                ->with('roles:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => ucwords(str_replace('_', ' ', (string) $user->roles->first()?->name)),
                ])->values(),
            'buyers' => Buyer::query()->orderBy('buyer_name')->get(['id', 'buyer_name']),
        ]);
    }

    /** Approvers for a step, for every buyer. Empty = back to automatic. */
    public function saveStep(Request $request, int $sequence)
    {
        $this->assertStep($sequence);
        $userIds = $this->validatedUserIds($request);

        PraStepApprover::replaceFor($sequence, null, $userIds);

        return redirect()->route('admin.pra-routing.index')->with('success', $this->savedMessage($sequence, null, $userIds));
    }

    /**
     * Approvers for a step, for SPECIFIC BUYERS (one or several, but not all).
     *
     * The same people are saved for every ticked buyer. When editing an existing
     * rule, `original_buyer_ids` lists the buyers it covered: any of them that is
     * no longer ticked loses the rule. No people ticked = the ticked buyers go
     * back to the all-buyers rule (or the automatic routing).
     */
    public function saveBuyer(Request $request, int $sequence)
    {
        $this->assertStep($sequence);

        $data = $request->validate([
            'buyer_ids' => ['required', 'array', 'min:1'],
            'buyer_ids.*' => ['integer', 'exists:buyers,id'],
            'original_buyer_ids' => ['nullable', 'array'],
            'original_buyer_ids.*' => ['integer'],
        ], [
            'buyer_ids.required' => 'Tick at least one buyer.',
            'buyer_ids.min' => 'Tick at least one buyer.',
        ]);
        $userIds = $this->validatedUserIds($request);

        $buyerIds = collect($data['buyer_ids'])->map(fn ($id) => (int) $id)->unique()->values()->all();
        $dropped = collect($data['original_buyer_ids'] ?? [])->map(fn ($id) => (int) $id)->diff($buyerIds)->values()->all();

        DB::transaction(function () use ($sequence, $buyerIds, $dropped, $userIds) {
            foreach ($buyerIds as $buyerId) {
                PraStepApprover::replaceFor($sequence, $buyerId, $userIds);
            }

            foreach ($dropped as $buyerId) {
                PraStepApprover::replaceFor($sequence, $buyerId, []);
            }
        });

        $label = $this->resolver->definitionFor($sequence)['label'];
        $names = Buyer::whereIn('id', $buyerIds)->orderBy('buyer_name')->pluck('buyer_name')->implode(', ');

        return redirect()->route('admin.pra-routing.index')->with('success', $userIds === []
            ? "{$label} for {$names}: no one chosen, so they follow the all-buyers rule or the automatic routing."
            : "{$label} for {$names}: " . count($userIds) . ' approver(s) saved.');
    }

    public function removeBuyer(int $sequence, Buyer $buyer)
    {
        $this->assertStep($sequence);

        PraStepApprover::replaceFor($sequence, (int) $buyer->id, []);

        return redirect()->route('admin.pra-routing.index')->with('success', 'Buyer rule removed.');
    }

    protected function assertStep(int $sequence): void
    {
        abort_unless(PraStepApprover::isAvailable() && in_array($sequence, $this->resolver->sequences(), true), 404);
    }

    /** @return list<int> */
    protected function validatedUserIds(Request $request): array
    {
        $data = $request->validate([
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        return collect($data['user_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    protected function savedMessage(int $sequence, ?int $buyerId, array $userIds): string
    {
        $label = $this->resolver->definitionFor($sequence)['label'];
        $scope = $buyerId ? 'for ' . (Buyer::find($buyerId)?->buyer_name ?? 'this buyer') : 'for all buyers';

        return $userIds === []
            ? "{$label} {$scope}: no one chosen, so it follows the automatic routing."
            : "{$label} {$scope}: " . count($userIds) . ' approver(s) saved.';
    }
}
