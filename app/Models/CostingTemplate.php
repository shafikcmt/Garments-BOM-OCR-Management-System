<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A ready cost sheet for a product group (one buyer's, or common to all buyers). */
class CostingTemplate extends Model
{
    protected $fillable = ['name', 'buyer_id', 'product_group', 'currency', 'basis', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function lines()
    {
        return $this->hasMany(CostingTemplateLine::class)->orderBy('position')->orderBy('id');
    }
}
