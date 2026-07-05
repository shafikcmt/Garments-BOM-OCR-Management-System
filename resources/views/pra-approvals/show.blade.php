@extends('layouts.app')

@section('title', 'Review PRA ' . $paymentRequest->request_no)

@section('content')
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $statusBadge = [
        'approved' => 'bg-success-subtle text-success',
        'rejected' => 'bg-danger-subtle text-danger',
        'pending' => 'bg-warning-subtle text-warning-emphasis',
    ];
    $canAct = $myApproval && $myApproval->isPending() && $paymentRequest->status === \App\Models\PaymentRequest::STATUS_PENDING_APPROVAL;
    $requiredDate = data_get($paymentRequest->data, 'payment_required_date');
@endphp
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-file-earmark-check"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Approvals / Review</div>
                    <h3 class="app-hero-title mb-0">{{ $paymentRequest->request_no }}</h3>
                </div>
            </div>
            <a href="{{ route('pra_approvals.index') }}" class="btn btn-outline-primary rounded-3">← Back to list</a>
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
        @endif
    @endforeach

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            {{-- PRA summary --}}
            <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <h5 class="mb-0">Request Details</h5>
                        <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis">{{ $progress['label'] }}</span>
                    </div>
                    <div class="row g-3 small">
                        <div class="col-6 col-md-4"><div class="text-muted">Requested By</div><div class="fw-semibold">{{ optional($paymentRequest->createdBy)->name ?? '—' }}</div></div>
                        <div class="col-6 col-md-4"><div class="text-muted">Created</div><div class="fw-semibold">{{ optional($paymentRequest->created_at)->format('d M Y') }}</div></div>
                        <div class="col-6 col-md-4"><div class="text-muted">Payment Required</div><div class="fw-semibold">{{ $requiredDate ? \Illuminate\Support\Carbon::parse($requiredDate)->format('d M Y') : '—' }}</div></div>
                        <div class="col-6 col-md-4"><div class="text-muted">Buyer</div><div class="fw-semibold">{{ $paymentRequest->buyer_name ?: '—' }}</div></div>
                        <div class="col-6 col-md-4"><div class="text-muted">Supplier / Vendor</div><div class="fw-semibold">{{ $paymentRequest->supplier_name ?: '—' }}</div></div>
                        <div class="col-6 col-md-4"><div class="text-muted">Season</div><div class="fw-semibold">{{ $paymentRequest->season_name ?: '—' }}</div></div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-end">
                        <div class="text-end">
                            <div class="text-muted small">Total PI Amount</div>
                            <div class="fs-4 fw-bold text-slate-900">$ {{ $money($paymentRequest->total_pi_amount) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Line items --}}
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Items ({{ $paymentRequest->items->count() }})</h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Vendor</th><th>Style</th><th>PO No.</th><th>PI No.</th><th>Material</th><th class="text-end">PI Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($paymentRequest->items as $item)
                                    <tr>
                                        <td class="small">{{ $item->supplier_name ?: '—' }}</td>
                                        <td class="small">{{ $item->style_name ?: '—' }}</td>
                                        <td class="small">{{ $item->po_no ?: '—' }}</td>
                                        <td class="small">{{ $item->pi_number ?: '—' }}</td>
                                        <td class="small">{{ $item->material_description ?: (data_get($item->data, 'material_type') ?: '—') }}</td>
                                        <td class="small text-end fw-semibold">$ {{ $money($item->pi_amount) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-4">No items.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Decision panel --}}
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Approvers ({{ $progress['approved'] }}/{{ $progress['total'] }} approved)</h5>
                    <ul class="list-unstyled mb-0">
                        @foreach($currentApprovals as $approval)
                            <li class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom">
                                <div class="min-w-0">
                                    <div class="fw-semibold text-slate-900">{{ optional($approval->approver)->name ?? '—' }}</div>
                                    @if($approval->comment)<div class="small text-muted">{{ $approval->comment }}</div>@endif
                                </div>
                                <span class="badge rounded-pill {{ $statusBadge[$approval->status] ?? 'bg-secondary-subtle text-secondary' }}">{{ ucfirst($approval->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            @if($canAct)
                <div class="card border-0 shadow-sm" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h5 class="mb-3">Your Decision</h5>
                        <button type="button" class="btn btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#approveModal">
                            <i class="bi bi-check2-circle me-1"></i> Approve
                        </button>
                        <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="bi bi-x-circle me-1"></i> Reject
                        </button>
                        <p class="form-text mb-0 mt-2">All selected approvers must approve before this PRA is finalised. A single rejection rejects the PRA.</p>
                    </div>
                </div>
            @elseif($myApproval)
                <div class="card border-0 shadow-sm" style="border-radius:14px;">
                    <div class="card-body p-4 text-center">
                        <span class="badge rounded-pill {{ $statusBadge[$myApproval->status] ?? 'bg-secondary-subtle text-secondary' }} mb-2">You: {{ ucfirst($myApproval->status) }}</span>
                        <p class="text-muted small mb-0">No further action is required from you.</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@if($canAct)
{{-- Approve modal --}}
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <form method="POST" action="{{ route('pra_approvals.approve', $paymentRequest) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-check2-circle text-success me-1"></i> Confirm Approval</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">You are approving PRA <strong>{{ $paymentRequest->request_no }}</strong>. This action is recorded against your name.</p>
                    <label class="form-label fw-semibold">Comment <span class="text-muted small">(optional)</span></label>
                    <textarea name="comment" rows="3" class="form-control" maxlength="2000" placeholder="Add a remark (optional)">{{ old('comment') }}</textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i> Confirm Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Reject modal --}}
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;">
            <form method="POST" action="{{ route('pra_approvals.reject', $paymentRequest) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-x-circle text-danger me-1"></i> Confirm Rejection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Rejecting PRA <strong>{{ $paymentRequest->request_no }}</strong> stops the approval process. The creator will be notified with your reason.</p>
                    <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <textarea name="comment" rows="3" class="form-control" maxlength="2000" required placeholder="State the reason for rejection">{{ old('comment') }}</textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i> Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
