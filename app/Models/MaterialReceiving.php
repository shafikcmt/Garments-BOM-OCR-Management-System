<?php

namespace App\Models;

use App\Models\Concerns\TriggersMaterialStockLedger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialReceiving extends Model
{
    use HasFactory;
    use TriggersMaterialStockLedger;

    public const SOURCE_BOOKING = 'booking';
    public const SOURCE_INTERNAL_PO = 'internal_po';

    protected $fillable = [
        'excel_file_id',
        'excel_row_id',
        'booking_po_id',
        'po_no',
        'buyer_name',
        'season_name',
        'supplier_name',
        'style_name',
        'material_name',
        'material_description',
        'gmts_color_name',
        'art_no',
        'sap_code',
        'material_color',
        'size',
        'uom',
        'grn_no',
        'invoice_no',
        'receive_date',
        'grn_date',
        'source_type',
        'qty',
        'invoice_qty',
        'internal_po_qty',
        'unit_price',
        'invoice_value',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'receive_date' => 'date',
        'grn_date' => 'date',
        // qty = Physical Rcv Qty (the figure the stock ledger consumes).
        'qty' => 'decimal:4',
        'invoice_qty' => 'decimal:4',
        'internal_po_qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'invoice_value' => 'decimal:4',
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

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
