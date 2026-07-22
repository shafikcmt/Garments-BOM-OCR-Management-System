<?php

namespace App\Models;

use App\Models\Concerns\TriggersMaterialStockLedger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialBulkIssue extends Model
{
    use HasFactory;
    use TriggersMaterialStockLedger;

    protected $fillable = [
        'excel_file_id',
        'excel_row_id',
        'booking_po_id',
        'material_requisition_id',
        'po_no',
        'buyer_name',
        'season_name',
        'style_name',
        'material_name',
        'material_description',
        'gmts_color_name',
        'art_no',
        'sap_code',
        'material_color',
        'size',
        'uom',
        'indent_section',
        'indent_person',
        'requisition_number',
        'issue_no',
        'issue_date',
        'bulk_qty',
        'sample_qty',
        'liability_qty',
        'dead_qty',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'bulk_qty' => 'decimal:4',
        'sample_qty' => 'decimal:4',
        'liability_qty' => 'decimal:4',
        'dead_qty' => 'decimal:4',
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

    public function requisition()
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
