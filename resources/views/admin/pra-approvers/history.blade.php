@extends('layouts.app')

@section('title', 'PRA Approval History')

@section('content')
@php
    $badge = [
        'approved' => 'bg-success-subtle text-success',
        'rejected' => 'bg-danger-subtle text-danger',
        'pending' => 'bg-warning-subtle text-warning-emphasis',
    ];
@endphp
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-clock-history"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / PRA Approval</div>
                    <h3 class="app-hero-title mb-0">Approval History</h3>
                </div>
            </div>
            <a href="{{ route('admin.pra-approvers.index') }}" class="btn btn-outline-primary rounded-3">
                <i class="bi bi-person-check me-1"></i> Manage Approvers
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th>PRA</th>
                            <th>Created By</th>
                            <th class="text-center">Cycle</th>
                            <th>Approver</th>
                            <th class="text-center">Decision</th>
                            <th>Comment</th>
                            <th>Acted</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($approvals as $approval)
                            <tr>
                                <td class="fw-bold text-slate-900">{{ optional($approval->paymentRequest)->request_no ?? '—' }}</td>
                                <td class="small text-muted">{{ optional(optional($approval->paymentRequest)->createdBy)->name ?? '—' }}</td>
                                <td class="text-center">{{ $approval->cycle }}</td>
                                <td>{{ optional($approval->approver)->name ?? '—' }}</td>
                                <td class="text-center">
                                    <span class="badge rounded-pill {{ $badge[$approval->status] ?? 'bg-secondary-subtle text-secondary' }}">{{ ucfirst($approval->status) }}</span>
                                </td>
                                <td class="small">{{ $approval->comment ?: '—' }}</td>
                                <td class="small text-muted">{{ $approval->acted_at ? $approval->acted_at->format('d M Y, H:i') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-5">No approval records yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $approvals->links() }}</div>
        </div>
    </div>
</div>
@endsection
