<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared behaviour for the three "Issue Setup" masters that feed the General
 * Stock issue form — Indent Section, Indent Person and Approved By. They are
 * identical in shape (name + active flag + soft delete), so the model code
 * lives here once instead of being copied three times.
 */
trait IsIssueSetupMaster
{
    public function initializeIsIssueSetupMaster(): void
    {
        $this->mergeFillable(['name', 'is_active', 'remarks', 'created_by']);
        $this->mergeCasts(['is_active' => 'boolean']);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Rows offered in a dropdown: live, active, alphabetical. */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }
}
