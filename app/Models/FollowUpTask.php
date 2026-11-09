<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class FollowUpTask extends Model
{
    public const OPEN = 'open';

    public const DONE = 'done';

    /** An open task due within this many days shows amber. */
    public const DUE_SOON_DAYS = 2;

    protected $fillable = [
        'title', 'note', 'buyer_id', 'mother_style_id', 'meeting_id', 'assigned_to', 'created_by', 'due_date',
        'status', 'completed_at', 'completed_by', 'escalated_at', 'source', 'meta',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'meta' => 'array',
    ];

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::OPEN);
    }

    public function isCritical(): bool
    {
        return (bool) ($this->meta['critical'] ?? false);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * Traffic light of an open task: red once overdue, amber when due today or
     * within DUE_SOON_DAYS, green otherwise. A finished task has none.
     */
    public function light(?Carbon $today = null): ?string
    {
        if (! $this->isOpen()) {
            return null;
        }

        $today ??= Carbon::today();

        return match (true) {
            $this->due_date->lt($today) => 'red',
            $this->due_date->lte($today->copy()->addDays(self::DUE_SOON_DAYS)) => 'amber',
            default => 'green',
        };
    }
}
