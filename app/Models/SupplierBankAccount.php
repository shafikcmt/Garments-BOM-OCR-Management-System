<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vendor bank account. Account, routing and IBAN numbers are encrypted at rest;
 * screens show only account_number_last4 (see maskedNumber). Rows are written
 * only by VendorMaster after an approved bank change request, never straight from a form.
 */
class SupplierBankAccount extends Model
{
    protected $fillable = [
        'supplier_id', 'bank_name', 'bank_branch', 'account_name', 'account_number', 'account_number_last4',
        'account_number_hash', 'routing_number', 'swift_code', 'iban', 'currency', 'is_primary',
        'verification_status', 'approved_by', 'approved_at', 'status',
    ];

    protected $casts = [
        'account_number' => 'encrypted',
        'routing_number' => 'encrypted',
        'iban' => 'encrypted',
        'is_primary' => 'boolean',
        'approved_at' => 'datetime',
    ];

    /** Never serialised: keeps secrets out of JSON responses and logs. */
    protected $hidden = ['account_number', 'routing_number', 'iban', 'account_number_hash'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function maskedNumber(): string
    {
        return $this->account_number_last4 ? '****' . $this->account_number_last4 : '****';
    }

    public static function hashNumber(string $number): string
    {
        return hash_hmac('sha256', preg_replace('/\s+/', '', $number), (string) config('app.key'));
    }
}
