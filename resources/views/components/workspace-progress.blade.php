{{--
    "How much of the BOM is waiting on you" panel.

    Every column in the workspace declares an owner role, so each role's share
    of the sheet is countable. Shared by the roles that have no module of their
    own (merchant, commercial, account) and by supply chain.

    Props: workspace — the array from DepartmentActivityService::forRole(), which
    now feeds this card so a department sees the same figure the Admin Dashboard
    reports for it. Scoped to REQUIRED, ACTIVE columns only.
--}}
@props(['workspace' => [], 'role' => null])

@php
    $percent = (float) ($workspace['percent'] ?? 0);
    $tone = $percent >= 75 ? 'success' : ($percent >= 40 ? 'warning' : 'danger');
    $status = $workspace['status'] ?? null;
@endphp

<div {{ $attributes->merge(['class' => 'gx-tone-'.$tone]) }}>
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-2">
        <div>
            <span class="gx-stat-value">{{ $percent }}%</span>
            <span class="gx-stat-label d-inline ms-1">complete</span>
            @if($status)
                <span class="badge {{ \App\Services\DepartmentActivityService::statusTone($status) }} ms-1">
                    {{ \App\Services\DepartmentActivityService::statusLabel($status) }}
                </span>
            @endif
        </div>
        <div class="small text-muted">
            {{ number_format($workspace['filled'] ?? 0) }} of {{ number_format($workspace['expected'] ?? 0) }} fields
        </div>
    </div>

    <div class="progress" style="height:10px; border-radius:var(--gx-radius-pill);"
         role="progressbar" aria-label="Workspace completion for this role"
         aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar" style="width: {{ $percent }}%; background: var(--gx-tone);"></div>
    </div>

    <div class="row g-2 mt-3">
        <div class="col-4">
            <div class="small text-muted">Required columns</div>
            <div class="fw-bold">{{ number_format($workspace['fields'] ?? 0) }}</div>
        </div>
        <div class="col-4">
            <div class="small text-muted">BOM lines</div>
            <div class="fw-bold">{{ number_format($workspace['rows'] ?? 0) }}</div>
        </div>
        <div class="col-4">
            <div class="small text-muted">Still to fill</div>
            <div class="fw-bold text-danger">{{ number_format($workspace['pending'] ?? 0) }}</div>
        </div>
    </div>
</div>
