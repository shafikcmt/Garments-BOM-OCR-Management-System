<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomItem extends Model
{
    protected $fillable = [
        'order_line_id', 'excel_row_id', 'material_type', 'material_description', 'sap_code',
        'material_color', 'uom', 'bom_qty', 'initial_consumption', 'wastage_percent',
        'consumption_incl_yy', 'custom_values',
    ];

    protected $casts = [
        'bom_qty' => 'decimal:4',
        'initial_consumption' => 'decimal:6',
        'wastage_percent' => 'decimal:4',
        'consumption_incl_yy' => 'decimal:6',
        'custom_values' => 'array',
    ];

    public function orderLine()
    {
        return $this->belongsTo(OrderLine::class);
    }

    public function excelRow()
    {
        return $this->belongsTo(ExcelRow::class);
    }
}
