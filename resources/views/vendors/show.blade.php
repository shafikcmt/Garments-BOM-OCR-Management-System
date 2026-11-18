@extends('layouts.app')

@section('title', $supplier->supplier_name)

@php
    $approvalTone = ['draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'info', 'approved' => 'success', 'rejected' => 'danger'][$supplier->approval_status] ?? 'secondary';
    $statusTone = ['active' => 'success', 'inactive' => 'secondary', 'blocked' => 'danger'][$supplier->vendor_status] ?? 'secondary';
    $usable = $supplier->unusableReason();
    $listRoute = auth()->user()->hasRole('admin') ? route('admin.suppliers.index') : route('merchant.vendors.index');
    $editRoute = auth()->user()->hasRole('admin') ? route('admin.suppliers.edit', $supplier) : route('merchant.vendors.edit', $supplier);
@endphp

@section('content')
<div class="container-fluid">
    <x-page-header icon="buildings" eyebrow="Vendor Master" :title="$supplier->supplier_name"
                   :copy="($supplier->supplier_code ?: 'No code yet') . ($supplier->legal_name ? ' · ' . $supplier->legal_name : '')">
        <x-slot:actions>
            <a href="{{ $listRoute }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Vendors</a>
            @if($canEdit)
                <a href="{{ $editRoute }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2"><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit Details</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    {{-- Status + workflow --}}
    <x-card class="mb-3" title="Status and approval">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="badge bg-{{ $approvalTone }}-subtle text-{{ $approvalTone }}-emphasis">Approval: {{ \App\Models\Supplier::APPROVALS[$supplier->approval_status] ?? $supplier->approval_status }}</span>
            <span class="badge bg-{{ $statusTone }}-subtle text-{{ $statusTone }}-emphasis">Status: {{ \App\Models\Supplier::STATUSES[$supplier->vendor_status] ?? $supplier->vendor_status }}</span>
            <span class="badge bg-light text-dark border">{{ \App\Models\Supplier::TYPES[$supplier->vendor_type] ?? $supplier->vendor_type }}</span>
            @if($supplier->expiry_date)<span class="badge bg-light text-dark border">Expires {{ $supplier->expiry_date->format('d M Y') }}</span>@endif
        </div>
        @if($usable)
            <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Not available for new PO / PI: {{ $usable }}</div>
        @else
            <div class="alert alert-success py-2 small mb-2"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Available for new PO / PI transactions.</div>
        @endif
        @if($supplier->status_reason)<div class="small text-muted mb-2">Reason: {{ $supplier->status_reason }}</div>@endif

        <form method="POST" class="d-flex flex-wrap gap-2 align-items-end" id="workflowForm">
            @csrf
            <div class="flex-grow-1" style="min-width:220px;max-width:420px;">
                <label class="form-label small mb-1" for="wfRemarks">Remarks (required to reject, block, inactivate, reactivate)</label>
                <input id="wfRemarks" type="text" name="remarks" class="form-control form-control-sm" maxlength="500">
            </div>
            @php
                $wf = function ($action, $label, $icon, $tone = 'primary') use ($supplier) {
                    return '<button type="submit" class="btn btn-sm btn-' . $tone . '" formaction="' . e(route('vendors.workflow', [$supplier, $action])) . '"><i class="bi bi-' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '</button>';
                };
            @endphp
            @if($canEdit && in_array($supplier->approval_status, ['draft', 'rejected']))
                {!! $wf('submit', 'Submit for Approval', 'send') !!}
            @endif
            @if($canApprove && $supplier->approval_status === 'submitted')
                {!! $wf('review', 'Start Review', 'search', 'outline-primary') !!}
            @endif
            @if($canApprove && in_array($supplier->approval_status, ['submitted', 'under_review']))
                {!! $wf('approve', 'Approve', 'check-lg', 'success') !!}
                {!! $wf('reject', 'Reject', 'x-lg', 'outline-danger') !!}
            @endif
            @if($canBlock && $supplier->approval_status === 'approved')
                @if($supplier->vendor_status === 'active')
                    {!! $wf('inactivate', 'Inactivate', 'pause-circle', 'outline-secondary') !!}
                @else
                    {!! $wf('reactivate', 'Reactivate', 'play-circle', 'outline-success') !!}
                @endif
                @if($supplier->vendor_status !== 'blocked')
                    {!! $wf('block', 'Block Vendor', 'slash-circle', 'outline-danger') !!}
                @endif
            @endif
        </form>
    </x-card>

    @if($pendingMods->isNotEmpty())
        <x-card class="mb-3" title="Changes waiting for approval">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Field</th><th>Current</th><th>Requested</th><th>By</th>@if($canApprove)<th></th>@endif</tr></thead>
                <tbody>
                @foreach($pendingMods as $m)
                    <tr>
                        <td>{{ str_replace('_', ' ', $m->field) }}</td><td>{{ $m->old_value ?: '—' }}</td><td>{{ $m->new_value ?: '—' }}</td>
                        <td>{{ $m->changedBy?->name ?? '—' }}</td>
                        @if($canApprove)
                        <td class="text-end">
                            <form method="POST" action="{{ route('vendors.modification.decide', $m) }}" class="d-inline">@csrf
                                <button name="decision" value="approve" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Approve</button>
                                <button name="decision" value="reject" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Reject</button>
                            </form>
                        </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-card>
    @endif

    {{-- Defaults --}}
    <x-card class="mb-3" title="Defaults used on new transactions">
        <div class="row g-3 small">
            @foreach([
                'Incoterm' => $supplier->incoterm, 'Ship mode' => $supplier->ship_mode,
                'Tolerance %' => $supplier->tolerance_percent, 'Currency' => $supplier->default_currency,
                'Payment terms' => $supplier->default_payment_terms, 'Lead time (days)' => $supplier->lead_time_days,
                'MOQ' => $supplier->moq !== null ? rtrim(rtrim((string) $supplier->moq, '0'), '.') : null,
                'TIN' => $supplier->tin, 'VAT / BIN' => $supplier->vat_bin_number, 'Business reg.' => $supplier->business_reg_number,
                'ERP code' => $supplier->external_erp_code, 'Country' => $supplier->country,
            ] as $label => $value)
                <div class="col-6 col-md-3"><div class="text-muted">{{ $label }}</div><div class="fw-semibold">{{ $value ?: '—' }}</div></div>
            @endforeach
        </div>
        <div class="small text-muted mt-2">A PO copies these values when it is generated and may override them; later vendor edits never change an existing PO.</div>
    </x-card>

    <div class="row g-3">
        {{-- Contacts --}}
        <div class="col-lg-6">
            <x-card title="Contacts">
                <table class="table table-sm align-middle">
                    @forelse($supplier->contacts as $c)
                        <tr>
                            <td><div class="fw-semibold">{{ $c->name }} @if($c->is_primary)<span class="badge bg-primary-subtle text-primary-emphasis">Primary</span>@endif</div>
                                <div class="small text-muted">{{ collect([$c->designation, \App\Models\SupplierContact::TYPES[$c->contact_type] ?? null, $c->phone, $c->email])->filter()->implode(' · ') }}</div></td>
                            <td class="text-end text-nowrap">
                                @if($canEdit)
                                    @unless($c->is_primary)
                                    <form method="POST" action="{{ route('vendors.contacts.primary', [$supplier, $c]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">Make Primary</button></form>
                                    @endunless
                                    <form method="POST" action="{{ route('vendors.contacts.destroy', [$supplier, $c]) }}" class="d-inline" data-confirm-message="Remove this contact?" data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">No contacts yet.</td></tr>
                    @endforelse
                </table>
                @if($canEdit)
                <form method="POST" action="{{ route('vendors.contacts.store', $supplier) }}" class="row g-2">
                    @csrf
                    <div class="col-6"><input name="name" class="form-control form-control-sm" placeholder="Contact name *" required></div>
                    <div class="col-6"><input name="designation" class="form-control form-control-sm" placeholder="Designation"></div>
                    <div class="col-6"><input name="email" type="email" class="form-control form-control-sm" placeholder="Email"></div>
                    <div class="col-6"><input name="phone" class="form-control form-control-sm" placeholder="Phone"></div>
                    <div class="col-6"><select name="contact_type" class="form-select form-select-sm">@foreach(\App\Models\SupplierContact::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    <div class="col-6 d-flex align-items-center gap-3"><label class="form-check small"><input type="checkbox" name="is_primary" value="1" class="form-check-input"> Primary</label><button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Contact</button></div>
                </form>
                @endif
            </x-card>
        </div>

        {{-- Addresses --}}
        <div class="col-lg-6">
            <x-card title="Addresses">
                <table class="table table-sm align-middle">
                    @forelse($supplier->addresses as $a)
                        <tr>
                            <td><div class="fw-semibold">{{ \App\Models\SupplierAddress::TYPES[$a->address_type] ?? $a->address_type }} @if($a->is_primary)<span class="badge bg-primary-subtle text-primary-emphasis">Primary</span>@endif</div>
                                <div class="small text-muted">{{ collect([$a->address, $a->city, $a->state, $a->postal_code, $a->country])->filter()->implode(', ') }}</div></td>
                            <td class="text-end text-nowrap">
                                @if($canEdit)
                                    @unless($a->is_primary)
                                    <form method="POST" action="{{ route('vendors.addresses.primary', [$supplier, $a]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">Make Primary</button></form>
                                    @endunless
                                    <form method="POST" action="{{ route('vendors.addresses.destroy', [$supplier, $a]) }}" class="d-inline" data-confirm-message="Remove this address?" data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">No addresses yet.</td></tr>
                    @endforelse
                </table>
                @if($canEdit)
                <form method="POST" action="{{ route('vendors.addresses.store', $supplier) }}" class="row g-2">
                    @csrf
                    <div class="col-4"><select name="address_type" class="form-select form-select-sm">@foreach(\App\Models\SupplierAddress::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    <div class="col-8"><input name="address" class="form-control form-control-sm" placeholder="Address *" required></div>
                    <div class="col-3"><input name="city" class="form-control form-control-sm" placeholder="City"></div>
                    <div class="col-3"><input name="state" class="form-control form-control-sm" placeholder="State"></div>
                    <div class="col-3"><input name="postal_code" class="form-control form-control-sm" placeholder="Postal code"></div>
                    <div class="col-3"><input name="country" class="form-control form-control-sm" placeholder="Country"></div>
                    <div class="col-12 d-flex align-items-center gap-3"><label class="form-check small"><input type="checkbox" name="is_primary" value="1" class="form-check-input"> Primary</label><button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Address</button></div>
                </form>
                @endif
            </x-card>
        </div>

        {{-- Item categories --}}
        <div class="col-lg-6">
            <x-card title="Item categories supplied">
                <table class="table table-sm align-middle">
                    @forelse($supplier->itemCategories as $ic)
                        <tr>
                            <td><div class="fw-semibold">{{ $ic->category_name }} <span class="badge bg-light text-dark border">{{ \App\Models\SupplierItemCategory::TYPES[$ic->item_type] ?? $ic->item_type }}</span></div>
                                <div class="small text-muted">Lead time {{ $ic->lead_time_days ?? '—' }} d · MOQ {{ $ic->moq !== null ? rtrim(rtrim((string) $ic->moq, '0'), '.') : '—' }} · Tolerance {{ $ic->tolerance_percent ?? '—' }}%</div></td>
                            <td class="text-end">@if($canEdit)<form method="POST" action="{{ route('vendors.categories.destroy', [$supplier, $ic]) }}" class="d-inline" data-confirm-message="Remove this category?" data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></form>@endif</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">No categories yet. Vendor defaults apply.</td></tr>
                    @endforelse
                </table>
                @if($canEdit)
                <form method="POST" action="{{ route('vendors.categories.store', $supplier) }}" class="row g-2">
                    @csrf
                    <div class="col-6"><input name="category_name" class="form-control form-control-sm" placeholder="Category *" required></div>
                    <div class="col-6"><select name="item_type" class="form-select form-select-sm">@foreach(\App\Models\SupplierItemCategory::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                    <div class="col-4"><input name="lead_time_days" type="number" min="0" class="form-control form-control-sm" placeholder="Lead days"></div>
                    <div class="col-4"><input name="moq" type="number" min="0" step="0.001" class="form-control form-control-sm" placeholder="MOQ"></div>
                    <div class="col-4"><input name="tolerance_percent" type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" placeholder="Tolerance %"></div>
                    <div class="col-12 text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Category</button></div>
                </form>
                @endif
            </x-card>
        </div>

        {{-- Business units --}}
        <div class="col-lg-6">
            <x-card title="Company / business unit applicability">
                <div class="small text-muted mb-2">No rows means the vendor can be used by every business unit.</div>
                <table class="table table-sm align-middle">
                    @forelse($supplier->businessUnits as $m)
                        <tr>
                            <td>{{ $m->businessUnit?->name }} <span class="small text-muted">{{ $m->effective_date?->format('d M Y') }} @if($m->expiry_date)→ {{ $m->expiry_date->format('d M Y') }}@endif</span></td>
                            <td class="text-end">@if($canEdit)<form method="POST" action="{{ route('vendors.bu.destroy', [$supplier, $m]) }}" class="d-inline" data-confirm-message="Remove this business unit?" data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></form>@endif</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">Applicable to all business units.</td></tr>
                    @endforelse
                </table>
                @if($canEdit && $businessUnits->isNotEmpty())
                <form method="POST" action="{{ route('vendors.bu.store', $supplier) }}" class="row g-2">
                    @csrf
                    <div class="col-5"><select name="business_unit_id" class="form-select form-select-sm">@foreach($businessUnits as $bu)<option value="{{ $bu->id }}">{{ $bu->name }}</option>@endforeach</select></div>
                    <div class="col-3"><input name="effective_date" type="date" class="form-control form-control-sm" title="Effective date"></div>
                    <div class="col-3"><input name="expiry_date" type="date" class="form-control form-control-sm" title="Expiry date"></div>
                    <div class="col-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
                </form>
                @endif
            </x-card>
        </div>
    </div>

    {{-- Bank accounts: only for people who may see them; numbers are always masked here --}}
    @if($canBank)
    <x-card class="my-3" title="Bank accounts">
        <div class="small text-muted mb-2">Numbers are masked. Adding or changing an account is a request that a different approver must accept.</div>
        <table class="table table-sm align-middle">
            <thead><tr><th>Bank</th><th>Account name</th><th>Number</th><th>SWIFT</th><th>Status</th><th></th></tr></thead>
            @forelse($bankAccounts as $b)
                <tr>
                    <td>{{ $b->bank_name }}@if($b->bank_branch), {{ $b->bank_branch }}@endif @if($b->is_primary)<span class="badge bg-primary-subtle text-primary-emphasis">Primary</span>@endif</td>
                    <td>{{ $b->account_name }}</td><td>{{ $b->maskedNumber() }}</td><td>{{ $b->swift_code ?: '—' }}</td>
                    <td><span class="badge bg-{{ $b->status === 'active' ? 'success' : 'secondary' }}-subtle text-{{ $b->status === 'active' ? 'success' : 'secondary' }}-emphasis">{{ ucfirst($b->status) }}</span></td>
                    <td class="text-end">@if($canBankManage && $b->status === 'active')
                        <form method="POST" action="{{ route('vendors.bank.request', $supplier) }}" class="d-inline" data-confirm-message="Send a request to deactivate this bank account?" data-confirm-label="Request">@csrf<input type="hidden" name="action" value="deactivate"><input type="hidden" name="account_id" value="{{ $b->id }}"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Request Deactivation</button></form>
                    @endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-muted">No approved bank accounts.</td></tr>
            @endforelse
        </table>

        @if($bankRequests->isNotEmpty())
        <h6 class="mt-3">Bank change requests</h6>
        <table class="table table-sm align-middle">
            @foreach($bankRequests as $r)
                <tr>
                    <td>{{ ucfirst($r->action) }}</td><td>{{ $r->summary }}</td>
                    <td class="small text-muted">By {{ $r->requester?->name ?? '—' }} · {{ $r->created_at->format('d M Y H:i') }}</td>
                    <td><span class="badge bg-light text-dark border">{{ ucfirst($r->status) }}</span>@if($r->review_remarks)<div class="small text-muted">{{ $r->review_remarks }}</div>@endif</td>
                    <td class="text-end text-nowrap">
                        @if($r->status === 'pending' && $canBankApprove && $r->requested_by !== auth()->id())
                            <form method="POST" action="{{ route('vendors.bank.decide', $r) }}" class="d-inline-flex gap-1">@csrf
                                <input name="remarks" class="form-control form-control-sm" placeholder="Remarks (required to reject)" style="width:190px">
                                <button name="decision" value="approve" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Approve</button>
                                <button name="decision" value="reject" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Reject</button>
                            </form>
                        @elseif($r->status === 'pending' && $r->requested_by === auth()->id())
                            <form method="POST" action="{{ route('vendors.bank.cancel', $r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">Cancel Request</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @endif

        @if($canBankManage)
        <h6 class="mt-3">Request a new bank account</h6>
        <form method="POST" action="{{ route('vendors.bank.request', $supplier) }}" class="row g-2">
            @csrf <input type="hidden" name="action" value="create">
            <div class="col-md-3"><input name="bank_name" class="form-control form-control-sm" placeholder="Bank name *" required></div>
            <div class="col-md-3"><input name="bank_branch" class="form-control form-control-sm" placeholder="Branch"></div>
            <div class="col-md-3"><input name="account_name" class="form-control form-control-sm" placeholder="Account name *" required></div>
            <div class="col-md-3"><input name="account_number" class="form-control form-control-sm" placeholder="Account number *" autocomplete="off" required></div>
            <div class="col-md-3"><input name="routing_number" class="form-control form-control-sm" placeholder="Routing number" autocomplete="off"></div>
            <div class="col-md-3"><input name="swift_code" class="form-control form-control-sm" placeholder="SWIFT"></div>
            <div class="col-md-2"><input name="iban" class="form-control form-control-sm" placeholder="IBAN"></div>
            <div class="col-md-2"><input name="currency" class="form-control form-control-sm" placeholder="Currency" maxlength="10"></div>
            <div class="col-md-2 d-flex align-items-center gap-2"><label class="form-check small"><input type="checkbox" name="is_primary" value="1" class="form-check-input"> Primary</label></div>
            <div class="col-12 text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Send for Approval</button></div>
        </form>
        @endif
    </x-card>
    @endif

    {{-- Vendor-item links --}}
    <x-card class="my-3" title="Items this vendor supplies (material and accessories)">
        <table class="table table-sm align-middle">
            @forelse($supplier->items as $it)
                <tr>
                    <td>{{ $it->stockItem?->name ?? $it->item_name }} <span class="badge bg-light text-dark border">{{ ucfirst($it->item_type) }}</span> @if($it->is_preferred)<span class="badge bg-success-subtle text-success-emphasis">Preferred</span>@endif</td>
                    <td class="small text-muted">Lead {{ $it->lead_time_days ?? '—' }} d · MOQ {{ $it->moq !== null ? rtrim(rtrim((string) $it->moq, '0'), '.') : '—' }}</td>
                    <td class="text-end">@if($canEdit)<form method="POST" action="{{ route('vendors.items.destroy', [$supplier, $it]) }}" class="d-inline" data-confirm-message="Remove this item?" data-confirm-label="Remove">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></form>@endif</td>
                </tr>
            @empty
                <tr><td class="text-muted">No vendor-item links yet.</td></tr>
            @endforelse
        </table>
        @if($canEdit)
        <form method="POST" action="{{ route('vendors.items.store', $supplier) }}" class="row g-2">
            @csrf
            <div class="col-md-3"><input name="item_name" class="form-control form-control-sm" placeholder="Item name *" required></div>
            <div class="col-md-2"><select name="item_type" class="form-select form-select-sm"><option value="material">Material</option><option value="accessory">Accessory</option></select></div>
            <div class="col-md-2"><input name="supplier_item_code" class="form-control form-control-sm" placeholder="Vendor item code"></div>
            <div class="col-md-2"><input name="lead_time_days" type="number" min="0" class="form-control form-control-sm" placeholder="Lead days"></div>
            <div class="col-md-2"><input name="moq" type="number" min="0" step="0.001" class="form-control form-control-sm" placeholder="MOQ"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
        </form>
        @endif
    </x-card>

    {{-- Audit trail --}}
    <x-card class="mb-4" title="Audit trail">
        <div class="table-responsive" style="max-height:360px;overflow:auto;">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Field</th><th>From</th><th>To</th><th>Approval</th></tr></thead>
                @forelse($audit as $log)
                    <tr>
                        <td class="text-nowrap small">{{ $log->created_at->format('d M Y H:i') }}</td>
                        <td class="small">{{ $log->changedBy?->name ?? 'System' }}</td>
                        <td class="small">{{ str_replace('_', ' ', $log->action) }}@if($log->remarks)<div class="text-muted">{{ $log->remarks }}</div>@endif</td>
                        <td class="small">{{ $log->field ? str_replace('_', ' ', $log->field) : '—' }}</td>
                        <td class="small">{{ $log->old_value ?? '—' }}</td><td class="small">{{ $log->new_value ?? '—' }}</td>
                        <td class="small">{{ $log->approval_status ? ucfirst($log->approval_status) : '—' }}@if($log->approvedBy) <span class="text-muted">by {{ $log->approvedBy->name }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No history yet.</td></tr>
                @endforelse
            </table>
        </div>
    </x-card>
</div>
@endsection
