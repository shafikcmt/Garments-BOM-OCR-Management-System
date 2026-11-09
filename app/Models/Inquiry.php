<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A buyer's request for a price on a style, and what became of the quotation. */
class Inquiry extends Model
{
    public const STATUSES = [
        'new' => 'New',
        'costing' => 'Costing',
        'quoted' => 'Quoted',
        'won' => 'Won',
        'lost' => 'Lost',
        'cancelled' => 'Cancelled',
    ];

    /** Still waiting for something. */
    public const OPEN = ['new', 'costing', 'quoted'];

    protected $fillable = [
        'reference', 'buyer_id', 'buyer_season_id', 'mother_style_id', 'style_ref', 'description', 'product_group',
        'est_qty', 'target_price', 'currency', 'received_date', 'quote_due_date', 'status', 'quoted_price', 'quoted_date',
        'decision_date', 'lost_reason', 'note', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'received_date' => 'date', 'quote_due_date' => 'date', 'quoted_date' => 'date', 'decision_date' => 'date',
        'target_price' => 'decimal:4', 'quoted_price' => 'decimal:4',
    ];

    protected $attributes = ['status' => 'new', 'currency' => 'USD'];

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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** The quotation is still to be sent and the buyer's date has passed. */
    public function isQuoteLate(?Carbon $today = null): bool
    {
        return in_array($this->status, ['new', 'costing'], true) && $this->quote_due_date && $this->quote_due_date->lt($today ?? Carbon::today());
    }
}
