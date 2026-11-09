<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Consumption of one material for one size of a style (before wastage). */
class StyleMaterialSize extends Model
{
    protected $fillable = ['mother_style_id', 'material_key', 'material_name', 'size', 'consumption', 'updated_by'];

    protected $casts = ['consumption' => 'decimal:6'];
}
