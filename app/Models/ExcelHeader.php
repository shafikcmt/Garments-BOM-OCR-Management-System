<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class ExcelHeader extends Model
{
    use HasFactory;

    protected $fillable = [
        'header_name',
        'header_key',
        'owner_role_id',
        'position',
        'field_type',
        'value_mode',
        'formula_key',
        'formula_meta',
        'is_required',
        'is_active',
        'can_view_all',
        'can_edit_owner_only',
        'merchant_can_upload',
        'track_order_changes',
    ];

    protected $casts = [
        'formula_meta' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'can_view_all' => 'boolean',
        'can_edit_owner_only' => 'boolean',
        'merchant_can_upload' => 'boolean',
        'track_order_changes' => 'boolean',
    ];

    public function ownerRole()
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    /** Roles that may edit this column besides the owner role (Admin > Excel Header Control). */
    public function editorRoles()
    {
        return $this->belongsToMany(Role::class, 'excel_header_editor_roles', 'excel_header_id', 'role_id')->withTimestamps();
    }

    /**
     * Whether a user holding these role ids may edit the column: its owner
     * role or one of the extra editor roles. Admin is checked by the caller.
     */
    public function isEditableByRoleIds($roleIds): bool
    {
        $roleIds = collect($roleIds)->map(fn ($id) => (int) $id);

        if ($roleIds->contains((int) $this->owner_role_id)) {
            return true;
        }

        return $this->editorRoles->pluck('id')->map(fn ($id) => (int) $id)->intersect($roleIds)->isNotEmpty();
    }

    public function cells()
    {
        return $this->hasMany(ExcelCell::class, 'header_id');
    }
}