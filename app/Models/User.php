<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'password',
        'status',
        'is_department_admin',
        'buyer_id',
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
    | Buyer scoping — Merchandising only
    |--------------------------------------------------------------------------
    |
    | Buyer scoping exists for one department. Every method below asks
    | isMerchantDepartment() first and answers null/false for everybody else,
    | so Commercial, Store, Accounts, Supply Chain, Production and the super
    | admin never reach a buyer comparison at all.
    */

    /** The buyer a normal merchant user was created under. */
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
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
     * scoped merchant is how the one account that must never be locked out
     * gets locked out.
     */
    public function isMerchantDepartment(): bool
    {
        return ! $this->hasRole('admin') && $this->departmentKey() === 'merchandising';
    }

    /**
     * Every buyer this merchant is confined to. Empty means unscoped.
     *
     * Two sources, because the assignment is recorded in two places for two
     * different kinds of user: a department-admin owns buyers
     * (buyers.department_admin_id), a normal user inherits one at creation
     * (users.buyer_id). An empty array means unscoped — the state of every
     * user that existed before buyer scoping, and deliberately unrestricted.
     *
     * THIS IS THE ONE TO COMPARE AGAINST. merchantBuyerId() below answers a
     * narrower question and cannot see an admin's second buyer.
     *
     * @return list<int>
     */
    public function merchantBuyerIds(): array
    {
        if (! $this->isMerchantDepartment()) {
            return [];
        }

        if ($this->isDepartmentAdmin()) {
            return $this->ownedBuyers()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->buyer_id ? [(int) $this->buyer_id] : [];
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
        if ($this->merchantBuyerId() === null) {
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