<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderLine extends Model
{
    protected $fillable = [
        'excel_file_id', 'line_key', 'buyer_id', 'buyer_season_id', 'mother_style_id',
        'child_style_id', 'style_no', 'gmts_color', 'customer_po_no', 'ship_date',
        'order_qty', 'custom_values',
    ];

    protected $casts = [
        'ship_date' => 'date',
        'order_qty' => 'decimal:4',
        'custom_values' => 'array',
    ];

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function season()
    {
        return $this->belongsTo(BuyerSeason::class, 'buyer_season_id');
    }

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function childStyle()
    {
        return $this->belongsTo(ChildStyle::class);
    }

    public function bomItems()
    {
        return $this->hasMany(BomItem::class);
    }
}
