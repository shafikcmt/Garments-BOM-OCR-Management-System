<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DepartmentRoles;
use App\Support\PermissionCatalog;
use App\Support\UserAdministrationScope;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    // Laravel's base controller no longer carries this. Added here rather than
    // to App\Http\Controllers\Controller so no other controller's behaviour
    // changes as a side effect of this screen gaining a policy.
    use AuthorizesRequests;

    /**
     * What the signed-in administrator is allowed to hand out.
     *
     * Unlimited for a super admin, confined to one department for a department
     * admin. Every scoping decision in this controller is asked of this object
     * rather than re-derived, so there is one answer per question.
     */
    private function scope(): UserAdministrationScope
    {
        return UserAdministrationScope::for(auth()->user());
    }

    /**
     * First breadcrumb on these screens.
     *
     * The Admin dashboard is still behind role:admin, so a department admin
     * following that crumb would be thrown a 403 from the screen they were
     * just allowed into. /dashboard sends each user to their own department's
     * one, which for them is the right way back.
     *
     * @return array{label: string, url: string}
     */
    private function rootCrumb(): array
    {
        return $this->scope()->isSuperAdmin()
            ? ['label' => 'Admin', 'url' => route('admin.dashboard')]
            : ['label' => 'Dashboard', 'url' => route('dashboard')];
    }

    public function index()
    {
        $this->authorize('viewAny', User::class);

        $scope = $this->scope();

        $users = User::with('roles')->latest()->get();

        // A department admin sees their own department and nobody else. Done
        // after loading so the department can be read off each user's role,
        // which is where it lives — there is no column to filter on in SQL.
        if (! $scope->isSuperAdmin()) {
            $users = $users->filter(
                fn (User $u) => auth()->user()->can('view', $u)
            )->values();
        }

        $stats = [
            'total' => $users->count(),
            'active' => $users->where('status', 1)->count(),
            'inactive' => $users->where('status', '!=', 1)->count(),
            'admins' => $users->filter(fn ($u) => $u->hasRole('admin'))->count(),
            'never_signed_in' => $users->whereNull('last_login_at')->count(),
        ];

        // Counted over the visible users, not the whole table — a department
        // admin's totals must add up to the list underneath them.
        $roleCounts = $scope->assignableRoles()->map(function (Role $role) use ($users, $scope) {
            $role->users_count = $scope->isSuperAdmin()
                ? $role->users()->count()
                : $users->filter(fn (User $u) => $u->hasRole($role->name))->count();

            return $role;
        });

        // Permissions are seeded and gate real features through @can(), but no
        // screen has ever shown them — an admin had to read the database to
        // find out who can do what. Read-only for now: editing this changes
        // access control for everyone.
        $permissionMatrix = [
            'roles' => $scope->assignableRoles(),
            'permissions' => Permission::orderBy('name')->get(),
        ];

        // Current sessions rather than a login history — there is no history
        // table, but the database session driver records who is signed in,
        // from where, and on what.
        $sessions = collect();

        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table', 'sessions'))
                ->whereNotNull('user_id')
                ->orderByDesc('last_activity')
                ->limit(25)
                ->get()
                ->map(fn ($s) => [
                    'user' => $users->firstWhere('id', $s->user_id),
                    'ip' => $s->ip_address,
                    'agent' => $s->user_agent,
                    'last_activity' => $s->last_activity ? Carbon::createFromTimestamp($s->last_activity) : null,
                ])
                ->filter(fn ($s) => $s['user'] !== null)
                ->values();
        }

        $rootCrumb = $this->rootCrumb();

        return view('admin.users.index', compact('users', 'stats', 'roleCounts', 'permissionMatrix', 'sessions', 'scope', 'rootCrumb'));
    }

    public function create()
    {
        $this->authorize('create', User::class);

        $roles = $this->scope()->assignableRoles();

        return view('admin.users.create', array_merge(
            compact('roles'),
            $this->permissionMatrixData()
        ));
    }

    /**
     * Everything the permission matrix partial needs.
     *
     * $rolePermissions maps role name to the permissions it already carries, so
     * the matrix can tick and lock those boxes the moment the role dropdown
     * changes — without a round trip, and without the admin having to guess
     * which of the boxes the role already covers.
     *
     * @return array<string, mixed>
     */
    private function permissionMatrixData(?User $user = null): array
    {
        $catalog = new PermissionCatalog();
        $scope = $this->scope();

        // A department admin is offered their own department and no other, so
        // the select has one option and cannot be used to move somebody out.
        // The controller refuses a different one regardless; this is only so
        // the form does not offer what it will then reject.
        $departments = $scope->isSuperAdmin()
            ? DepartmentRoles::departments()
            : array_intersect_key(
                DepartmentRoles::departments(),
                [$scope->departmentKey() => true]
            );

        return [
            'permissionGroups' => $catalog->grouped(),
            'actionColumns' => $catalog->actionColumns(),
            'catalog' => $catalog,
            'departments' => $departments,
            'scope' => $scope,
            'rootCrumb' => $this->rootCrumb(),
            // null means unlimited (super admin). An empty array would mean
            // "may grant nothing", which is a different thing entirely.
            'grantablePermissions' => $scope->grantablePermissions(),
            'roleDepartments' => DepartmentRoles::indexFor(Role::all()),
            'rolePermissions' => Role::with('permissions')->get()
                ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->all()])
                ->all(),
            // Direct grants only — what this screen owns. Permissions the role
            // brings are shown as already-ticked but are never written here.
            'directPermissions' => $user
                ? $user->getDirectPermissions()->pluck('name')->all()
                : [],
        ];
    }

    /**
     * The role must belong to the department that was chosen with it.
     *
     * The dropdown already hides the others, but hiding an option is a
     * courtesy, not a control — a hand-made post can still name any role. This
     * is the check that actually holds, and it is the one that stops a Store
     * user being submitted with a Management role.
     *
     * A role mapped to no department is allowed through: existing roles that
     * predate the map must stay assignable, or a user holding one could never
     * be edited.
     */
    private function departmentRules(): array
    {
        return [
            'department' => ['nullable', 'string', Rule::in(array_keys(DepartmentRoles::departments()))],
            'role' => [
                'required',
                'exists:roles,name',
                function ($attribute, $value, $fail) {
                    $chosen = request()->input('department');
                    $actual = DepartmentRoles::departmentOf($value);

                    if ($chosen && $actual && $actual !== $chosen) {
                        $fail(sprintf(
                            'The %s role belongs to %s, not %s.',
                            DepartmentRoles::roleLabel($value),
                            DepartmentRoles::labelOf($actual),
                            DepartmentRoles::labelOf($chosen)
                        ));
                    }
                },
            ],
        ];
    }

    /**
     * Refuse a submission that reaches outside the administrator's scope.
     *
     * Called before validation on both create and update, so an out-of-scope
     * post is a 403 and not a form error: it is not a mistake to correct and
     * resubmit, it is an action this account may not take. Nothing is written
     * before every one of these has passed, so a rejected request cannot be
     * applied in part.
     *
     * The form already hides all four of these. That is why they are here — the
     * only way to arrive with one is to have bypassed the form.
     */
    private function guardScopedSubmission(Request $request): void
    {
        $scope = $this->scope();

        if ($scope->isSuperAdmin()) {
            return;
        }

        // 1. The department cannot be swapped. Silently rewriting it to their
        //    own would let a post that asked for Commercial quietly succeed as
        //    Store; the request asked for something they may not do.
        $chosen = $request->input('department');

        abort_if(
            $chosen && $chosen !== $scope->departmentKey(),
            403,
            'You can only manage users in the '.$scope->departmentLabel().' department.'
        );

        // 2. The role must be one of their own department's.
        abort_unless(
            $scope->mayAssignRole($request->input('role')),
            403,
            'You can only assign '.$scope->departmentLabel().' roles.'
        );

        // 3. No granting a permission you do not hold yourself. This is the
        //    self-escalation guard: the matrix lists every permission in the
        //    system, so without it, ticking users.delete and saving would hand
        //    the author a right nobody gave them.
        $overreach = collect($request->input('permissions', []))
            ->filter()
            ->reject(fn ($name) => $scope->mayGrant($name));

        abort_if(
            $overreach->isNotEmpty(),
            403,
            'You can only grant permissions you hold yourself. Not yours: '
                .$overreach->take(3)->implode(', ').'.'
        );

        // 4. Department Admin is a promotion, not an access setting. Only a
        //    super admin may set it — on anyone, including the author.
        abort_if(
            $request->has('is_department_admin') || $request->has('department_admin_control'),
            403,
            'Only a super admin can make someone a Department Admin.'
        );
    }

    /**
     * The value to store for is_department_admin.
     *
     * A checkbox posts nothing when unticked, so the form carries a hidden
     * marker alongside it to say the control was on the page at all. Without
     * that, "unticked" and "the form never showed this field" look identical,
     * and a department admin's save would demote whoever they edited.
     *
     * Reached only after guardScopedSubmission(), so any post carrying these
     * keys is a super admin's.
     */
    private function departmentAdminFlag(Request $request, ?User $user = null): bool
    {
        if (! $request->has('department_admin_control')) {
            return (bool) ($user?->is_department_admin ?? false);
        }

        return $request->boolean('is_department_admin');
    }

    /**
     * Write the ticked boxes as DIRECT permissions on the user.
     *
     * Anything the role already grants is skipped rather than copied down: if
     * it were stored directly too, later changing the role would leave the old
     * permission behind on the user, which is how someone keeps access to a
     * module they were moved out of.
     *
     * syncPermissions replaces the direct set, so unticking removes. Roles are
     * untouched by it.
     */
    private function syncDirectPermissions(User $user, Request $request): void
    {
        // A screen that posts no matrix at all must not wipe existing grants.
        if (! $request->has('permissions')) {
            return;
        }

        $submitted = collect($request->input('permissions', []))
            ->filter()
            ->unique();

        $fromRole = $user->getPermissionsViaRoles()->pluck('name');

        $valid = Permission::whereIn('name', $submitted)->pluck('name');

        $keep = $valid->reject(fn ($name) => $fromRole->contains($name));

        // Grants the editor cannot reach survive their save.
        //
        // syncPermissions replaces the whole direct set, and the matrix posts
        // its full state — so a department admin saving a user would delete
        // every direct grant outside their own rights, including ones a super
        // admin gave. Their boxes are drawn locked, so they would not even see
        // it happen. Escalation is the guarded direction; this is the same bug
        // pointing the other way.
        $grantable = $this->scope()->grantablePermissions();

        if ($grantable !== null) {
            $keep = $keep->merge(
                $user->getDirectPermissions()
                    ->pluck('name')
                    ->reject(fn ($name) => in_array($name, $grantable, true))
            )->unique();
        }

        $user->syncPermissions($keep->values()->all());
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $this->guardScopedSubmission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:6'],
            'status' => ['required', 'in:0,1'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ] + $this->departmentRules());

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
            'is_department_admin' => $this->departmentAdminFlag($request),
        ]);

        $user->syncRoles([$data['role']]);
        $this->syncDirectPermissions($user, $request);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return view('admin.users.show', array_merge(
            compact('user'),
            $this->permissionMatrixData($user)
        ));
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);

        $roles = $this->scope()->assignableRoles();

        return view('admin.users.edit', array_merge(
            compact('user', 'roles'),
            $this->permissionMatrixData($user)
        ));
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $this->guardScopedSubmission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,' . $user->id],
            'status' => ['required', 'in:0,1'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ] + $this->departmentRules());

        $isSelf = $user->id === auth()->id();

        // Safety: admin cannot deactivate or change the role of their own account.
        if ($isSelf) {
            $data['status'] = 1;
            $data['role'] = $user->getRoleNames()->first() ?? $data['role'];
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->status = $data['status'];
        $user->is_department_admin = $this->departmentAdminFlag($request, $user);

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $user->profile_photo = $request->file('profile_photo')
                ->store('profile-photos', 'public');
        }

        $user->save();
        $user->syncRoles([$data['role']]);

        // Same reasoning as the role and status guard above: an admin editing
        // their own account must not be able to strip their own permissions and
        // lock themselves out of the screen they would need to undo it.
        if (! $isSelf) {
            $this->syncDirectPermissions($user, $request);
        }

        $message = $isSelf
            ? 'User profile updated. Note: you cannot change your own role, status or permissions.'
            : 'User profile updated successfully.';

        return redirect()->route('admin.users.edit', $user)->with('success', $message);
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->authorize('resetPassword', $user);

        $request->validate([
            'new_password' => ['required', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($request->input('new_password')),
        ]);

        return redirect()->route('admin.users.edit', $user)
            ->with('success', 'Password has been reset successfully.');
    }

    public function sendPasswordResetLink(User $user)
    {
        $this->authorize('resetPassword', $user);

        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return redirect()->route('admin.users.edit', $user)
                ->with('success', 'Password reset email sent to ' . $user->email . '.');
        }

        return redirect()->route('admin.users.edit', $user)
            ->with('error', 'Could not send password reset email. ' . __($status));
    }

    public function destroy(User $user)
    {
        // Deleting your own account is refused in words rather than a 403 —
        // it is a mistake, not an overreach — so it is checked ahead of the
        // policy, which would otherwise answer first and answer bluntly.
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        $this->authorize('delete', $user);

        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }
}
