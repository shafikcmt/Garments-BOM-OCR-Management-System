<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One role's membership of a department. A role belongs to one department. */
class DepartmentRole extends Model
{
    protected $fillable = ['department_key', 'role_name', 'is_primary', 'sort_order'];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_key', 'key');
    }
}
