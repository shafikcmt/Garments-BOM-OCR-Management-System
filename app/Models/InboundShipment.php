<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** One BL / AWB of imported material, with its route, dates and the POs it carries. */
class InboundShipment extends Model
{
    public const MODES = ['sea' => 'Sea', 'air' => 'Air', 'road' => 'Road', 'courier' => 'Courier'];

    public const BL_STATUSES = ['draft' => 'Draft', 'original' => 'Original received', 'telex' => 'Telex release', 'surrendered' => 'Surrendered'];

    /** Worked out from the dates, in life order. */
    public const STATUSES = [
        'planned' => 'Planned',
        'in_transit' => 'In transit',
        'arrived' => 'Arrived',
        'cleared' => 'Customs cleared',
        'in_house' => 'In-house',
    ];

    protected $fillable = [
        'shipment_no', 'buyer_id', 'ship_mode', 'forwarder', 'vessel', 'bl_awb_no', 'bl_status', 'container_no',
        'port_of_loading', 'port_of_discharge', 'country_of_origin', 'etd', 'atd', 'eta', 'ata', 'customs_cleared_date',
        'committed_inhouse_date', 'actual_inhouse_date', 'remarks', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'etd' => 'date', 'atd' => 'date', 'eta' => 'date', 'ata' => 'date', 'customs_cleared_date' => 'date',
        'committed_inhouse_date' => 'date', 'actual_inhouse_date' => 'date',
    ];

    protected $attributes = ['ship_mode' => 'sea'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function links()
    {
        return $this->hasMany(InboundShipmentPo::class)->orderBy('id');
    }

    /** planned -> in transit (left) -> arrived -> customs cleared -> in-house, from the dates. */
    public function status(): string
    {
        return match (true) {
            $this->actual_inhouse_date !== null => 'in_house',
            $this->customs_cleared_date !== null => 'cleared',
            $this->ata !== null => 'arrived',
            $this->atd !== null => 'in_transit',
            default => 'planned',
        };
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status()];
    }

    /** Not arrived and the expected arrival date has passed. */
    public function isLate(?Carbon $today = null): bool
    {
        return $this->ata === null && $this->eta !== null && $this->eta->lt($today ?? Carbon::today());
    }

    /** Arrived and waiting for customs / delivery: days since arrival. */
    public function waitingDays(?Carbon $today = null): ?int
    {
        if ($this->ata === null || $this->actual_inhouse_date !== null) {
            return null;
        }

        return (int) $this->ata->diffInDays($today ?? Carbon::today());
    }
}
