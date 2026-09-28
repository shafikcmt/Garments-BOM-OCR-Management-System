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

    /**
     * When the Store department gets a new head, that person is given the
     * Requirement vs Issue / Stock Summary permission and store.requisition.view
     * (they approve the Store Head stage). The previous head loses the
     * Requirement vs Issue permission; store.requisition.view is left alone,
     * since they may still need the requisition screens. Admin can grant or
     * revoke either by hand.
     */
    protected static function booted(): void
    {
        static::saved(function (Department $department) {
            if ($department->key !== 'store'
                || ! ($department->wasRecentlyCreated || $department->wasChanged('head_user_id'))) {
                return;
            }

            $previous = $department->wasRecentlyCreated ? null : $department->getOriginal('head_user_id');

            if ($previous && (int) $previous !== (int) $department->head_user_id) {
                \App\Services\RequirementVsIssueService::revokeFromOldStoreHead((int) $previous);
            }

            if ($department->head_user_id) {
                \App\Services\RequirementVsIssueService::grantToStoreHead($department->head_user_id);
                // The Store head approves the Store Head stage of requisitions.
                \App\Services\RequisitionWorkflow::grantView($department->head_user_id);
            }
        });
    }

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
