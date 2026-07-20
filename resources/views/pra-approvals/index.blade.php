@extends('layouts.app')

@section('title', 'Pending PRA Approvals')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'PRA Approvals'],
        ['label' => 'Pending'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-inbox" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Approvals</div>
                <h3 class="app-hero-title mb-0">Pending PRA Approvals</h3>
            </div>
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
        @endif
    @endforeach

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th>PRA Number</th>
                            <th>Requested By</th>
                            <th>Buyer / Supplier</th>
                            <th class="text-end">Total PI Amount</th>
                            <th class="text-center">Progress</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pendingRequests as $pr)
                            @php $progress = $pr->approvalProgress(); @endphp
                            <tr>
                                <td class="fw-bold text-slate-900">{{ $pr->request_no }}</td>
                                <td class="small text-muted">{{ optional($pr->createdBy)->name ?? '—' }}</td>
                                <td class="small">
                                    <div>{{ $pr->buyer_name ?: '—' }}</div>
                                    <div class="text-muted">{{ $pr->supplier_name ?: '—' }}</div>
                                </td>
                                <td class="text-end fw-bold">$ {{ number_format((float) $pr->total_pi_amount, 2) }}</td>
                                <td class="text-center"><span class="badge rounded-pill bg-warning-subtle text-warning-emphasis">{{ $progress['label'] }}</span></td>
                                <td class="text-end">
                                    <a href="{{ route('pra_approvals.show', $pr) }}" class="btn btn-sm btn-primary rounded-pill px-3">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i> Review
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-check2-circle fs-3 d-block mb-2 text-success" aria-hidden="true"></i>
                                No PRA is waiting for your approval.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
