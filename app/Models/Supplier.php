<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_code',
        'supplier_name',
        'legal_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'item_type',
        'incoterm',
        'ship_mode',
        'tolerance_percent',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_number',
        'bank_routing_number',
        'bank_swift_code',
        'is_active',
        'vendor_type', 'vendor_category', 'vendor_status', 'approval_status', 'tin', 'vat_bin_number',
        'business_reg_number', 'default_currency', 'default_payment_terms', 'lead_time_days', 'moq',
        'effective_date', 'expiry_date', 'external_erp_code', 'remarks', 'status_reason',
        'created_by', 'updated_by', 'approved_by', 'submitted_at', 'approved_at',
    ];

    public const TYPES = ['local' => 'Local', 'foreign' => 'Foreign'];
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'];
    public const APPROVALS = ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    /** Fields whose changes are written to the vendor audit log. */
    public const AUDITED = [
        'supplier_code', 'supplier_name', 'legal_name', 'vendor_type', 'vendor_category', 'vendor_status',
        'tin', 'vat_bin_number', 'business_reg_number', 'country', 'default_currency', 'default_payment_terms',
        'incoterm', 'ship_mode', 'tolerance_percent', 'lead_time_days', 'moq', 'effective_date', 'expiry_date',
        'external_erp_code', 'remarks',
    ];

    /**
     * is_active (old flag every existing query reads) and vendor_status (new) are kept
     * in step: change either one and the other follows. A blocked vendor is never active.
     */
    protected static function booted(): void
    {
        static::saving(function (Supplier $s) {
            if ($s->isDirty('vendor_status')) {
                $s->is_active = $s->vendor_status === 'active';
            } elseif ($s->isDirty('is_active')) {
                if ($s->vendor_status === 'blocked') {
                    $s->is_active = false;
                } else {
                    $s->vendor_status = $s->is_active ? 'active' : 'inactive';
                }
            }
        });
    }

    protected $casts = [
        'tolerance_percent' => 'decimal:2',
        'moq' => 'decimal:3',
        'is_active' => 'boolean',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Vendors a NEW PO / PI / purchase may use: approved, active, inside the
     * effective/expiry window and (when a BU is given) applicable to that BU.
     * A vendor with no BU rows is applicable to every BU.
     */
    public function scopeSelectable($query, ?int $businessUnitId = null)
    {
        $today = now()->toDateString();

        $query->where('approval_status', 'approved')
            ->where('vendor_status', 'active')
            ->where(fn ($q) => $q->whereNull('effective_date')->orWhere('effective_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today));

        if ($businessUnitId !== null) {
            $query->where(function ($q) use ($businessUnitId, $today) {
                $q->whereDoesntHave('businessUnits')
                    ->orWhereHas('businessUnits', fn ($b) => $b->where('business_unit_id', $businessUnitId)
                        ->where('status', 'active')
                        ->where(fn ($x) => $x->whereNull('effective_date')->orWhere('effective_date', '<=', $today))
                        ->where(fn ($x) => $x->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today)));
            });
        }

        return $query;
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getDisplayNameAttribute()
    {
        return $this->legal_name ?: $this->supplier_name;
    }

    public function getFullAddressAttribute()
    {
        return collect([
            $this->address,
            $this->city,
            $this->country,
        ])->filter()->implode(', ');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function contacts()
    {
        return $this->hasMany(SupplierContact::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function addresses()
    {
        return $this->hasMany(SupplierAddress::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function itemCategories()
    {
        return $this->hasMany(SupplierItemCategory::class)->orderBy('category_name');
    }

    public function items()
    {
        return $this->hasMany(SupplierItem::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(SupplierBankAccount::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function bankChangeRequests()
    {
        return $this->hasMany(SupplierBankChangeRequest::class)->latest('id');
    }

    public function businessUnits()
    {
        return $this->hasMany(SupplierBusinessUnit::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(SupplierAuditLog::class)->latest('id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(now()->startOfDay());
    }

    /**
     * Why this vendor cannot be used for a new transaction, or null when it can.
     * Same rules as scopeSelectable, spelled out for messages.
     */
    public function unusableReason(?int $businessUnitId = null): ?string
    {
        $name = $this->supplier_name;

        return match (true) {
            $this->vendor_status === 'blocked' => "Vendor \"{$name}\" is blocked and cannot be used for new transactions.",
            $this->vendor_status !== 'active' => "Vendor \"{$name}\" is inactive and cannot be used for new transactions.",
            $this->approval_status !== 'approved' => "Vendor \"{$name}\" is not approved yet (status: " . (self::APPROVALS[$this->approval_status] ?? $this->approval_status) . ').',
            $this->isExpired() => "Vendor \"{$name}\" expired on " . $this->expiry_date->format('d M Y') . '.',
            $this->effective_date !== null && $this->effective_date->gt(now()->startOfDay()) => "Vendor \"{$name}\" is effective only from " . $this->effective_date->format('d M Y') . '.',
            $businessUnitId !== null && ! $this->appliesToBusinessUnit($businessUnitId) => "Vendor \"{$name}\" is not applicable to the selected company / BU.",
            default => null,
        };
    }

    public function appliesToBusinessUnit(int $businessUnitId): bool
    {
        $rows = $this->businessUnits;

        return $rows->isEmpty() || $rows->contains(fn ($r) => $r->business_unit_id === $businessUnitId && $r->isCurrent());
    }
}
