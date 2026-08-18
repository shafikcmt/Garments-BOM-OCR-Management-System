<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\User;
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
        ]);
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
