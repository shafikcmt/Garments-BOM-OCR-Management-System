<?php

namespace App\Models;

use App\Models\Concerns\TriggersMaterialStockLedger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialDeadMovement extends Model
{
    use HasFactory;
    use TriggersMaterialStockLedger;

    protected $fillable = [
        'excel_file_id',
        'excel_row_id',
        'booking_po_id',
        'po_no',
        'buyer_name',
        'season_name',
        'style_name',
        'material_description',
        'sap_code',
        'material_color',
        'size',
        'uom',
        'movement_date',
        'transfer_to_bulk_qty',
        'sample_issue_qty',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'transfer_to_bulk_qty' => 'decimal:4',
        'sample_issue_qty' => 'decimal:4',
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
