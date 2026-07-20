@extends('layouts.app')

@section('title', 'Management Dashboard')

@section('content')
@php
    $statusBadge = [
        'approved' => 'bg-success-subtle text-success',
        'rejected' => 'bg-danger-subtle text-danger',
        'pending' => 'bg-warning-subtle text-warning-emphasis',
    ];
@endphp
<div class="container-fluid">
    <div class="app-hero-card p-4 p-lg-5 mb-4">
        <div class="app-hero-layout d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="app-hero-main d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:52px;height:52px;border-radius:18px;font-size:22px;"><i class="bi bi-clipboard2-check"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Management</div>
                    <h2 class="app-hero-title">Welcome, {{ auth()->user()->name }}</h2>
                    <p class="app-hero-copy mb-0">Review pending Payment Request Approvals and track approval status.</p>
                </div>
            </div>
            <a href="{{ route('pra_approvals.index') }}" class="app-hero-action btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                <i class="bi bi-inbox"></i>Pending PRA Approvals
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon" style="background:#fff4d6;color:#b7791f;"><i class="bi bi-hourglass-split"></i></span>
                    <div>
                        <div class="app-stat-label">Awaiting Me</div>
                        <div class="fs-4 fw-bold text-slate-900">{{ $myPending }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon" style="background:#e5edff;color:#3454d1;"><i class="bi bi-clock-history"></i></span>
                    <div>
                        <div class="app-stat-label">Pending (All)</div>
                        <div class="fs-4 fw-bold text-slate-900">{{ $stats['pending'] }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon" style="background:#d9f7e5;color:#1a7f47;"><i class="bi bi-check2-circle"></i></span>
                    <div>
                        <div class="app-stat-label">Approved</div>
                        <div class="fs-4 fw-bold text-slate-900">{{ $stats['approved'] }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center gap-3">
                    <span class="app-stat-icon" style="background:#fde2e1;color:#c0392b;"><i class="bi bi-x-circle"></i></span>
                    <div>
                        <div class="app-stat-label">Rejected</div>
                        <div class="fs-4 fw-bold text-slate-900">{{ $stats['rejected'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <h5 class="mb-3">Recent Approval Activity</h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th>PRA</th>
                            <th>Approver</th>
                            <th class="text-center">Decision</th>
                            <th>Comment</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentActivity as $activity)
                            <tr>
                                <td class="fw-bold text-slate-900">{{ optional($activity->paymentRequest)->request_no ?? '—' }}</td>
                                <td>{{ optional($activity->approver)->name ?? '—' }}</td>
                                <td class="text-center">
                                    <span class="badge rounded-pill {{ $statusBadge[$activity->status] ?? 'bg-secondary-subtle text-secondary' }}">{{ ucfirst($activity->status) }}</span>
                                </td>
                                <td class="small">{{ $activity->comment ?: '—' }}</td>
                                <td class="small text-muted">{{ optional($activity->acted_at)->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-5">No approval activity yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
