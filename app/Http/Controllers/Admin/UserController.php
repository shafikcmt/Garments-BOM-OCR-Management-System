<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
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

        // The buyers this admin may put a new team member on. Only offered
        // when there is a choice: an admin with one buyer still passes it on
        // silently, which is what every merchant admin does today.
        $actor = auth()->user();
        $assignableBuyers = $actor?->isBuyerScopedDepartment() && $actor->isDepartmentAdmin()
            ? Buyer::whereIn('id', $actor->scopedBuyerIds())->orderBy('buyer_name')->get()
            : collect();

        return view('admin.users.create', array_merge(
            compact('roles', 'assignableBuyers'),
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
     * $grantOnly is off for the read-only profile: that screen reports what a
     * user actually holds, and trimming it to the viewer's own rights would
     * quietly under-report someone's access. On the grant forms it is on — see
     * $visible below.
     *
     * @return array<string, mixed>
     */
    private function permissionMatrixData(?User $user = null, bool $grantOnly = true): array
    {
        $catalog = new PermissionCatalog();
        $scope = $this->scope();

        // The matrix used to list every permission in the system to everybody,
        // relying on locked checkboxes to stop a department admin granting what
        // they do not hold. The server still refuses that, but a form should not
        // offer it at all: an admin with no Accounts rights has no business
        // being shown the Accounts module on a create-user screen. Null for a
        // super admin, so their screen is untouched.
        $visible = $grantOnly ? $scope->grantablePermissions() : null;

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
            'permissionGroups' => $catalog->grouped($visible),
            'actionColumns' => $catalog->actionColumns($visible),
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
     * THE SUBSET RULE AT CREATION. The buyers a new team member is assigned to.
     *
     * Never the posted list on its own. Whatever arrives is intersected with
     * the ACTOR's own buyers, so a hand-made post naming a buyer the admin does
     * not hold assigns nothing — the same standard ExcelUploadRequest holds the
     * upload tag to, and for the same reason. The form is narrowed to match,
     * but the form is not the control.
     *
     * @return list<int>  ascending, so the first is the primary buyer_id
     */
    private function assignedBuyerIds(Request $request): array
    {
        $assignable = $this->assignableBuyerIds($request);

        if ($assignable === []) {
            return [];
        }

        // One buyer: inherited silently, exactly as before. No field is shown
        // for it because there is nothing to decide.
        if (count($assignable) === 1) {
            return $assignable;
        }

        $posted = array_map('intval', array_filter((array) $request->input('buyer_ids', [])));

        // array_intersect keeps $assignable's ascending order, so the primary
        // is deterministic rather than whatever order the checkboxes posted in.
        return array_values(array_intersect($assignable, $posted));
    }

    /**
     * The buyers this actor may assign at all — their own, and only when the
     * new user belongs to a buyer-scoped department.
     *
     * Read from the ACTOR, never from the request. A department admin creating
     * a user is creating one of their own team, so the candidate set is a fact
     * about who is doing the creating.
     *
     * Empty for everyone else — a super admin's new users are unscoped, exactly
     * as every user is today. The new role is checked too, so a department admin
     * who somehow creates a user outside a scoped department does not scope them
     * to a buyer that department cannot see.
     *
     * The role check asks BuyerScope rather than naming a department. It read
     * `!== 'merchandising'` until Supply Chain was switched on, which was the
     * last hardcoded department literal in the application and would have
     * silently refused a Supply Chain admin the buyer picker on the create
     * form — while the edit form, which already asked BuyerScope, offered it.
     *
     * @return list<int>
     */
    private function assignableBuyerIds(Request $request): array
    {
        $actor = auth()->user();

        if (! $actor?->isBuyerScopedDepartment() || ! $actor->isDepartmentAdmin()) {
            return [];
        }

        if (! BuyerScope::appliesTo(DepartmentRoles::departmentOf($request->input('role')))) {
            return [];
        }

        return $actor->scopedBuyerIds();
    }

    /**
     * Validation for the buyer picker, which only exists when the actor has
     * more than one buyer to offer.
     *
     * Rule::in is the backend half of the subset rule: the narrowed dropdown
     * stops an honest mistake, this stops a crafted post. Both, never one.
     *
     * @return array<string, list<mixed>>
     */
    private function buyerAssignmentRules(Request $request): array
    {
        $assignable = $this->assignableBuyerIds($request);

        if (count($assignable) < 2) {
            return [];
        }

        return [
            // `required` + `min:1` is also THE INVARIANT that keeps
            // buyer_scope_mode = 'specific' with an empty buyer set
            // unreachable. That state denies the user every tagged record
            // while the screen shows them as assigned, so it is refused at the
            // point of entry rather than handled downstream.
            'buyer_ids' => ['required', 'array', 'min:1'],
            'buyer_ids.*' => ['integer', Rule::in($assignable)],
        ];
    }

    /**
     * Guard for the same invariant on the path validation cannot see.
     *
     * buyerAssignmentRules() only fires when the actor has two or more buyers
     * to offer — a single-buyer admin posts no buyer_ids at all, and a super
     * admin has no picker. So the rules alone do not prove the invariant holds
     * for every caller. This does, and it is deliberately an exception rather
     * than a silent correction: a request that would create an assigned-but-
     * unconfined user is a bug in the caller, and quietly rewriting it to
     * 'all' would hide the bug behind a user who has less access than their
     * record says.
     *
     * @param  list<int>  $assignedBuyerIds
     */
    private function assertBuyerScopeCoherent(string $mode, array $assignedBuyerIds): void
    {
        if ($mode === BuyerScope::MODE_SPECIFIC && $assignedBuyerIds === []) {
            abort(422, 'A user confined to specific buyers must be assigned at least one.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Buyer assignment on the EDIT screen
    |--------------------------------------------------------------------------
    |
    | Until now the buyer set could only be decided once, at creation, and the
    | edit screen showed it as a read-only badge. That made every correction a
    | delete-and-recreate: a merchant moved to a second buyer, or taken off one
    | they no longer work on, had no path through the screen at all.
    |
    | THIS IS THE ONLY PLACE buyer_scope_mode CHANGES. The Buyer screen adds and
    | removes assignments but never touches the mode, because adding an
    | 'all'-mode user to one buyer there would read as granting access and
    | actually restrict them to that single buyer.
    |
    | The helpers below are deliberately separate from assignableBuyerIds() and
    | friends above, which serve creation. Creation reads the department off the
    | POSTED role because the user does not exist yet; editing reads it off the
    | stored user. Sharing one helper between the two would mean one of them
    | asking the wrong question.
    */

    /**
     * Whether the buyer-assignment widget belongs on this user's edit screen.
     *
     * Four gates, and each one is a hole if left out:
     *
     *   target is an owner    a department admin holds buyers through
     *                         buyers.department_admin_id, which is the Buyer
     *                         screen's field. Offering this widget for them
     *                         would put two different columns behind one
     *                         control and let the losing one look authoritative.
     *   department unscoped   nothing reads a buyer set for Commercial, Store
     *                         or Accounts. Assigning one there is a field that
     *                         does nothing, which is worse than no field.
     *   actor is nobody       a guest reaching a controller method.
     *   actor is not an admin the screen is already behind UserPolicy::update,
     *                         but the ability to CHANGE somebody's scope is not
     *                         the same as the ability to edit their name.
     */
    private function mayAssignBuyers(User $target): bool
    {
        $actor = auth()->user();

        if (! $actor) {
            return false;
        }

        if ($target->is_department_admin) {
            return false;
        }

        if (! BuyerScope::appliesTo($target->departmentKey())) {
            return false;
        }

        if ($actor->hasRole('admin')) {
            return true;
        }

        return $actor->isDepartmentAdmin()
            && $actor->isBuyerScopedDepartment()
            && $actor->id !== $target->id;
    }

    /**
     * The buyers this actor may tick for this target, in display order.
     *
     * Two branches, each explicit rather than inferred from an empty set:
     *
     *   unconfined actor   a super admin, or a department admin who works
     *                      across every buyer themselves, chooses from every
     *                      ACTIVE buyer. An inactive one is not offered - it is
     *                      a buyer the company has stopped working with, and
     *                      assigning somebody to it grants nothing.
     *   confined actor     their own holding and no further. THE SUBSET RULE,
     *                      the same standard as creation: an admin may hand out
     *                      only what they hold.
     *
     * @return \Illuminate\Support\Collection<int, Buyer>
     */
    private function assignableBuyersForEdit(User $target)
    {
        if (! $this->mayAssignBuyers($target)) {
            return collect();
        }

        $actor = auth()->user();

        if ($actor->hasRole('admin') || ! $actor->isBuyerConfined()) {
            return Buyer::where('is_active', true)->orderBy('buyer_name')->get();
        }

        return Buyer::whereIn('id', $actor->scopedBuyerIds())->orderBy('buyer_name')->get();
    }

    /**
     * @return list<int>  ascending, so the primary buyer_id is deterministic
     */
    private function assignableBuyerIdsForEdit(User $target): array
    {
        $ids = $this->assignableBuyersForEdit($target)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        sort($ids);

        return array_values($ids);
    }

    /**
     * Whether this actor may set a user to 'all' - that is, un-confine them.
     *
     * Super admin only, and NOT the unconfined department admin who is offered
     * every buyer above. Those two look alike and are not: offering every buyer
     * hands out access the actor already has, one buyer at a time, and stays
     * inside the subset rule. Un-confining hands out every buyer that will EVER
     * exist, including ones created after the click. That is authority rather
     * than access, and it belongs with the flag that confers authority - see
     * UserPolicy::setDepartmentAdmin, gated the same way and for the same
     * reason.
     */
    private function mayUnconfineBuyers(User $target): bool
    {
        return $this->mayAssignBuyers($target) && (bool) auth()->user()?->hasRole('admin');
    }

    /**
     * The mode this request is asking for. Falls to 'specific' for an actor who
     * may not un-confine, whatever they posted - the field is not theirs, so it
     * goes unread rather than being refused, exactly as can_upload does.
     */
    private function postedBuyerScopeMode(Request $request, User $user): string
    {
        if (! $this->mayUnconfineBuyers($user)) {
            return BuyerScope::MODE_SPECIFIC;
        }

        return $request->input('buyer_scope_mode') === BuyerScope::MODE_ALL
            ? BuyerScope::MODE_ALL
            : BuyerScope::MODE_SPECIFIC;
    }

    /**
     * Validation for the edit widget. Only fires when the widget was on the
     * page AND this actor may use it, so a crafted post naming buyer_ids on a
     * screen that never showed them validates nothing and syncs nothing.
     *
     * @return array<string, list<mixed>>
     */
    private function buyerEditRules(Request $request, User $user): array
    {
        if (! $request->has('buyer_assignment_control') || ! $this->mayAssignBuyers($user)) {
            return [];
        }

        $assignable = $this->assignableBuyerIdsForEdit($user);

        if ($assignable === []) {
            return [];
        }

        $rules = [];

        if ($this->mayUnconfineBuyers($user)) {
            $rules['buyer_scope_mode'] = ['required', Rule::in(BuyerScope::modes())];
        }

        // 'specific' is the assumed mode for anyone who cannot choose one, so a
        // department admin always posts a non-empty set. min:1 here is the same
        // INVARIANT creation holds: 'specific' with no buyers denies the user
        // every tagged record while the screen shows them as assigned.
        if ($this->postedBuyerScopeMode($request, $user) === BuyerScope::MODE_SPECIFIC) {
            $rules['buyer_ids'] = ['required', 'array', 'min:1'];
            $rules['buyer_ids.*'] = ['integer', Rule::in($assignable)];
        }

        return $rules;
    }

    /**
     * Write the new buyer set and mode.
     *
     * Same hidden-marker shape as is_department_admin and can_upload: the
     * widget is absent for most targets, so its absence must read as "not
     * shown" and never as "clear their buyers".
     */
    private function syncBuyerAssignment(Request $request, User $user): void
    {
        if (! $request->has('buyer_assignment_control') || ! $this->mayAssignBuyers($user)) {
            return;
        }

        $assignable = $this->assignableBuyerIdsForEdit($user);

        if ($assignable === []) {
            return;
        }

        // 'all' MUST clear the assignments. isBuyerConfined() answers "mode is
        // specific OR they hold buyers", so a user left on 'all' with rows in
        // buyer_user is still confined and the mode change did nothing - a
        // control that reports success and changes no access.
        if ($this->postedBuyerScopeMode($request, $user) === BuyerScope::MODE_ALL) {
            $user->buyers()->sync([]);
            $user->forceFill([
                'buyer_id' => null,
                'buyer_scope_mode' => BuyerScope::MODE_ALL,
            ])->save();

            return;
        }

        $posted = array_map('intval', array_filter((array) $request->input('buyer_ids', [])));

        // array_intersect keeps $assignable's ascending order, so the primary
        // is deterministic rather than whatever order the checkboxes posted in.
        $chosen = array_values(array_intersect($assignable, $posted));

        // Buyers the target holds that this actor cannot reach are not the
        // actor's to remove. Without this, a confined admin opening a shared
        // team member's screen and saving would strip every buyer another admin
        // had assigned - through a form that never showed those fields.
        $untouched = array_values(array_diff(
            $user->buyers()->pluck('buyers.id')->map(fn ($id) => (int) $id)->all(),
            $assignable
        ));

        $final = array_values(array_unique(array_merge($chosen, $untouched)));
        sort($final);

        $this->assertBuyerScopeCoherent(BuyerScope::MODE_SPECIFIC, $final);

        $user->buyers()->sync($final);

        // buyer_id stays the LOWEST id held, matching creation and matching
        // what scopedBuyerIds() lists first. Recomputing it here is what keeps
        // the column and the pivot from disagreeing when the old primary is the
        // buyer being removed.
        $user->forceFill([
            'buyer_id' => $final[0],
            'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
        ])->save();
    }

    /**
     * The value to store for can_upload.
     *
     * Same hidden-marker shape as is_department_admin: the control is only on
     * the page for the owning department admin, so its absence must mean "not
     * shown" rather than "unticked". Gated by UserPolicy::setCanUpload, which
     * is where the rule about WHO may grant it actually lives.
     */
    private function canUploadFlag(Request $request, User $user): bool
    {
        if (! $request->has('can_upload_control')) {
            return (bool) $user->can_upload;
        }

        if (! auth()->user()?->can('setCanUpload', $user)) {
            return (bool) $user->can_upload;
        }

        return $request->boolean('can_upload');
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
        ] + $this->departmentRules() + $this->buyerAssignmentRules($request));

        $assignedBuyerIds = $this->assignedBuyerIds($request);

        $buyerScopeMode = $assignedBuyerIds === []
            ? BuyerScope::MODE_ALL
            : BuyerScope::MODE_SPECIFIC;

        $this->assertBuyerScopeCoherent($buyerScopeMode, $assignedBuyerIds);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
            'is_department_admin' => $this->departmentAdminFlag($request),
            // The primary stays on the column; the full set goes to the pivot
            // below. Both, so a user created here is read the same way as one
            // the Phase 2 backfill produced.
            'buyer_id' => $assignedBuyerIds[0] ?? null,
            // Said explicitly, never left to the column default. A user given
            // buyers who stayed on 'all' would be assigned and unconfined at
            // the same time — the assignment recorded, and every one of its
            // restrictions silently not applied.
            'buyer_scope_mode' => $buyerScopeMode,
        ]);

        if ($assignedBuyerIds !== []) {
            $user->buyers()->sync($assignedBuyerIds);
        }

        $user->syncRoles([$data['role']]);
        $this->syncDirectPermissions($user, $request);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return view('admin.users.show', array_merge(
            compact('user'),
            $this->permissionMatrixData($user, grantOnly: false)
        ));
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);

        $roles = $this->scope()->assignableRoles();

        // A department admin owns their buyers; everyone else inherits one.
        // Resolved here rather than in the view so the screen does no
        // relationship work of its own.
        //
        // A collection either way, because an admin may own several and the
        // screen has to show all of them — an admin looking at one badge when
        // they hold two has no way to tell the second was ever saved.
        // A team member's set is the pivot PLUS users.buyer_id, in that union
        // and not either alone: the pivot is the real set, but a row written
        // before the pivot existed - or by a seeder or a manual fix - lives
        // only in the column, and showing no badge for it would say "assigned
        // to nobody" about a user who is confined.
        $assignedBuyers = $user->isDepartmentAdmin()
            ? $user->ownedBuyers
            : $user->buyers()->get()->concat(collect([$user->buyer])->filter())
                ->unique('id')->sortBy('id')->values();

        // The editable widget, when this actor may change the set at all.
        // Empty for everyone else, and the view falls back to the read-only
        // badges it has always shown.
        $assignableBuyers = $this->assignableBuyersForEdit($user);
        $mayUnconfineBuyers = $this->mayUnconfineBuyers($user);
        $currentBuyerIds = $assignedBuyers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $currentBuyerScopeMode = $user->isBuyerConfined()
            ? BuyerScope::MODE_SPECIFIC
            : BuyerScope::MODE_ALL;

        return view('admin.users.edit', array_merge(
            compact(
                'user',
                'roles',
                'assignedBuyers',
                'assignableBuyers',
                'mayUnconfineBuyers',
                'currentBuyerIds',
                'currentBuyerScopeMode'
            ),
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
        ] + $this->departmentRules() + $this->buyerEditRules($request, $user));

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
        $user->can_upload = $this->canUploadFlag($request, $user);

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $user->profile_photo = $request->file('profile_photo')
                ->store('profile-photos', 'public');
        }

        $user->save();

        // BEFORE syncRoles, deliberately. mayAssignBuyers() reads the target's
        // department off their role, and buyerEditRules() above asked the same
        // question against the role as it stood when the request arrived. Run
        // after the role change and validation would have judged one department
        // while the write judged another - a save that validates buyer_ids and
        // then ignores them, or the reverse. Whether the user is about to leave
        // Merchandising does not change what the form in front of the admin
        // was; a set written for a department that stops being scoped is inert,
        // which is the harmless half of the disagreement.
        $this->syncBuyerAssignment($request, $user);

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
