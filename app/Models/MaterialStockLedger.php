<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Cached closing-stock summary. One row per (excel_row_id, size). All numeric
// columns are maintained by MaterialStockLedgerService — do not edit them by
// hand; they are derived from the receiving / issue / movement event tables.
class MaterialStockLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'excel_file_id',
        'excel_row_id',
        'booking_po_id',
        'size',
        'po_no',
        'buyer_name',
        'season_name',
        'style_name',
        'material_description',
        'sap_code',
        'material_color',
        'gmts_color_name',
        'uom',
        'booking_receive_qty',
        'internal_po_receive_qty',
        'total_receive_qty',
        'bulk_issue_qty',
        'sample_qty',
        'declared_liability_qty',
        'calculated_dead_qty',
        'liability_to_bulk_qty',
        'liability_sample_qty',
        'dead_to_bulk_qty',
        'dead_sample_qty',
        'running_closing_qty',
        'liability_closing_qty',
        'dead_closing_qty',
        'total_closing_qty',
        'avg_unit_price',
        'total_value',
        'recalculated_at',
    ];

    protected $casts = [
        'booking_receive_qty' => 'decimal:4',
        'internal_po_receive_qty' => 'decimal:4',
        'total_receive_qty' => 'decimal:4',
        'bulk_issue_qty' => 'decimal:4',
        'sample_qty' => 'decimal:4',
        'declared_liability_qty' => 'decimal:4',
        'calculated_dead_qty' => 'decimal:4',
        'liability_to_bulk_qty' => 'decimal:4',
        'liability_sample_qty' => 'decimal:4',
        'dead_to_bulk_qty' => 'decimal:4',
        'dead_sample_qty' => 'decimal:4',
        'running_closing_qty' => 'decimal:4',
        'liability_closing_qty' => 'decimal:4',
        'dead_closing_qty' => 'decimal:4',
        'total_closing_qty' => 'decimal:4',
        'avg_unit_price' => 'decimal:4',
        'total_value' => 'decimal:4',
        'recalculated_at' => 'datetime',
    ];

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function excelRow()
    {
        return $this->belongsTo(ExcelRow::class);
    }

    public function bookingPo()
    {
        return $this->belongsTo(BookingPo::class);
    }
}
