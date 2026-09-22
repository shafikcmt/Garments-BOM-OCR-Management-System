<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A department: a named group of roles with a head, and the sections under
 * it. Managed on Admin > Settings > Departments. DepartmentRoles reads this
 * table for every department question the rest of the app asks, so the
 * list on the user form, the dashboards and the requisition chain are all
 * this one list.
 */
class Department extends Model
{
    protected $fillable = ['key', 'label', 'head_user_id', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function head()
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function roleLinks()
    {
        return $this->hasMany(DepartmentRole::class, 'department_key', 'key')->orderBy('sort_order');
    }

    public function sections()
    {
        return $this->hasMany(IndentSection::class, 'department_key', 'key')->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    /** @return list<string> role names, primary first */
    public function roleNames(): array
    {
        return $this->roleLinks
            ->sortBy(fn (DepartmentRole $r) => [$r->is_primary ? 0 : 1, $r->sort_order])
            ->pluck('role_name')
            ->values()
            ->all();
    }
}
