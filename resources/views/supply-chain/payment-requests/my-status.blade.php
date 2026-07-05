@extends('layouts.app')

@section('title', 'My PRA Status')

@section('content')
@php
    $stateBadge = [
        'approved' => 'bg-success-subtle text-success',
        'rejected' => 'bg-danger-subtle text-danger',
        'pending_approval' => 'bg-warning-subtle text-warning-emphasis',
        'pending_check' => 'bg-info-subtle text-info-emphasis',
        'none' => 'bg-secondary-subtle text-secondary',
    ];
    $decisionBadge = [
        'approved' => 'bg-success-subtle text-success',
        'rejected' => 'bg-danger-subtle text-danger',
        'pending' => 'bg-warning-subtle text-warning-emphasis',
    ];
@endphp
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-list-check"></i></span>
            <div>
                <div class="app-hero-eyebrow">Payment Request</div>
                <h3 class="app-hero-title mb-0">My PRA Status</h3>
            </div>
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
        @endif
    @endforeach

    <div class="d-flex flex-column gap-3">
        @forelse($paymentRequests as $pr)
            @php $progress = $pr->approvalProgress(); $current = $pr->currentApprovals(); @endphp
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <a href="{{ route('supply_chain.payment_requests.show', $pr) }}" class="fw-bold text-slate-900 text-decoration-none fs-6">{{ $pr->request_no }}</a>
                            <div class="small text-muted">{{ $pr->buyer_name ?: '—' }} · {{ $pr->supplier_name ?: '—' }} · $ {{ number_format((float) $pr->total_pi_amount, 2) }}</div>
                        </div>
                        <span class="badge rounded-pill {{ $stateBadge[$progress['state']] ?? 'bg-secondary-subtle text-secondary' }}">{{ $progress['label'] }}</span>
                    </div>

                    <div class="row g-2">
                        @foreach($current as $approval)
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="d-flex justify-content-between align-items-start gap-2 border rounded-3 px-3 py-2">
                                    <div class="min-w-0">
                                        <div class="fw-semibold small text-slate-900">{{ optional($approval->approver)->name ?? '—' }}</div>
                                        @if($approval->comment)<div class="small text-muted">{{ $approval->comment }}</div>@endif
                                    </div>
                                    <span class="badge rounded-pill {{ $decisionBadge[$approval->status] ?? 'bg-secondary-subtle text-secondary' }}">{{ ucfirst($approval->status) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if($progress['state'] === 'rejected')
                        <div class="mt-3">
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#resubmitModal{{ $pr->id }}">
                                <i class="bi bi-arrow-repeat me-1"></i> Resubmit for Approval
                            </button>
                        </div>

                        {{-- Resubmit modal --}}
                        <div class="modal fade" id="resubmitModal{{ $pr->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content" style="border-radius:14px;">
                                    <form method="POST" action="{{ route('supply_chain.payment_requests.resubmit', $pr) }}">
                                        @csrf
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="bi bi-arrow-repeat me-1"></i> Resubmit {{ $pr->request_no }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="small text-muted mb-3">Select approver(s) for a fresh approval cycle. The previous cycle stays in the history.</p>
                                            @if($approverPool->isEmpty())
                                                <div class="alert alert-warning small mb-0">No active approvers available. Ask the admin to add approvers.</div>
                                            @else
                                                @if(($checkerPool ?? collect())->isNotEmpty())
                                                    <label class="form-label fw-semibold">Send for check to <span class="text-muted small fw-normal">(optional)</span></label>
                                                    <select name="checker_id" class="form-select mb-3">
                                                        <option value="">— No checker (send straight to approvers) —</option>
                                                        @foreach($checkerPool as $checker)
                                                            <option value="{{ $checker->id }}">{{ $checker->name }} ({{ $checker->email }})</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                                <label class="form-label fw-semibold">Send for approval to <span class="text-danger">*</span></label>
                                                <div class="d-flex flex-column gap-2" style="max-height:240px;overflow-y:auto;">
                                                    @foreach($approverPool as $approver)
                                                        <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2 mb-0" style="cursor:pointer;">
                                                            <input type="checkbox" class="form-check-input mt-0" name="approver_ids[]" value="{{ $approver->id }}">
                                                            <span>{{ $approver->name }} <span class="text-muted small">({{ $approver->email }})</span></span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                                <label class="form-label fw-semibold mt-3">Remarks <span class="text-muted small">(optional)</span></label>
                                                <textarea name="remarks" rows="2" class="form-control" maxlength="2000">{{ $pr->remarks }}</textarea>
                                            @endif
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary" {{ $approverPool->isEmpty() ? 'disabled' : '' }}>
                                                <i class="bi bi-send me-1"></i> Resubmit
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-5 text-center text-muted">
                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                    You have not sent any PRA for approval yet.
                </div>
            </div>
        @endforelse
    </div>

    <div class="mt-3">{{ $paymentRequests->links() }}</div>
</div>
@endsection
