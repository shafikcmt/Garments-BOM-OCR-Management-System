<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->latest()->get();

        $stats = [
            'total' => $users->count(),
            'active' => $users->where('status', 1)->count(),
            'inactive' => $users->where('status', '!=', 1)->count(),
            'admins' => $users->filter(fn ($u) => $u->hasRole('admin'))->count(),
            'never_signed_in' => $users->whereNull('last_login_at')->count(),
        ];

        $roleCounts = Role::withCount('users')->orderBy('name')->get();

        // Permissions are seeded and gate real features through @can(), but no
        // screen has ever shown them — an admin had to read the database to
        // find out who can do what. Read-only for now: editing this changes
        // access control for everyone.
        $permissionMatrix = [
            'roles' => Role::with('permissions')->orderBy('name')->get(),
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

        return view('admin.users.index', compact('users', 'stats', 'roleCounts', 'permissionMatrix', 'sessions'));
    }

    public function create()
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

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

        return [
            'permissionGroups' => $catalog->grouped(),
            'actionColumns' => $catalog->actionColumns(),
            'catalog' => $catalog,
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

        $user->syncPermissions($valid->reject(fn ($name) => $fromRole->contains($name))->values()->all());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:6'],
            'status' => ['required', 'in:0,1'],
            'role' => ['required', 'exists:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
        ]);

        $user->syncRoles([$data['role']]);
        $this->syncDirectPermissions($user, $request);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function show(User $user)
    {
        return view('admin.users.show', array_merge(
            compact('user'),
            $this->permissionMatrixData($user)
        ));
    }

    public function edit(User $user)
    {
        $roles = Role::with('permissions')->orderBy('name')->get();

        return view('admin.users.edit', array_merge(
            compact('user', 'roles'),
            $this->permissionMatrixData($user)
        ));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,' . $user->id],
            'status' => ['required', 'in:0,1'],
            'role' => ['required', 'exists:roles,name'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $isSelf = $user->id === auth()->id();

        // Safety: admin cannot deactivate or change the role of their own account.
        if ($isSelf) {
            $data['status'] = 1;
            $data['role'] = $user->getRoleNames()->first() ?? $data['role'];
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->status = $data['status'];

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
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }
}
