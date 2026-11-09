<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostingTemplateLine extends Model
{
    protected $attributes = ['qty' => 1, 'wastage_pct' => 0, 'rate' => 0, 'position' => 0];

    protected $fillable = ['costing_template_id', 'head', 'description', 'qty', 'unit', 'wastage_pct', 'rate', 'position'];

    protected $casts = ['qty' => 'decimal:4', 'wastage_pct' => 'decimal:3', 'rate' => 'decimal:4'];
}
