<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostingLine extends Model
{
    protected $attributes = ['qty' => 1, 'wastage_pct' => 0, 'rate' => 0, 'amount' => 0, 'position' => 0];

    protected $fillable = ['costing_id', 'head', 'description', 'qty', 'unit', 'wastage_pct', 'rate', 'amount', 'position'];

    protected $casts = [
        'qty' => 'decimal:4',
        'wastage_pct' => 'decimal:3',
        'rate' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function costing()
    {
        return $this->belongsTo(Costing::class);
    }
}
