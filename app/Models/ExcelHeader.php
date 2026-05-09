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
    ];

    protected $casts = [
        'formula_meta' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'can_view_all' => 'boolean',
        'can_edit_owner_only' => 'boolean',
        'merchant_can_upload' => 'boolean',
    ];

    public function ownerRole()
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    public function cells()
    {
        return $this->hasMany(ExcelCell::class, 'header_id');
    }
}