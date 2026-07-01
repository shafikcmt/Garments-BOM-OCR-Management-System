<?php

namespace App\Models;

use App\Support\PiAlertSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $fillable = [
        'user_id',
        'actor_id',
        'excel_file_id',
        'type',
        'title',
        'message',
        'url',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * Limit notifications to those the given user is allowed to see.
     * PI missing alerts are hidden from users whose department/role is not
     * selected in the admin PI alert settings. Admin always sees everything.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query;
        }

        if ($user->hasRole('admin')) {
            return $query;
        }

        $allowedDepartments = PiAlertSettings::departments();
        $userRoles = $user->getRoleNames()->all();

        $canSeePiAlerts = count(array_intersect($userRoles, $allowedDepartments)) > 0;

        if (! $canSeePiAlerts) {
            $query->where('type', '!=', 'pi_missing_alert');
        }

        return $query;
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }
}