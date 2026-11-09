<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Register record of one customer PO of a style: destination, contract, quotation, approval. */
class BuyerOrder extends Model
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    public const STATUSES = [
        self::DRAFT => 'Not submitted',
        self::PENDING => 'Waiting for approval',
        self::APPROVED => 'Approved',
        self::RETURNED => 'Returned',
    ];

    protected $fillable = [
        'mother_style_id', 'customer_po_no', 'destination', 'contract_ref', 'costing_id', 'remark',
        'approval_status', 'approved_fingerprint', 'submitted_by', 'submitted_at', 'decided_by', 'decided_at',
        'decision_comment', 'updated_by',
    ];

    protected $casts = ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];

    protected $attributes = ['approval_status' => self::DRAFT, 'customer_po_no' => ''];

    public function motherStyle()
    {
        return $this->belongsTo(MotherStyle::class);
    }

    public function costing()
    {
        return $this->belongsTo(Costing::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
