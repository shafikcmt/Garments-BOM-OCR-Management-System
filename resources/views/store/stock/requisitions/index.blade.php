@extends('layouts.app')

@section('title', 'General Stock — Purchase Requisition')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);

    // Drives the Clear button only — the filtering itself is the controller's.
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // One badge style per status, worst-to-best left to right in the flow.
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
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Purchase Requisition</h3>
                    <p class="app-hero-copy mb-0">What the departments have asked to be bought, and where each request stands.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.requisitions.report') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Monthly Report
                </a>
                {{-- One entry form covers both cases: a one-item requisition is
                     simply this form with a single line, so there is nothing for
                     a separate "Single Item" button to open. --}}
                <a href="{{ route('store.stock.requisitions.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Requisition
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5 class="mb-0">Requisitions <span class="badge bg-primary-subtle text-primary ms-1">{{ $requisitions->total() }}</span></h5>
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
                <div class="col-12 col-xl-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            {{-- No "Type" column: every requisition now comes
                                 from the one entry form, so it read "Multiple
                                 Items" on every row and only cost width. The
                                 mode is still stored and still shown on the
                                 requisition itself. --}}
                            <th style="min-width:240px;">Requisition No</th>
                            <th style="min-width:110px;">Date</th>
                            <th style="min-width:140px;">Section</th>
                            <th style="min-width:150px;">Requested By</th>
                            <th class="text-end" style="min-width:80px;">Items</th>
                            <th class="text-end" style="min-width:130px;">Total Amount</th>
                            <th style="min-width:150px;">Status</th>
                            <th class="text-end gx-stock-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requisitions as $r)
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $r->requisition_no }}</div>
                                    <div class="gx-stock-spec">raised by {{ optional($r->createdBy)->name ?: '—' }}</div>
                                </td>
                                <td class="small">{{ optional($r->requisition_date)->format('d-M-Y') ?? '—' }}</td>
                                <td class="small">{{ optional($r->indentSection)->name ?: 'All' }}</td>
                                <td class="small text-muted">{{ optional($r->indentPerson)->name ?: '—' }}</td>
                                <td class="text-end fw-semibold">{{ $r->items_count }}</td>
                                <td class="text-end fw-semibold">{{ $money($r->items_sum_amount) }}</td>
                                <td>
                                    @php([$class, $icon] = $badges[$r->status] ?? ['bg-light text-dark', 'bi-dot'])
                                    <span class="badge {{ $class }}"><i class="bi {{ $icon }} me-1" aria-hidden="true"></i>{{ $statusLabels[$r->status] ?? $r->status }}</span>
                                </td>
                                <td class="text-end gx-stock-actions">
                                    <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>View
                                    </a>
                                    @if($r->isEditable())
                                        <a href="{{ route('store.stock.requisitions.edit', $r) }}" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="gx-stock-empty">
                                <span class="gx-stock-empty-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                                <div class="gx-stock-empty-title">{{ $hasFilters ? 'No requisitions match this filter' : 'No requisitions raised yet' }}</div>
                                <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or status.' : 'Raise one with New Requisition above.' }}</div>
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
