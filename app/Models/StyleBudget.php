<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A purchasing budget for a style (matched by the free-text style_name), with
 * optional buyer / season scoping. See StyleBudgetService for how the effective
 * budget is resolved and how consumption is measured.
 */
class StyleBudget extends Model
{
    protected $fillable = [
        'style_name',
        'buyer_name',
        'season_name',
        'budget_amount',
        'note',
        'set_by',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
    ];

    public function setBy()
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
