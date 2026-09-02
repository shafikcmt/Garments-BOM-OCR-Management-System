<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the Notes / Instructions block on a booking PO.
 *
 * TWO AXES, easy to confuse:
 *
 *   is_default   whether it prints WITHOUT being asked for. True = on every
 *                matching PO; false = only when a Supply Chain user ticks it.
 *   buyer_id     WHICH POs it is allowed on at all. Null = every buyer.
 *
 * They combine: a default row with a buyer prints automatically, but only on
 * that buyer's POs. Both filters are applied by forBuyer() below, which is the
 * one place the rule lives.
 */
class BookingInstruction extends Model
{
    use HasFactory;

    protected $fillable = [
        'instruction',
        'buyer_id',
        'is_default',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'buyer_id' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The buyer this instruction is limited to, or null when it is global.
     *
     * withTrashed(): six of the nine live buyers are soft-deleted and two of
     * them still appear on booking POs. A relation that hid them would blank
     * the Buyer column on the admin list for exactly those rows.
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Instructions allowed on a PO for the given buyer.
     *
     * Global rows (buyer_id null) always qualify; a buyer's own rows join them.
     *
     * A NULL BUYER ID MEANS "COULD NOT BE RESOLVED", NOT "EVERY BUYER". Many
     * BOM buyer names have no row in the buyers table at all, and for those POs
     * the honest answer is globals only — never another buyer's clauses. That
     * is the whole point of the column, so the null case narrows rather than
     * widens.
     */
    public function scopeForBuyer(Builder $query, ?int $buyerId): Builder
    {
        return $query->where(function (Builder $inner) use ($buyerId) {
            $inner->whereNull('buyer_id');

            if ($buyerId !== null) {
                $inner->orWhere('buyer_id', $buyerId);
            }
        });
    }
}
