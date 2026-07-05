<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        'password' => 'hashed',
    ];

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