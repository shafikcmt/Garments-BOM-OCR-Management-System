<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A proposed bank account change waiting for a second person to approve. */
class SupplierBankChangeRequest extends Model
{
    protected $fillable = [
        'supplier_id', 'supplier_bank_account_id', 'action', 'payload', 'summary', 'status',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_remarks',
    ];

    protected $casts = ['payload' => 'encrypted:array', 'reviewed_at' => 'datetime'];

    protected $hidden = ['payload'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account()
    {
        return $this->belongsTo(SupplierBankAccount::class, 'supplier_bank_account_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
