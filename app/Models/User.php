<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Support\BuyerScope;
use App\Support\DepartmentRoles;
use App\Support\PiAlertSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'designation',
        'phone',
        'password',
        'status',
        'is_department_admin',
        'requisition_section_id',
        'buyer_id',
        'buyer_scope_mode',
        'can_upload',
        'profile_photo',
        'signature_path',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_department_admin' => 'boolean',
        'can_upload' => 'boolean',
        'password' => 'hashed',
    ];

    /**
     * The role this user is administered by — the first one they hold.
     *
     * The user form assigns exactly one role (syncRoles with a single name), so
     * "first" is the whole set in practice. Kept as a method rather than
     * assumed at each call site, because the department is read off it.
     */
    public function primaryRoleName(): ?string
    {
        return $this->getRoleNames()->first();
    }

    /**
     * Whether this user can open the BOM file Workspace, and so should be told
     * about file uploads and edits there.
     *
     * Mirrors the sidebar: Account, Commercial and Supply Chain have a
     * Workspace link in their own menu; everyone else (Store, Management, a
     * narrow store role) needs store.workspace.view, which Admin holds through
     * Gate::before. A user who never sees the Workspace gets no file
     * notifications.
     */
    public function canOpenWorkspace(): bool
    {
        return $this->hasAnyRole(['admin', 'account', 'commercial', 'supply_chain', 'merchant'])
            || $this->can('store.workspace.view');
    }

    /**
     * Department key this user belongs to, or null when their role is mapped to
     * no department (or they hold no role at all).
     *
     * Null is meaningful, not merely missing: a user nobody's department map
     * claims is out of every department admin's reach, and stays a super admin
     * matter. See UserPolicy.
     */
    public function departmentKey(): ?string
    {
        return DepartmentRoles::departmentOf($this->primaryRoleName());
    }

    /**
     * Whether this user administers their own department's users.
     *
     * The flag alone is not enough — an admin of no department can administer
     * nobody, so a department must resolve too. A super admin is NOT a
     * department admin by this definition; they are handled ahead of it
     * everywhere, and conflating the two is how a scope check would come to be
     * applied to the person who is meant to have no scope.
     */
    public function isDepartmentAdmin(): bool
    {
        return $this->is_department_admin === true && $this->departmentKey() !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Buyer scoping
    |--------------------------------------------------------------------------
    |
    | Every method below asks isBuyerScopedDepartment() first and answers
    | null/false/empty for everybody else, so a department that scoping is not
    | switched on for — and the super admin — never reach a buyer comparison
    | at all.
    |
    | WHICH departments those are is BuyerScope::SCOPED_DEPARTMENTS, not a
    | literal in here. Today that list holds Merchandising alone, so every
    | answer below is identical to what it was when this read
    | `departmentKey() === 'merchandising'` in six separate places.
    */

    /**
     * The PRIMARY buyer a normal merchant user was created under.
     *
     * Kept as the default even now that buyers() exists: it is what a new
     * account inherits, what the upload form preselects, and what every query
     * written before the pivot still reads.
     */
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * Every buyer a normal team member is assigned to.
     *
     * Department admins do not use this — they own buyers through
     * buyers.department_admin_id instead. Assignment and ownership are
     * different things and are stored separately on purpose.
     *
     * The pivot carries no department and no module: a row means "assigned",
     * full stop. That is what makes it reusable by a second department without
     * a migration, and it is also why a per-module scope would need real
     * storage rather than an extra argument on a method.
     */
    /**
     * The section this user raises General Stock requisitions for (Cutting,
     * Line-04 …) — an Issue Setup master, set on Admin > Users. Null means
     * the requisition form offers the whole list, as it always did.
     */
    public function requisitionSection()
    {
        return $this->belongsTo(IndentSection::class, 'requisition_section_id');
    }

    /**
     * The HR employee record this account belongs to, if it has one. Its
     * employee_id is the alternative login identifier for staff without a
     * company email. Set on Admin > Users, or once by the user on My Profile
     * while none is linked; the table itself is the seed of the future
     * Employee Master (App\Models\Employee).
     */
    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * The phone number rules, shared by Admin > Users and My Profile: digits
     * with an optional leading "+", spaces, dashes and brackets allowed
     * ("+880 1711-000000", "(02) 9876543"), at least 6 digits, at most 20
     * characters in all.
     *
     * @return array<int, string>
     */
    public static function phoneRules(): array
    {
        return ['nullable', 'string', 'max:20', 'regex:/^(?=(?:\D*\d){6})\+?[0-9 ()\-]+$/'];
    }

    /**
     * @return array<string, string>
     */
    public static function phoneMessages(): array
    {
        return [
            'phone.max' => 'Phone number may be at most 20 characters.',
            'phone.regex' => 'Enter a valid phone number, e.g. +880 1711-000000.',
        ];
    }

    public function buyers()
    {
        return $this->belongsToMany(Buyer::class)->orderBy('buyers.id');
    }

    /**
     * The buyers this user owns as their Merchant department-admin.
     *
     * hasMany, not hasOne. `buyers.department_admin_id` always described one
     * admin per buyer and said nothing about how many buyers an admin may
     * hold — the single-buyer rule lived in application code, in a
     * BuyerController hook that nulled an admin's other buyers whenever one
     * was saved. That hook silently discarded a real assignment, so it is
     * gone and the relationship now reads the column for what it is.
     *
     * Ordered, because merchantBuyerId() picks the first of these and an
     * unordered "first" is whatever the database felt like returning.
     */
    public function ownedBuyers()
    {
        return $this->hasMany(Buyer::class, 'department_admin_id')->orderBy('id');
    }

    /**
     * Whether buyer scoping applies to this user at all.
     *
     * The super admin is excluded here rather than at each call site: they
     * oversee every department and hold no buyer, and treating them as a
     * scoped user is how the one account that must never be locked out gets
     * locked out.
     *
     * Asks appliesToRole(), NOT appliesTo(). The two differ for exactly one
     * department today: Store is scoped, but its General Stock role is carved
     * out of it because General Stock data carries no buyer at all. Asking by
     * department here would confine a General Stock user against tables that
     * have no buyer column to compare. The method name is now narrower than
     * what it asks — kept because renaming it would touch every call site for
     * no behaviour change.
     */
    public function isBuyerScopedDepartment(): bool
    {
        return ! $this->hasRole('admin') && BuyerScope::appliesToRole($this->primaryRoleName());
    }

    /**
     * @deprecated Use isBuyerScopedDepartment(). Kept because renaming a
     * security gate and moving its call sites in one commit is how one gets
     * missed; the call sites move separately, against a green suite.
     */
    public function isMerchantDepartment(): bool
    {
        return $this->isBuyerScopedDepartment();
    }

    /**
     * Every buyer this user is confined to.
     *
     * Two sources, because the assignment is recorded in two places for two
     * different things: OWNERSHIP, which only a department admin has
     * (buyers.department_admin_id), and ACCESS, which anyone may be granted
     * (users.buyer_id + the buyer_user pivot).
     *
     * A department admin may hold BOTH, and the union of the two is what
     * confines them. Reading ownership alone was how a granted-but-unowning
     * admin came back with an empty set and was treated as unconfined.
     *
     * DO NOT read an empty result as "unscoped" in new code. Ask
     * isBuyerConfined(), or better, ask hasBuyerAccess() and let it decide.
     * The two coincide today; from Phase 4b a team member's confinement is
     * decided by buyer_scope_mode, and a 'specific' user with an empty set is
     * confined to NOTHING rather than free of confinement.
     *
     * THIS IS THE ONE TO COMPARE AGAINST. merchantBuyerId() below answers a
     * narrower question and cannot see an admin's second buyer.
     *
     * @return list<int>
     */
    public function scopedBuyerIds(): array
    {
        if (! $this->isBuyerScopedDepartment()) {
            return [];
        }

        if ($this->isDepartmentAdmin()) {
            // OWNERSHIP AND ACCESS, NOT OWNERSHIP ALONE.
            //
            // This branch used to return ownedBuyers() and stop, which read the
            // wrong half for a department admin who had been GRANTED buyers
            // rather than given ownership of them. The tick boxes on the user
            // form write the buyer_user pivot; this never looked at it; the set
            // came back empty — and an empty set for an admin is exactly what
            // isBuyerConfined() reads as "not confined", handing them every
            // buyer in the system. The grant meant to narrow them widened them.
            //
            // Fails open, which is the direction that matters, and it is the
            // very case the docblock above warns about: a 'specific' user
            // holding nothing must be confined to NOTHING, never waved through.
            //
            // No production user was standing on it — every real department
            // admin is mode 'all' today — but that is luck, not design. The
            // profile screen now names buyer access plainly, so granting it to
            // a department admin is about to become the ordinary thing to do,
            // and fail-open must not sit under a screen that invites the input
            // which trips it.
            //
            // Owned ids lead, so an admin's own buyers keep the position they
            // have always held for anything that reads the first element.
            return array_values(array_unique(array_merge(
                $this->ownedBuyers()->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $this->accessBuyerIds()
            )));
        }

        return $this->accessBuyerIds();
    }

    /**
     * The buyers this user has been ASSIGNED — Buyer Access, as written by the
     * tick boxes on the user form. Not ownership; see ownedBuyers().
     *
     * users.buyer_id is the primary one and is listed FIRST, so
     * merchantBuyerId() below still answers with the column every existing
     * caller has always read. The rest follow in id order.
     *
     * Union rather than pivot-only: the column is authoritative on its own for
     * anyone whose pivot row is missing — a user created before the backfill
     * ran, or one added straight to the table — and dropping them to unscoped
     * would quietly hand them access to every buyer.
     *
     * @return list<int>
     */
    private function accessBuyerIds(): array
    {
        $assigned = $this->buyers()->pluck('buyers.id')->map(fn ($id) => (int) $id)->all();

        if (! $this->buyer_id) {
            return $assigned;
        }

        $primary = (int) $this->buyer_id;

        return array_values(array_merge([$primary], array_diff($assigned, [$primary])));
    }

    /**
     * @deprecated Use scopedBuyerIds(). Alias only — same body, same answer.
     *
     * @return list<int>
     */
    public function merchantBuyerIds(): array
    {
        return $this->scopedBuyerIds();
    }

    /**
     * Whether this user is confined to a SET of buyers, as opposed to working
     * across every buyer.
     *
     * The question scopedBuyerIds() cannot answer on its own, and the reason
     * this method exists at all. "Empty set" and "not confined" used to be the
     * same thing; with an explicit mode they are not, and conflating them is
     * the one mistake in this design that silently unscopes everybody.
     *
     *   department admin  confined by what they OWN OR ARE GRANTED. Mode is
     *                     not consulted — applying it would let a mode value
     *                     cancel an ownership the Buyers screen still shows
     *                     them holding. An admin holding neither is therefore
     *                     unconfined whatever the mode says, which is how the
     *                     four 'all'-mode admins in production read today.
     *   team member       confined when their mode says so, OR when they hold
     *                     buyers regardless of the mode. See the comment on
     *                     that expression — the "or" is what makes an unset or
     *                     stale mode fail closed instead of open.
     */
    public function isBuyerConfined(): bool
    {
        if (! $this->isBuyerScopedDepartment()) {
            return false;
        }

        if ($this->isDepartmentAdmin()) {
            return $this->scopedBuyerIds() !== [];
        }

        // EITHER, and the "or" is the safety. Both halves widen confinement;
        // neither can cancel the other.
        //
        //   mode says so    a 'specific' user holding nothing is confined to
        //                   NOTHING — refused on every tagged record — rather
        //                   than waved through as unscoped.
        //   they hold some  holding buyers confines you whatever the mode
        //                   says. 'all' + assignments is a contradiction the
        //                   user form cannot produce (it writes 'specific'
        //                   whenever it assigns), and this resolves it the
        //                   safe way round: an unset or stale mode on a user
        //                   who plainly holds buyers must not hand them every
        //                   buyer in the system.
        //
        // The second half is also why the existing suite needed no edits: a
        // user built with buyer_id and no mode is confined here exactly as the
        // old empty-set rule confined them.
        return $this->buyer_scope_mode === BuyerScope::MODE_SPECIFIC
            || $this->scopedBuyerIds() !== [];
    }

    /**
     * THE BUYER ACCESS PREDICATE. Every department's controllers ask this
     * instead of writing their own comparison.
     *
     * Returns true — ALLOWED — by default, and that default is right for a
     * lock and wrong for a list. Four ways to be allowed, and each is
     * deliberate:
     *
     *   unscoped department  scoping is not switched on for them.
     *   unconfined user      mode 'all', or an admin owning nothing.
     *   untagged record      belongs to no buyer, so no buyer's team can claim
     *                        it, and locking everyone out of the whole existing
     *                        archive is not a scope rule — it is an outage.
     *   theirs               the ordinary case.
     *
     * DO NOT use this to filter a query result in PHP. Records whose buyer is
     * null pass it, which for a list means "show everything" — the opposite of
     * what a filter is for. Narrow lists in SQL, with the null branch written
     * out where it can be read.
     */
    /** Follow-up tasks given to this user. */
    public function followUpTasks()
    {
        return $this->hasMany(FollowUpTask::class, 'assigned_to');
    }

    public function hasBuyerAccess(?int $buyerId): bool
    {
        if (! $this->isBuyerConfined()) {
            return true;
        }

        if ($buyerId === null) {
            return true;
        }

        return in_array($buyerId, $this->scopedBuyerIds(), true);
    }

    /**
     * The PRIMARY buyer this merchant is confined to, or null when unconfined.
     *
     * "Primary" means the lowest-numbered buyer they hold — the oldest
     * assignment. That is a deterministic answer to a question that no longer
     * has a single true one, and it is deliberately kept rather than deleted:
     * every existing caller wants one buyer, and changing what they all read
     * in one go is how a scope rule quietly stops matching.
     *
     * A merchant holding exactly one buyer — which is everyone today — gets
     * the same answer from this as from merchantBuyerIds(), so nothing about
     * their access changes.
     *
     * DO NOT reach for this in new code. An admin owning several buyers is
     * scoped to all of them, and a comparison against this one silently locks
     * them out of the rest. Use merchantBuyerIds().
     */
    public function merchantBuyerId(): ?int
    {
        return $this->merchantBuyerIds()[0] ?? null;
    }

    /**
     * Whether this merchant may upload a BOM file.
     *
     * Unscoped users keep today's behaviour. A scoped department-admin uploads
     * for the buyer they own. A scoped normal user uploads only where their
     * own department-admin has granted the override.
     */
    public function mayUploadBom(): bool
    {
        // Unconfined keeps today's behaviour. Asked as a confinement rather
        // than an empty set so that "has no buyers" cannot be confused with
        // "has several and therefore no single primary" — the confusion that
        // made the old upload fallthrough dangerous — and so that a 'specific'
        // user holding nothing is refused rather than waved through.
        if (! $this->isBuyerConfined()) {
            return true;
        }

        return $this->isDepartmentAdmin() || $this->can_upload === true;
    }

    /**
     * Department label(s) for this user, derived from the assigned role(s).
     * Department is not a separate field — role is the single source of truth.
     */
    public function departmentLabel(): string
    {
        $options = PiAlertSettings::departmentOptions();

        return $this->getRoleNames()
            ->map(fn ($role) => $options[$role] ?? Str::headline($role))
            ->implode(', ');
    }

    /**
     * The title printed under this user's name on a signed document.
     *
     * The admin-set designation when there is one ("Senior Merchandiser");
     * otherwise the department the role implies, with "Head" appended for a
     * department admin — "Merchandising Head", "Accounts Head". The fallback is
     * what every PRA printed before the designation column existed, so a user
     * nobody has titled prints exactly as they always did.
     */
    public function signatureDesignation(): string
    {
        $designation = trim((string) $this->designation);

        if ($designation !== '') {
            return $designation;
        }

        $department = $this->departmentLabel();

        if ($department === '') {
            return '';
        }

        return $this->isDepartmentAdmin() ? $department . ' Head' : $department;
    }

    /**
     * Public URL of the uploaded profile photo, or null when none is set.
     */
    public function avatarUrl(): ?string
    {
        return $this->profile_photo ? Storage::url($this->profile_photo) : null;
    }

    /**
     * Public URL of the uploaded signature image, or null when none is set.
     */
    public function signatureUrl(): ?string
    {
        return $this->signature_path ? Storage::url($this->signature_path) : null;
    }

    /**
     * Whether the user has uploaded a personal signature image.
     */
    public function hasSignature(): bool
    {
        return (bool) $this->signature_path
            && Storage::disk('public')->exists($this->signature_path);
    }

    /**
     * Initials fallback used when no profile photo is uploaded.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name));
        $parts = array_filter($parts);

        if (empty($parts)) {
            return 'U';
        }

        $first = Str::substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? Str::substr(end($parts), 0, 1) : '';

        return Str::upper($first . $last);
    }

    public function excelFiles()
    {
        return $this->hasMany(ExcelFile::class, 'uploaded_by');
    }

    public function updatedCells()
    {
        return $this->hasMany(ExcelCell::class, 'updated_by');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}