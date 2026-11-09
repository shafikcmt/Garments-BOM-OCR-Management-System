<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Booking PO carried by an inbound shipment, with the quantity on it. */
class InboundShipmentPo extends Model
{
    protected $fillable = ['inbound_shipment_id', 'booking_po_id', 'qty'];

    protected $casts = ['qty' => 'decimal:4'];

    public function shipment()
    {
        return $this->belongsTo(InboundShipment::class, 'inbound_shipment_id');
    }

    public function bookingPo()
    {
        return $this->belongsTo(BookingPo::class);
    }
}
