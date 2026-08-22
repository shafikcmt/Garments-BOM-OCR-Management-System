<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BuyerController extends Controller
{
    public function index()
    {
        $buyers = Buyer::with('departmentAdmin')->latest()->paginate(15);

        return view('admin.buyers.index', compact('buyers'));
    }

    public function create()
    {
        return view('admin.buyers.create', [
            'merchantAdmins' => $this->merchantDepartmentAdmins(),
        ]);
    }

    public function store(Request $request)
    {
        Buyer::create($this->validatedData($request));

        return redirect()
            ->route('admin.buyers.index')
            ->with('success', 'Buyer created successfully.');
    }

    public function edit(Buyer $buyer)
    {
        return view('admin.buyers.edit', [
            'buyer' => $buyer,
            'merchantAdmins' => $this->merchantDepartmentAdmins(),
            'assignedUsers' => $this->assignedUsers($buyer),
            'assignableUsers' => $this->assignableUsers($buyer),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Who works on this buyer
    |--------------------------------------------------------------------------
    |
    | The other half of Phase 4d. The User edit screen answers "which buyers is
    | this person on"; this answers "who is on this buyer", which is the
    | question an admin actually arrives at the Buyers screen holding.
    |
    | ONE ASYMMETRY CARRIES THE SAFETY: this screen never writes
    | buyer_scope_mode. Adding an 'all'-mode user to a buyer here would read as
    | granting them something and would in fact CONFINE them to this one buyer,
    | because isBuyerConfined() answers "mode is specific OR they hold buyers".
    | So the picker offers only users already on 'specific', and detaching a
    | user's last buyer is refused rather than silently producing 'specific'
    | with an empty set — the state that denies a user every tagged record
    | while the screen still shows them as assigned. Both of those send the
    | admin to Admin > Users, where the mode does change.
    |
    | Ownership is a different column and is untouched here. A department admin
    | owns buyers through buyers.department_admin_id, set by the field on the
    | form above; they are listed as the owner and never appear in the pivot.
    */

    /**
     * The team members assigned to this buyer, owner excluded.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function assignedUsers(Buyer $buyer)
    {
        return $buyer->users()
            ->where('is_department_admin', false)
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'users.buyer_id', 'users.buyer_scope_mode'])
            ->filter(fn (User $user) => $user->isBuyerScopedDepartment())
            ->values();
    }

    /**
     * The users who may be added to this buyer.
     *
     * Deliberately includes ordinary team members, not only administrators —
     * a buyer's page that listed nobody but its owner would answer a question
     * nobody asks, and the admin would still have to open each user in turn to
     * find out who works on it.
     *
     * Three exclusions, each one a hole if left out:
     *
     *   already assigned    nothing to add.
     *   department admin    owns buyers through the other column; adding one
     *                       here would put two columns behind one screen.
     *   mode is 'all'       THE TRAP. Attaching them would confine somebody who
     *                       works across every buyer, while reading on screen
     *                       as a grant. Set their mode from Admin > Users
     *                       first, which is the only place it changes.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function assignableUsers(Buyer $buyer)
    {
        return User::query()
            ->where('is_department_admin', false)
            ->where('buyer_scope_mode', BuyerScope::MODE_SPECIFIC)
            ->whereDoesntHave('buyers', fn ($query) => $query->where('buyers.id', $buyer->id))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->isBuyerScopedDepartment())
            ->values();
    }

    /**
     * Put a user on this buyer.
     *
     * The picker is narrowed to match, but the picker is not the control: every
     * rule is re-asked here, because a hand-made POST never saw the form.
     */
    public function attachUser(Request $request, Buyer $buyer)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::findOrFail($data['user_id']);

        if (! $this->assignableUsers($buyer)->contains('id', $user->id)) {
            return redirect()
                ->route('admin.buyers.edit', $buyer)
                ->with('error', 'That user cannot be added here. A user who works across all buyers must be set to specific buyers from Admin > Users first.');
        }

        $buyer->users()->syncWithoutDetaching([$user->id]);

        // users.buyer_id is the PRIMARY of the set and scopedBuyerIds() lists it
        // first, so a user whose column is empty would otherwise hold a buyer
        // the column denies. Only filled when empty — repointing somebody's
        // primary is not what "add to this buyer" means.
        if (! $user->buyer_id) {
            $user->forceFill(['buyer_id' => $buyer->id])->save();
        }

        return redirect()
            ->route('admin.buyers.edit', $buyer)
            ->with('success', $user->name . ' now works on ' . $buyer->buyer_name . '.');
    }

    /**
     * Take a user off this buyer.
     *
     * Refused when it is their last one. That would leave buyer_scope_mode at
     * 'specific' with an empty set, which denies them every tagged record while
     * the screen still shows them as a scoped user — the invariant
     * UserController::assertBuyerScopeCoherent() holds on the other side.
     */
    public function detachUser(Buyer $buyer, User $user)
    {
        $remaining = $user->buyers()
            ->where('buyers.id', '!=', $buyer->id)
            ->pluck('buyers.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($remaining === []) {
            return redirect()
                ->route('admin.buyers.edit', $buyer)
                ->with('error', $user->name . ' works on no other buyer. Give them another buyer, or set them to all buyers from Admin > Users, before removing this one.');
        }

        $buyer->users()->detach($user->id);

        sort($remaining);

        // The primary follows the set, exactly as it does on the User screen.
        // Left pointing at the buyer just removed it would hand back the access
        // this action took away.
        if ((int) $user->buyer_id === $buyer->id) {
            $user->forceFill(['buyer_id' => $remaining[0]])->save();
        }

        return redirect()
            ->route('admin.buyers.edit', $buyer)
            ->with('success', $user->name . ' no longer works on ' . $buyer->buyer_name . '.');
    }

    public function update(Request $request, Buyer $buyer)
    {
        $buyer->update($this->validatedData($request, $buyer));

        return redirect()
            ->route('admin.buyers.index')
            ->with('success', 'Buyer updated successfully.');
    }

    public function destroy(Buyer $buyer)
    {
        $buyer->delete();

        return redirect()
            ->route('admin.buyers.index')
            ->with('success', 'Buyer deleted successfully.');
    }

    private function validatedData(Request $request, ?Buyer $buyer = null): array
    {
        $data = $request->validate([
            'buyer_code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('buyers', 'buyer_code')->ignore($buyer?->id),
            ],
            'buyer_name' => ['required', 'string', 'max:255'],

            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],

            'is_active' => ['nullable', 'boolean'],

            // Must be a Merchant department-admin. Validating the role here as
            // well as filtering the dropdown means a hand-made POST naming any
            // other user id is refused rather than quietly stored.
            'department_admin_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('is_department_admin', true)),
            ],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        // Ownership is a super admin's to set. This whole screen sits behind
        // role:admin, so the marker is belt-and-braces rather than the gate —
        // but a form that never showed the field must not read as "clear it".
        if (! $request->has('department_admin_control')) {
            unset($data['department_admin_id']);

            return $data;
        }

        $data['department_admin_id'] = $this->assignableAdminId($request->input('department_admin_id'));

        return $data;
    }

    /*
     * An admin may own SEVERAL buyers. There used to be a
     * releaseAdminFromOtherBuyers() here, called after every create and
     * update, which nulled department_admin_id on every other buyer the
     * chosen admin held. It was written to protect User::merchantBuyerId()
     * from having to choose between two buyers.
     *
     * The protection cost more than it bought. Assigning an admin to a second
     * buyer silently unassigned the first — no warning, no confirmation, and
     * nothing on screen to say it had happened. A super admin setting up two
     * buyers for one merchant admin destroyed their own first assignment by
     * doing the second.
     *
     * The choice it was protecting against is answered properly now:
     * merchantBuyerIds() returns all of them, and merchantBuyerId() picks the
     * lowest id deterministically rather than whatever the database returned
     * first.
     */

    /**
     * The chosen owner, or null. Rejects anyone who is not a Merchant
     * department-admin — the role check the exists() rule above cannot make,
     * because a role lives in a pivot table rather than a users column.
     */
    private function assignableAdminId($value): ?int
    {
        if (! $value) {
            return null;
        }

        return $this->merchantDepartmentAdmins()->contains('id', (int) $value)
            ? (int) $value
            : null;
    }

    /**
     * Merchant department-admins, the only users who may own a buyer.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function merchantDepartmentAdmins()
    {
        return User::query()
            ->where('is_department_admin', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'merchant'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
