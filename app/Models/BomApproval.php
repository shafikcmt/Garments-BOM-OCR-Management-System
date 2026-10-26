<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomApproval extends Model
{
    public const PENDING = 'pending';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    /** Who decides at each level. */
    public const LEVEL_LABELS = [1 => 'Merchandising Head', 2 => 'Management'];

    protected $fillable = [
        'mother_style_id', 'status', 'level', 'cycle', 'fingerprint', 'submitted_by',
        'submitted_at', 'approved_at', 'changed_notified_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'changed_notified_at' => 'datetime',
    ];

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function steps()
    {
        return $this->hasMany(BomApprovalStep::class)->orderBy('id');
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? 'Approver';
    }
}
