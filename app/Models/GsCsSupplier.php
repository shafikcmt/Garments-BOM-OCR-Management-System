<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One supplier column on a Comparative Statement. */
class GsCsSupplier extends Model
{
    protected $table = 'gs_cs_suppliers';

    protected $fillable = [
        'gs_comparative_statement_id', 'general_stock_supplier_id', 'name', 'address', 'phone',
        'terms', 'is_recommended', 'sort_order',
    ];

    protected $casts = ['is_recommended' => 'boolean'];

    public function statement()
    {
        return $this->belongsTo(GsComparativeStatement::class, 'gs_comparative_statement_id');
    }

    public function supplier()
    {
        return $this->belongsTo(GeneralStockSupplier::class, 'general_stock_supplier_id')->withTrashed();
    }
}
