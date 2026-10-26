<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** One sample of a style: what, when needed, when sent, and the buyer's verdict. */
class Sample extends Model
{
    public const STATUSES = [
        'requested' => 'Requested',
        'in_progress' => 'In progress',
        'sent' => 'Sent to buyer',
        'approved' => 'Approved',
        'approved_with_comments' => 'Approved with comments',
        'rejected' => 'Rejected',
    ];

    /** Statuses after which nothing more is waited for. */
    public const CLOSED = ['approved', 'approved_with_comments', 'rejected'];

    protected $fillable = [
        'mother_style_id', 'child_style_id', 'sample_type_id', 'gmts_color', 'size', 'qty', 'required_date', 'sent_date',
        'courier_awb', 'status', 'buyer_comment', 'comment_date', 'created_by', 'updated_by',
    ];

    protected $casts = ['required_date' => 'date', 'sent_date' => 'date', 'comment_date' => 'date', 'qty' => 'integer'];

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function childStyle()
    {
        return $this->belongsTo(ChildStyle::class);
    }

    public function type()
    {
        return $this->belongsTo(SampleType::class, 'sample_type_id');
    }

    /** Not sent yet and the buyer's date has passed. */
    public function isLate(?Carbon $today = null): bool
    {
        return in_array($this->status, ['requested', 'in_progress'], true)
            && $this->required_date && $this->required_date->lt($today ?? Carbon::today());
    }

    public function badgeClass(): string
    {
        return match ($this->status) {
            'approved' => 'bg-success text-white',
            'approved_with_comments' => 'bg-success-subtle text-success',
            'rejected' => 'bg-danger text-white',
            'sent' => 'bg-primary-subtle text-primary',
            'in_progress' => 'bg-warning-subtle text-warning-emphasis',
            default => 'bg-light text-dark border',
        };
    }
}
