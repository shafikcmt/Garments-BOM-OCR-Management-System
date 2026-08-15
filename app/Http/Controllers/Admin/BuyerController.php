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
        $buyer = Buyer::create($this->validatedData($request));

        $this->releaseAdminFromOtherBuyers($buyer);

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

        $this->releaseAdminFromOtherBuyers($buyer);

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

    /**
     * One admin owns one buyer.
     *
     * The column enforces "a buyer has at most one admin" by being a single
     * column; nothing stops the same admin being named on two buyers, and if
     * that happened User::merchantBuyerId() would silently pick whichever came
     * back first — the user would be scoped to a buyer nobody chose. Assigning
     * an admin here therefore releases them from any buyer they held before.
     */
    private function releaseAdminFromOtherBuyers(Buyer $buyer): void
    {
        if (! $buyer->department_admin_id) {
            return;
        }

        Buyer::where('department_admin_id', $buyer->department_admin_id)
            ->whereKeyNot($buyer->id)
            ->update(['department_admin_id' => null]);
    }

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
