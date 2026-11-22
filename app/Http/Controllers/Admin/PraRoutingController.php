<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\PraStepApprover;
use App\Models\User;
use App\Services\PraStageResolver;
use App\Support\DepartmentRoles;
use Illuminate\Http\Request;

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
                'label' => $definition['label'],
                'automatic' => DepartmentRoles::labelOf($definition['department']) . ' '
                    . ($definition['actor_kind'] === 'head' ? 'Head' : 'Team')
                    . ($this->resolver->isBuyerScoped($sequence) ? ', for the buyers they have access to' : ', for all buyers'),
                'global_ids' => $forStep->whereNull('buyer_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                'global_users' => $forStep->whereNull('buyer_id')->pluck('user.name')->filter()->sort()->values()->all(),
                'buyer_rules' => $forStep->whereNotNull('buyer_id')->groupBy('buyer_id')->map(fn ($group) => [
                    'buyer_id' => (int) $group->first()->buyer_id,
                    'buyer' => $group->first()->buyer?->buyer_name ?? ('Buyer #' . $group->first()->buyer_id),
                    'ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
                    'users' => $group->pluck('user.name')->filter()->sort()->values()->all(),
                ])->sortBy('buyer')->values()->all(),
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

    /** Approvers for a step, for one buyer. Empty = that buyer follows the all-buyers rule. */
    public function saveBuyer(Request $request, int $sequence)
    {
        $this->assertStep($sequence);
        $data = $request->validate(['buyer_id' => ['required', 'integer', 'exists:buyers,id']]);
        $userIds = $this->validatedUserIds($request);

        PraStepApprover::replaceFor($sequence, (int) $data['buyer_id'], $userIds);

        return redirect()->route('admin.pra-routing.index')->with('success', $this->savedMessage($sequence, (int) $data['buyer_id'], $userIds));
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
