@extends('layouts.app')

@section('title', 'General Stock — Requested Requisitions')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // Same badge set as the main list, so one status never reads two ways.
    $badges = [
        'draft' => ['bg-secondary-subtle text-secondary-emphasis', 'bi-pencil'],
        'submitted' => ['bg-primary-subtle text-primary', 'bi-send'],
        'store_reviewed' => ['bg-info-subtle text-info-emphasis', 'bi-clipboard-check'],
        'approved' => ['bg-success-subtle text-success', 'bi-check2-circle'],
        'purchase_action_taken' => ['bg-dark-subtle text-dark', 'bi-cart-check'],
    ];
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => \App\Models\PurchaseRequisition::seesEveryRequisition(auth()->user()) ? route('store.dashboard') : null],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Requested'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="inbox" eyebrow="General Stock" title="Requested Requisitions"
                   copy="What the departments have sent to Store. Mark each one received, then approve it to go forward.">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All Requisitions
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    {{-- Two tabs over one list. The queue is a separate screen rather than a
         status filter because it answers a different question: not "what has
         been raised" but "what is waiting on me". --}}
    @include('store.stock.requisitions._tabs', ['active' => 'requested'])

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5 class="mb-0">
                    From the departments
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ $requisitions->total() }}</span>
                </h5>
            </div>

            <form method="GET" class="row g-3 gx-stock-filter mb-4">
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="month">Month</label>
                    <input type="month" name="month" id="month" value="{{ $filters['month'] ?? '' }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="statusFilter">Status</label>
                    <select name="status" id="statusFilter" class="form-select">
                        <option value="">All</option>
                        @foreach($statusLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="sectionFilter">Section</label>
                    <select name="section" id="sectionFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($sections as $s)
                            <option value="{{ $s->id }}" @selected(($filters['section'] ?? null) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Requisition no, requester or item">
                </div>
                <div class="col-12 col-xl-2 gx-stock-filter-actions">
                    <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.requisitions.requested') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:240px;">Requisition No</th>
                            <th style="min-width:110px;">Date</th>
                            <th style="min-width:140px;">Section</th>
                            <th style="min-width:150px;">Raised By</th>
                            <th class="text-end" style="min-width:80px;">Items</th>
                            <th class="text-end" style="min-width:130px;">Total Amount</th>
                            <th style="min-width:150px;">Status</th>
                            <th class="text-end gx-stock-actions" style="min-width:280px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requisitions as $r)
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $r->requisition_no }}</div>
                                    @if($r->store_ack_at)
                                        <div class="gx-stock-spec">received {{ $r->store_ack_at->format('d-M-Y') }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ optional($r->requisition_date)->format('d-M-Y') ?? '—' }}</td>
                                <td class="small">{{ optional($r->indentSection)->name ?: 'All' }}</td>
                                <td class="small">
                                    {{ optional($r->createdBy)->name ?: '—' }}
                                    @if($r->department_role)
                                        <div class="gx-stock-spec">{{ $r->departmentLabel() }}@if($r->department_checked_at) · head checked {{ $r->department_checked_at->format('d-M-Y') }}@endif</div>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">{{ $r->items_count }}</td>
                                <td class="text-end fw-semibold">{{ $money($r->items_sum_amount) }}</td>
                                <td>
                                    @php([$class, $icon] = $badges[$r->status] ?? ['bg-light text-dark', 'bi-dot'])
                                    <span class="badge {{ $class }}"><i class="bi {{ $icon }} me-1" aria-hidden="true"></i>{{ $r->progressLabel() }}</span>
                                </td>
                                <td class="text-end gx-stock-actions">
                                    <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>View
                                    </a>
                                    {{-- Print and download are the existing
                                         single-requisition routes, reachable
                                         from the queue as well as the list. --}}
                                    <a href="{{ route('store.stock.requisitions.pdf', $r) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                                    </a>
                                    {{-- Correct the sheet before it goes any
                                         further. Opens the ordinary requisition
                                         edit form — there is no second edit UI —
                                         and is offered only while the Store's
                                         correction window is open and only to
                                         someone holding the correction
                                         permission, which is the same pair the
                                         controller checks on the way in. --}}
                                    @if($canEdit && $r->isOpenToStoreCorrection())
                                        <a href="{{ route('store.stock.requisitions.edit', $r) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                        </a>
                                    @endif
                                    @include('store.stock.requisitions._review-actions', ['requisition' => $r, 'small' => true])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="gx-stock-empty">
                                <span class="gx-stock-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                                <div class="gx-stock-empty-title">{{ $hasFilters ? 'Nothing matches this filter' : 'No department requests yet' }}</div>
                                <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or status.' : 'Requisitions raised outside the Store appear here as soon as they are submitted.' }}</div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $requisitions->links() }}</div>
        </div>
    </div>

    @include('store.stock._searchable')
</div>
@endsection
