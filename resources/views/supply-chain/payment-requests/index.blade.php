@extends('layouts.app')

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

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <div class="text-uppercase text-primary fw-bold small mb-1" style="letter-spacing:.08em;">Supply Chain</div>
            <h4 class="fw-bold mb-1">Payment Request Approval</h4>
            <p class="text-muted mb-0">Reference image/PDF format অনুযায়ী filter, approval sheet, PDF এবং Excel format সাজানো হয়েছে।</p>
        </div>
        <a href="#payment-request-list" class="btn btn-outline-primary rounded-pill">
            <i class="bi bi-list-check me-1"></i> Payment Request List
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success rounded-4 border-0 shadow-sm">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">{{ session('warning') }}</div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body"><div class="text-muted small">Total PI Amount</div><div class="fs-4 fw-bold">{{ number_format((float) ($kpis['total_pi_amount'] ?? 0), 2) }}</div></div></div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body"><div class="text-muted small">Total PO Count</div><div class="fs-4 fw-bold">{{ $kpis['total_po_count'] ?? 0 }}</div></div></div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body"><div class="text-muted small">Total Budget / Savings</div><div class="fs-5 fw-bold">{{ number_format((float) ($kpis['total_budget'] ?? 0), 2) }} <span class="text-muted">/</span> {{ number_format((float) ($kpis['total_savings'] ?? 0), 2) }}</div></div></div>
        </div>
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body"><div class="text-muted small">Earliest Payment Required</div><div class="fs-5 fw-bold">{{ $kpis['earliest_payment_required_date'] ?: '-' }}</div></div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="fw-bold mb-1">Approval Format Filters</h6>
                <div class="small text-muted">PDF reference এর মত: Shipment Month, Vendor Type, Final Status, Payment Term, Payment Status auto-suggest dropdown.</div>
            </div>
            @if(count($activeFilters))
                <span class="badge rounded-pill text-bg-primary">{{ count($activeFilters) }} Active Filter</span>
            @endif
        </div>
        <div class="card-body">
            @if(count($activeFilters))
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @foreach($activeFilters as $label => $value)
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">{{ $label }}: {{ $value }}</span>
                    @endforeach
                </div>
            @endif

            <form method="GET" action="{{ route('supply_chain.payment_requests.index') }}" class="row g-3 align-items-end">
                @foreach($filterCards as $field)
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label class="form-label small fw-bold">{{ $field['label'] }}</label>
                        <input list="{{ $field['list'] }}" name="{{ $field['name'] }}" value="{{ request($field['name']) }}" class="form-control form-control-sm rounded-3" placeholder="All">
                        <datalist id="{{ $field['list'] }}">
                            @foreach($field['options'] as $option)
                                <option value="{{ $option }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                @endforeach

                @foreach($dateFilters as $field)
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-bold">{{ $field['label'] }}</label>
                        <div class="input-group input-group-sm">
                            <input type="date" name="{{ $field['name'] }}_from" value="{{ request($field['name'] . '_from') }}" class="form-control rounded-start-3">
                            <input type="date" name="{{ $field['name'] }}_to" value="{{ request($field['name'] . '_to') }}" class="form-control rounded-end-3">
                        </div>
                    </div>
                @endforeach

                <div class="col-12 d-flex flex-wrap gap-2 pt-1">
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="bi bi-funnel me-1"></i> Apply Filter</button>
                    <a href="{{ route('supply_chain.payment_requests.index') }}" class="btn btn-light rounded-pill border px-4">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <form method="POST" action="{{ route('supply_chain.payment_requests.store') }}">
        @csrf
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4" id="pending-pi-payment">
            <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="fw-bold mb-0">Pending PI Payment</h6>
                    <div class="small text-muted">Reference approval table follow করে row select করুন।</div>
                </div>
                <button type="submit" class="btn btn-success rounded-pill"><i class="bi bi-file-earmark-plus me-1"></i> Create Payment Request Approval</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light text-nowrap">
                        <tr>
                            <th><input type="checkbox" class="form-check-input" id="selectAllPaymentRows"></th>
                            <th>Vendor Name</th>
                            <th>Style Name</th>
                            <th>Contract Shipment</th>
                            <th>Committed Ex Mill</th>
                            <th>Payment Term</th>
                            <th>Material PO Number</th>
                            <th>Material PI Number</th>
                            <th>Material Type</th>
                            <th>Payment Status</th>
                            <th>PCD Required</th>
                            <th class="text-end">Sum of PI Amount</th>
                            <th class="text-end">Budget</th>
                            <th class="text-end">Savings</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pendingRows as $row)
                            <tr>
                                <td><input type="checkbox" name="booking_po_ids[]" value="{{ $row['booking_po_id'] }}" class="form-check-input payment-row-check"></td>
                                <td>
                                    <div class="fw-semibold">{{ $row['supplier_name'] ?: '-' }}</div>
                                    <div class="text-muted small">{{ $row['buyer_name'] ?: '-' }} · {{ $row['season_name'] ?: '-' }}</div>
                                </td>
                                <td>{{ $row['style_name'] ?: '-' }}</td>
                                <td>{{ $row['contract_shipment'] ?: '-' }}</td>
                                <td>{{ $row['committed_ex_mill'] ?: '-' }}</td>
                                <td>{{ $row['payment_term'] ?: '-' }}</td>
                                <td class="fw-bold text-nowrap">{{ $row['po_no'] ?: '-' }}</td>
                                <td class="fw-bold text-nowrap">{{ $row['pi_number'] ?: '-' }}</td>
                                <td>{{ $row['material_type'] ?: '-' }}</td>
                                <td><span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle">{{ $row['payment_status'] ?: '-' }}</span></td>
                                <td>{{ $row['pcd_required'] ?: '-' }}</td>
                                <td class="text-end fw-bold">{{ number_format((float) ($row['pi_amount'] ?? 0), 2) }}</td>
                                <td class="text-end">{{ number_format((float) ($row['budget'] ?? 0), 2) }}</td>
                                <td class="text-end">{{ number_format((float) ($row['savings'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="14" class="text-center py-5 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No PI received payment pending rows found. PI Number / Payment Status / Payment Doc No headers check করুন।
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($pendingRows->count())
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="11" class="text-end">Grand Total</th>
                                <th class="text-end">{{ number_format((float) ($kpis['total_pi_amount'] ?? 0), 2) }}</th>
                                <th class="text-end">{{ number_format((float) ($kpis['total_budget'] ?? 0), 2) }}</th>
                                <th class="text-end">{{ number_format((float) ($kpis['total_savings'] ?? 0), 2) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <div class="card-footer bg-white border-0">
                {{ $pendingRows->links() }}
            </div>
        </div>
    </form>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('selectAllPaymentRows');
    const checks = document.querySelectorAll('.payment-row-check');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks.forEach((check) => check.checked = selectAll.checked);
        });
    }
});
</script>
@endsection
