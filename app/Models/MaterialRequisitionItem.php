<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single material line of a requisition slip (child of MaterialRequisition).
 * Required Qty is copied from the PO/BOM; Issued/Received reference the shared
 * stock item master and default to Required/Issued but stay editable.
 */
class MaterialRequisitionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_requisition_id',
        'booking_po_id',
        'excel_row_id',
        'material_description',
        'sap_code',
        'material_color',
        'size',
        'uom',
        'required_qty',
        'issued_stock_item_id',
        'issued_qty',
        'received_stock_item_id',
        'received_qty',
        'remarks',
    ];

    protected $casts = [
        'required_qty' => 'decimal:4',
        'issued_qty' => 'decimal:4',
        'received_qty' => 'decimal:4',
    ];

    public function requisition()
    {
        return $this->belongsTo(MaterialRequisition::class, 'material_requisition_id');
    }

    public function bookingPo()
    {
        return $this->belongsTo(BookingPo::class);
    }

    public function issuedStockItem()
    {
        return $this->belongsTo(StockItem::class, 'issued_stock_item_id');
    }

    public function receivedStockItem()
    {
        return $this->belongsTo(StockItem::class, 'received_stock_item_id');
    }
}
