<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $table = 'materials';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'material_type',
        'description',
        'sap_code',
        'color',
        'unit',
        'cost_per_unit',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'cost_per_unit' => 'decimal:2',
    ];
}
