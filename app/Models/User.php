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