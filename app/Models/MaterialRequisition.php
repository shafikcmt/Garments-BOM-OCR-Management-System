<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialRequisition extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ISSUED = 'issued';

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
        'requisition_no',
        'status',
        'qty',
        'requested_by',
        'approved_by',
        'requested_at',
        'approved_at',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
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

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bulkIssues()
    {
        return $this->hasMany(MaterialBulkIssue::class);
    }

    public function items()
    {
        return $this->hasMany(MaterialRequisitionItem::class);
    }
}
