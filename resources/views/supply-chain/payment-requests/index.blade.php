@extends('layouts.app')

@section('styles')
<style>
    .payment-request-table-wrap { overflow: visible; }
    .payment-request-table { width: 100%; table-layout: fixed; min-width: 0; }
    .payment-request-table th,
    .payment-request-table td {
        line-height: 1.18;
        padding: .48rem .32rem;
        vertical-align: middle;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .payment-request-table thead th {
        white-space: nowrap;
        font-size: .58rem;
        letter-spacing: .015em;
        text-transform: uppercase;
        color: #1c2d5a;
    }
    .payment-request-table tbody td {
        white-space: nowrap;
        font-size: .68rem;
    }
    .payment-request-table .payment-code { font-size: .62rem; }
    .payment-request-table .comments-cell { max-width: 1px; }
    .payment-request-table .row-create-btn {
        font-size: .62rem;
        padding: .25rem .42rem;
        white-space: nowrap;
    }
    @media (max-width: 1199.98px) {
        .payment-request-table thead th { font-size: .52rem; letter-spacing: 0; }
        .payment-request-table tbody td { font-size: .62rem; }
        .payment-request-table th, .payment-request-table td { padding: .38rem .22rem; }
        .payment-request-table .payment-code { font-size: .56rem; }
        .payment-request-table .row-create-btn { font-size: .55rem; padding: .22rem .32rem; }
    }
</style>
@endsection

@section('content')
@php
    $filterOptions = $filterOptions ?? [];
    $activeFilters = $activeFilters ?? [];
    $filterCards = [
        ['label' => 'Shipment Month', 'name' => 'shipment_month', 'list' => 'shipmentMonthOptions', 'options' => $filterOptions['shipment_months'] ?? []],
        ['label' => 'Vendor Type', 'name' => 'vendor_type', 'list' => 'vendorTypeOptions', 'options' => $filterOptions['vendor_types'] ?? []],
        ['label' => 'Final Status', 'name' => 'final_status', 'list' => 'finalStatusOptions', 'options' => $filterOptions['final_statuses'] ?? []],
        ['label' => 'Payment Term', 'name' => 'payment_term', 'list' => 'paymentTermOptions', 'options' => $filterOptions['payment_terms'] ?? []],
        ['label' => 'Payment Status', 'name' => 'payment_status', 'list' => 'paymentStatusOptions', 'options' => $filterOptions['payment_statuses'] ?? []],
        ['label' => 'Buyer', 'name' => 'buyer', 'list' => 'buyerOptions', 'options' => $filterOptions['buyers'] ?? []],
        ['label' => 'Season', 'name' => 'season', 'list' => 'seasonOptions', 'options' => $filterOptions['seasons'] ?? []],
        ['label' => 'Supplier / Vendor', 'name' => 'supplier', 'list' => 'supplierOptions', 'options' => $filterOptions['suppliers'] ?? []],
        ['label' => 'Material Type', 'name' => 'material_type', 'list' => 'materialTypeOptions', 'options' => $filterOptions['material_types'] ?? []],
        ['label' => 'PI Status', 'name' => 'pi_status', 'list' => 'piStatusOptions', 'options' => $filterOptions['pi_statuses'] ?? []],
        ['label' => 'PO Number', 'name' => 'po_no', 'list' => 'poNoOptions', 'options' => $filterOptions['po_numbers'] ?? []],
        ['label' => 'PI Number', 'name' => 'pi_number', 'list' => 'piNumberOptions', 'options' => $filterOptions['pi_numbers'] ?? []],
    ];
    $dateFilters = [
        ['label' => 'Contract Shipment', 'name' => 'contract_shipment'],
        ['label' => 'Committed Ex Mill', 'name' => 'committed_ex_mill'],
        ['label' => 'PCD Required', 'name' => 'pcd_required'],
        ['label' => 'Payment Required Date', 'name' => 'payment_required_date'],
    ];
@endphp

<div class="container-fluid py-2">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="text-uppercase text-primary fw-bold" style="font-size:10px;letter-spacing:.1em;">Supply Chain</div>
            <h5 class="fw-bold mb-0" style="letter-spacing:-.02em;">Payment Request Approval</h5>
        </div>
        <a href="#payment-request-list" class="btn btn-outline-primary btn-sm" style="min-height:32px;">
            <i class="bi bi-list-check me-1"></i>Request List
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success rounded-4 border-0 shadow-sm">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">{{ session('warning') }}</div>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        <div class="card border-0 shadow-sm flex-fill" style="border-radius:12px;min-width:160px;">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:36px;height:36px;background:#eff6ff;color:#2563eb;font-size:16px;"><i class="bi bi-currency-dollar"></i></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">PI Amount</div><div style="font-size:1.15rem;font-weight:800;color:#0f172a;letter-spacing:-.03em;">{{ number_format((float) ($kpis['total_pi_amount'] ?? 0), 2) }}</div></div>
            </div>
        </div>
        <div class="card border-0 shadow-sm flex-fill" style="border-radius:12px;min-width:140px;">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:36px;height:36px;background:#f0fdf4;color:#15803d;font-size:16px;"><i class="bi bi-file-earmark-check"></i></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">PO Count</div><div style="font-size:1.15rem;font-weight:800;color:#0f172a;letter-spacing:-.03em;">{{ $kpis['total_po_count'] ?? 0 }}</div></div>
            </div>
        </div>
        <div class="card border-0 shadow-sm flex-fill" style="border-radius:12px;min-width:200px;">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:36px;height:36px;background:#fef9ec;color:#b45309;font-size:16px;"><i class="bi bi-bar-chart"></i></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">Budget / Savings</div><div style="font-size:1rem;font-weight:800;color:#0f172a;letter-spacing:-.03em;">{{ number_format((float) ($kpis['total_budget'] ?? 0), 2) }} <span style="color:#cbd5e1;">/</span> {{ number_format((float) ($kpis['total_savings'] ?? 0), 2) }}</div></div>
            </div>
        </div>
        <div class="card border-0 shadow-sm flex-fill" style="border-radius:12px;min-width:180px;">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center rounded-3 flex-shrink-0" style="width:36px;height:36px;background:#fff1f2;color:#e11d48;font-size:16px;"><i class="bi bi-calendar-check"></i></div>
                <div><div style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">Earliest Pay. Required</div><div style="font-size:1rem;font-weight:800;color:#0f172a;letter-spacing:-.03em;">{{ $kpis['earliest_payment_required_date'] ?: '—' }}</div></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;overflow:hidden;">
        <form method="GET" action="{{ route('supply_chain.payment_requests.index') }}">
            <div class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom" style="background:#f8fafc;">
                <span class="fw-bold text-slate-900 me-1" style="font-size:13px;white-space:nowrap;">Filters</span>
                @if(count($activeFilters))
                    <span class="badge rounded-pill text-bg-primary">{{ count($activeFilters) }} active</span>
                @endif
                <div class="ms-auto d-flex gap-2 flex-wrap align-items-center">
                    <button type="submit" class="btn btn-primary btn-sm px-3" style="min-height:32px;"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <a href="{{ route('supply_chain.payment_requests.index') }}" class="btn btn-outline-secondary btn-sm" style="min-height:32px;">Reset</a>
                    <button type="button" class="btn btn-outline-secondary btn-sm" style="min-height:32px;" data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="false">
                        <i class="bi bi-sliders me-1"></i>Advanced
                    </button>
                </div>
            </div>

            {{-- Primary filters row --}}
            <div class="d-flex flex-wrap gap-2 align-items-end p-3" style="background:#fff;">
                <div style="min-width:180px;flex:1 1 180px;">
                    <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Buyer</label>
                    <input list="buyerOptions" name="buyer" value="{{ request('buyer') }}" class="form-control form-control-sm" placeholder="All buyers" style="min-height:34px;">
                    <datalist id="buyerOptions">
                        @foreach($filterOptions['buyers'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                    </datalist>
                </div>
                <div style="min-width:180px;flex:1 1 180px;">
                    <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Supplier / Vendor</label>
                    <input list="supplierOptions" name="supplier" value="{{ request('supplier') }}" class="form-control form-control-sm" placeholder="All suppliers" style="min-height:34px;">
                    <datalist id="supplierOptions">
                        @foreach($filterOptions['suppliers'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                    </datalist>
                </div>
                <div style="min-width:150px;flex:1 1 150px;">
                    <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Season</label>
                    <input list="seasonOptions" name="season" value="{{ request('season') }}" class="form-control form-control-sm" placeholder="All seasons" style="min-height:34px;">
                    <datalist id="seasonOptions">
                        @foreach($filterOptions['seasons'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                    </datalist>
                </div>
                <div style="min-width:140px;flex:1 1 140px;">
                    <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">PI Status</label>
                    <input list="piStatusOptions" name="pi_status" value="{{ request('pi_status') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                    <datalist id="piStatusOptions">
                        @foreach($filterOptions['pi_statuses'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                    </datalist>
                </div>
                <div style="min-width:140px;flex:1 1 140px;">
                    <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Payment Status</label>
                    <input list="paymentStatusOptions" name="payment_status" value="{{ request('payment_status') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                    <datalist id="paymentStatusOptions">
                        @foreach($filterOptions['payment_statuses'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                    </datalist>
                </div>
                <div style="min-width:200px;flex:1 1 200px;">
                    <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Payment Required Date</label>
                    <div class="input-group input-group-sm" style="min-height:34px;">
                        <input type="date" name="payment_required_date_from" value="{{ request('payment_required_date_from') }}" class="form-control" style="min-height:34px;">
                        <input type="date" name="payment_required_date_to" value="{{ request('payment_required_date_to') }}" class="form-control" style="min-height:34px;">
                    </div>
                </div>
            </div>

            {{-- Advanced filters (collapsed) --}}
            <div class="collapse" id="advancedFilters">
                <div class="d-flex flex-wrap gap-2 align-items-end p-3 pt-0" style="background:#fff;border-top:1px solid #f1f5f9;">
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Shipment Month</label>
                        <input list="shipmentMonthOptions" name="shipment_month" value="{{ request('shipment_month') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="shipmentMonthOptions">
                            @foreach($filterOptions['shipment_months'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Vendor Type</label>
                        <input list="vendorTypeOptions" name="vendor_type" value="{{ request('vendor_type') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="vendorTypeOptions">
                            @foreach($filterOptions['vendor_types'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Final Status</label>
                        <input list="finalStatusOptions" name="final_status" value="{{ request('final_status') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="finalStatusOptions">
                            @foreach($filterOptions['final_statuses'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Payment Term</label>
                        <input list="paymentTermOptions" name="payment_term" value="{{ request('payment_term') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="paymentTermOptions">
                            @foreach($filterOptions['payment_terms'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Material Type</label>
                        <input list="materialTypeOptions" name="material_type" value="{{ request('material_type') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="materialTypeOptions">
                            @foreach($filterOptions['material_types'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">PO Number</label>
                        <input list="poNoOptions" name="po_no" value="{{ request('po_no') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="poNoOptions">
                            @foreach($filterOptions['po_numbers'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    <div style="min-width:140px;flex:1 1 140px;">
                        <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">PI Number</label>
                        <input list="piNumberOptions" name="pi_number" value="{{ request('pi_number') }}" class="form-control form-control-sm" placeholder="All" style="min-height:34px;">
                        <datalist id="piNumberOptions">
                            @foreach($filterOptions['pi_numbers'] ?? [] as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>
                    </div>
                    {{-- Remaining date filters except payment_required_date (already shown above) --}}
                    @foreach($dateFilters as $field)
                        @if($field['name'] !== 'payment_required_date')
                        <div style="min-width:200px;flex:1 1 200px;">
                            <label class="form-label mb-1" style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">{{ $field['label'] }}</label>
                            <div class="input-group input-group-sm" style="min-height:34px;">
                                <input type="date" name="{{ $field['name'] }}_from" value="{{ request($field['name'] . '_from') }}" class="form-control" style="min-height:34px;">
                                <input type="date" name="{{ $field['name'] }}_to" value="{{ request($field['name'] . '_to') }}" class="form-control" style="min-height:34px;">
                            </div>
                        </div>
                        @endif
                    @endforeach
                </div>
            </div>

            @if(count($activeFilters))
                <div class="d-flex flex-wrap gap-2 px-3 pb-3" style="background:#fff;">
                    @foreach($activeFilters as $label => $value)
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle" style="font-size:11px;">{{ $label }}: {{ $value }}</span>
                    @endforeach
                </div>
            @endif
        </form>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4" id="pending-pi-payment">
        <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="fw-bold mb-0">Pending PI Payment</h6>
                <div class="small text-muted">Click Preview to review the payment format, then click Create to generate a Payment Request Approval for the selected PO.</div>
            </div>
        </div>
        <div class="payment-request-table-wrap">
            <table class="table table-hover align-middle mb-0 payment-request-table">
                <colgroup>
                    <col style="width:11%">
                    <col style="width:7%">
                    <col style="width:7%">
                    <col style="width:8%">
                    <col style="width:10%">
                    <col style="width:10%">
                    <col style="width:7%">
                    <col style="width:8%">
                    <col style="width:8%">
                    <col style="width:7%">
                    <col style="width:9%">
                    <col style="width:8%">
                </colgroup>
                <thead class="table-light">
                    <tr>
                        <th title="Vendor Name">Vendor</th>
                        <th title="Style">Style</th>
                        <th title="PCD Required">PCD Req.</th>
                        <th title="Payment Term">Pay Term</th>
                        <th title="Material PO Number">PO No.</th>
                        <th title="Material PI Number">PI No.</th>
                        <th title="Material Type">Mat Type</th>
                        <th title="Contract Shipment">Cont. Ship</th>
                        <th title="Committed Ex Mill">Ex Mill</th>
                        <th title="Comments">Comments</th>
                        <th class="text-end" title="PI Amount (USD)">PI Amt USD</th>
                        <th class="text-end" title="Action">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingRows as $row)
                        <tr>
                            <td title="{{ $row['supplier_name'] ?: '-' }}">
                                <div class="fw-semibold text-truncate">{{ $row['supplier_name'] ?: '-' }}</div>
                                <div class="text-muted small text-truncate" title="{{ ($row['buyer_name'] ?: '-') . ' · ' . ($row['season_name'] ?: '-') }}">{{ $row['buyer_name'] ?: '-' }} · {{ $row['season_name'] ?: '-' }}</div>
                            </td>
                            <td title="{{ $row['style_name'] ?: '-' }}">{{ $row['style_name'] ?: '-' }}</td>
                            <td title="{{ $row['pcd_required'] ?: '-' }}">{{ $row['pcd_required'] ?: '-' }}</td>
                            <td title="{{ $row['payment_term'] ?: '-' }}">{{ $row['payment_term'] ?: '-' }}</td>
                            <td class="fw-bold payment-code" title="{{ $row['po_no'] ?: '-' }}">{{ $row['po_no'] ?: '-' }}</td>
                            <td class="fw-bold payment-code" title="{{ $row['pi_number'] ?: '-' }}">{{ $row['pi_number'] ?: '-' }}</td>
                            <td title="{{ $row['material_type'] ?: '-' }}">{{ $row['material_type'] ?: '-' }}</td>
                            <td title="{{ $row['contract_shipment'] ?: '-' }}">{{ $row['contract_shipment'] ?: '-' }}</td>
                            <td title="{{ $row['committed_ex_mill'] ?: '-' }}">{{ $row['committed_ex_mill'] ?: '-' }}</td>
                            <td class="comments-cell" title="{{ $row['remarks'] ?: '(blank)' }}">{{ $row['remarks'] ?: '(blank)' }}</td>
                            <td class="text-end fw-bold" title="{{ number_format((float) ($row['pi_amount'] ?? 0), 2) }}">{{ number_format((float) ($row['pi_amount'] ?? 0), 2) }}</td>
                            <td class="text-end">
                                <form method="GET" action="{{ route('supply_chain.payment_requests.preview') }}" class="m-0 d-inline-block">
                                    <input type="hidden" name="booking_po_ids[]" value="{{ $row['booking_po_id'] }}">
                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill row-create-btn" title="Preview payment request approval for this PO">
                                        <i class="bi bi-eye"></i>
                                        <span class="d-none d-xl-inline">Preview</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                No pending PI payment rows found. Verify PI Number, Payment Status, and Payment Doc No headers in the workspace.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if($pendingRows->count())
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="10" class="text-end">Grand Total</th>
                            <th class="text-end">{{ number_format((float) ($kpis['total_pi_amount'] ?? 0), 2) }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="card-footer bg-white border-0">
            {{ $pendingRows->links() }}
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden" id="payment-request-list">
        <div class="card-header bg-white border-0 py-3">
            <h6 class="fw-bold mb-0">Payment Request List</h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request No</th>
                        <th>Supplier</th>
                        <th>Buyer</th>
                        <th>Season</th>
                        <th class="text-end">Total PI Amount</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Created At</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($paymentRequests as $paymentRequest)
                        <tr>
                            <td class="fw-bold">{{ $paymentRequest->request_no }}</td>
                            <td>{{ $paymentRequest->supplier_name ?: '-' }}</td>
                            <td>{{ $paymentRequest->buyer_name ?: '-' }}</td>
                            <td>{{ $paymentRequest->season_name ?: '-' }}</td>
                            <td class="text-end fw-bold">{{ number_format((float) $paymentRequest->total_pi_amount, 2) }}</td>
                            <td><span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">{{ ucfirst($paymentRequest->status) }}</span></td>
                            <td>{{ optional($paymentRequest->createdBy)->name ?: '-' }}</td>
                            <td>{{ optional($paymentRequest->created_at)->format('Y-m-d H:i') }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('supply_chain.payment_requests.show', $paymentRequest) }}" class="btn btn-sm btn-outline-primary rounded-pill">View</a>
                                <a href="{{ route('supply_chain.payment_requests.download_pdf', $paymentRequest) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger rounded-pill">PDF Preview</a>
                                <a href="{{ route('supply_chain.payment_requests.download_excel', $paymentRequest) }}" class="btn btn-sm btn-outline-success rounded-pill">Excel</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center py-4 text-muted">No payment request created yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white border-0">
            {{ $paymentRequests->links() }}
        </div>
    </div>
</div>
@endsection
