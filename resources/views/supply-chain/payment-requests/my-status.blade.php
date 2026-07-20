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
    $dotClass = [
        'approved' => 'pra-dot-approved',
        'rejected' => 'pra-dot-rejected',
        'pending' => 'pra-dot-pending',
    ];
@endphp

<style>
    .pra-status-table td, .pra-status-table th { vertical-align: middle; }
    .pra-toggle { border:0; background:transparent; color:#64748b; padding:0; width:26px; height:26px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; transition:transform .15s ease, background .15s ease; }
    .pra-toggle:hover { background:#eef2f9; color:#0b1d5b; }
    .pra-toggle[aria-expanded="true"] { transform:rotate(90deg); }
    .pra-approver-chip { display:inline-flex; align-items:center; gap:5px; background:#f4f7fc; border:1px solid #e4ebf7; border-radius:999px; padding:2px 9px; font-size:11.5px; font-weight:600; color:#334155; white-space:nowrap; }
    .pra-dot { width:8px; height:8px; border-radius:50%; flex:none; }
    .pra-dot-approved { background:#16a34a; }
    .pra-dot-pending  { background:#eab308; }
    .pra-dot-rejected { background:#dc2626; }
    .pra-detail-cell { background:#f8fafc; }
    .pra-detail-row { border:1px solid #e5eaf2; border-radius:10px; background:#fff; padding:10px 14px; }
    .pra-detail-comment { color:#64748b; font-size:12.5px; }
</style>

<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Supply Chain', 'url' => route('supply_chain.dashboard')],
        ['label' => 'Payment Request'],
        ['label' => 'My PRA Status'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-list-check" aria-hidden="true"></i></span>
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

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 pra-status-table">
                <thead class="table-light">
                    <tr>
                        <th style="width:34px;"></th>
                        <th>PR No.</th>
                        <th>Buyer / Season</th>
                        <th>Vendor</th>
                        <th class="text-end">PI Amt USD</th>
                        <th>Approvers</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($paymentRequests as $pr)
                        @php $progress = $pr->approvalProgress(); $current = $pr->currentApprovals(); @endphp
                        <tr>
                            <td class="text-center">
                                <button type="button" class="pra-toggle" data-bs-toggle="collapse" data-bs-target="#praDetail{{ $pr->id }}" aria-expanded="false" aria-controls="praDetail{{ $pr->id }}" title="Show approver details">
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>
                            </td>
                            <td>
                                <a href="{{ route('supply_chain.payment_requests.show', $pr) }}" class="fw-bold text-slate-900 text-decoration-none">{{ $pr->request_no }}</a>
                            </td>
                            <td>
                                <div class="fw-semibold small text-slate-900">{{ $pr->buyer_name ?: '—' }}</div>
                                <div class="text-muted small">{{ $pr->season_name ?: '—' }}</div>
                            </td>
                            <td class="small">{{ $pr->supplier_name ?: '—' }}</td>
                            <td class="text-end fw-bold">{{ number_format((float) $pr->total_pi_amount, 2) }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @forelse($current as $approval)
                                        <span class="pra-approver-chip" title="{{ ucfirst($approval->status) }}">
                                            <span class="pra-dot {{ $dotClass[$approval->status] ?? 'pra-dot-pending' }}"></span>
                                            {{ optional($approval->approver)->name ?? '—' }}
                                        </span>
                                    @empty
                                        <span class="text-muted small">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>
                                <span class="badge rounded-pill {{ $stateBadge[$progress['state']] ?? 'bg-secondary-subtle text-secondary' }}">{{ $progress['label'] }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                @if($progress['state'] === 'rejected')
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#resubmitModal{{ $pr->id }}">
                                        <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Resubmit
                                    </button>
                                @else
                                    <a href="{{ route('supply_chain.payment_requests.show', $pr) }}" class="btn btn-sm btn-outline-primary rounded-pill px-3">View</a>
                                @endif
                            </td>
                        </tr>

                        {{-- Expandable approver-wise detail --}}
                        <tr class="collapse" id="praDetail{{ $pr->id }}">
                            <td colspan="8" class="pra-detail-cell">
                                <div class="row g-2 py-1">
                                    @foreach($current as $approval)
                                        <div class="col-12 col-md-6 col-xl-4">
                                            <div class="pra-detail-row d-flex justify-content-between align-items-start gap-2 h-100">
                                                <div class="min-w-0">
                                                    <div class="fw-semibold small text-slate-900">{{ optional($approval->approver)->name ?? '—' }}</div>
                                                    @if($approval->comment)
                                                        <div class="pra-detail-comment">{{ $approval->comment }}</div>
                                                    @else
                                                        <div class="pra-detail-comment fst-italic">No comment</div>
                                                    @endif
                                                </div>
                                                <span class="badge rounded-pill {{ $decisionBadge[$approval->status] ?? 'bg-secondary-subtle text-secondary' }}">{{ ucfirst($approval->status) }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>

                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-3 d-block mb-2" aria-hidden="true"></i>
                                You have not sent any PRA for approval yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white border-0">
            {{ $paymentRequests->links() }}
        </div>
    </div>
</div>

{{-- Resubmit modals (kept outside the table for valid markup) --}}
@foreach($paymentRequests as $pr)
    @if($pr->approvalProgress()['state'] === 'rejected')
        <div class="modal fade" id="resubmitModal{{ $pr->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border-radius:var(--gx-radius);">
                    <form method="POST" action="{{ route('supply_chain.payment_requests.resubmit', $pr) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Resubmit {{ $pr->request_no }}</h5>
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
                                <i class="bi bi-send me-1" aria-hidden="true"></i> Resubmit
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach
@endsection
