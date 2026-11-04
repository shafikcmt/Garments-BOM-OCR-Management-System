<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One supplier's rate for one CS line. A NULL price means "did not quote". */
class GsCsQuote extends Model
{
    protected $table = 'gs_cs_quotes';

    protected $fillable = ['gs_cs_item_id', 'gs_cs_supplier_id', 'specification', 'unit_price'];

    protected $casts = ['unit_price' => 'decimal:4'];

    public function item()
    {
        return $this->belongsTo(GsCsItem::class, 'gs_cs_item_id');
    }

    public function supplier()
    {
        return $this->belongsTo(GsCsSupplier::class, 'gs_cs_supplier_id');
    }
}
