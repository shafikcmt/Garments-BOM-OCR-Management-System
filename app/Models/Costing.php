<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Costing extends Model
{
    public const DRAFT = 'draft';

    public const FINAL = 'final';

    public const MODES = ['buyer' => 'Buyer', 'inhouse' => 'In-house'];

    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'BDT'];

    public const BASES = ['pc' => 'piece', 'dz' => 'dozen'];

    /** Same as the column defaults, so a freshly created object is correct before it is reloaded. */
    protected $attributes = [
        'status' => self::DRAFT,
        'currency' => 'USD',
        'basis' => 'pc',
        'total_cost' => 0,
    ];

    protected $fillable = [
        'mother_style_id', 'mode', 'revision', 'status', 'currency', 'basis', 'quoted_price',
        'total_cost', 'note', 'created_by', 'finalized_by', 'finalized_at',
    ];

    protected $casts = [
        'quoted_price' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'finalized_at' => 'datetime',
    ];

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function lines()
    {
        return $this->hasMany(CostingLine::class)->orderBy('position')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Profit margin as a share of the quoted price, or null when there is no price. */
    public function marginPct(): ?float
    {
        $price = (float) $this->quoted_price;

        return $price > 0 ? round(($price - (float) $this->total_cost) / $price * 100, 1) : null;
    }
}
