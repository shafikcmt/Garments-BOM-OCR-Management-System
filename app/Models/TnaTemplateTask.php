<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One task of a TnA template: due offset_days from the anchor date, given to a role. */
class TnaTemplateTask extends Model
{
    public const ANCHORS = [
        'ship_date' => 'Ship date',
        'order_received' => 'Order received',
    ];

    protected $fillable = ['tna_template_id', 'title', 'anchor', 'offset_days', 'assignee_role', 'position'];

    protected $casts = ['offset_days' => 'integer'];

    public function template()
    {
        return $this->belongsTo(TnaTemplate::class, 'tna_template_id');
    }

    /** "21 days before ship date", "3 days after order received", "On ship date". */
    public function whenLabel(): string
    {
        $anchor = mb_strtolower(self::ANCHORS[$this->anchor] ?? $this->anchor);
        $days = abs($this->offset_days);

        return match (true) {
            $this->offset_days === 0 => 'On ' . $anchor,
            $this->offset_days < 0 => $days . ' ' . ($days === 1 ? 'day' : 'days') . ' before ' . $anchor,
            default => $days . ' ' . ($days === 1 ? 'day' : 'days') . ' after ' . $anchor,
        };
    }
}
