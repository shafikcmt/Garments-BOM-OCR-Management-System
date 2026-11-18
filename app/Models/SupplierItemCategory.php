<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A category a vendor supplies, with its own lead time / MOQ / tolerance that beat the vendor defaults. */
class SupplierItemCategory extends Model
{
    public const TYPES = ['material' => 'Material', 'accessory' => 'Accessory', 'general' => 'General'];

    protected $fillable = ['supplier_id', 'item_category_id', 'category_name', 'item_type', 'lead_time_days', 'moq', 'tolerance_percent', 'status'];

    protected $casts = ['moq' => 'decimal:3', 'tolerance_percent' => 'decimal:2'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }
}
