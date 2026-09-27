@extends('layouts.app')

@section('title', 'General Stock — Department Review')

@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Department Review'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="person-check" eyebrow="General Stock" title="Department Review"
                   copy="Requisitions your department has raised and that need your check before they go to Store.">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    @if($errors->has('note'))
        <div class="alert alert-danger border-0 shadow-sm rounded-3">{{ $errors->first('note') }}</div>
    @endif

    @include('store.stock.requisitions._tabs', ['active' => 'department'])

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5 class="mb-0">
                    Waiting for your check
                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ $requisitions->total() }}</span>
                </h5>
            </div>

            <form method="GET" class="row g-3 gx-stock-filter mb-4">
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="month">Month</label>
                    <input type="month" name="month" id="month" value="{{ $filters['month'] ?? '' }}" class="form-control">
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Requisition no, requester or item">
                </div>
                <div class="col-12 col-xl-2 gx-stock-filter-actions">
                    <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.requisitions.department-review') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:240px;">Requisition No</th>
                            <th style="min-width:110px;">Date</th>
                            <th style="min-width:140px;">Department</th>
                            <th style="min-width:150px;">Raised By</th>
                            <th class="text-end" style="min-width:80px;">Items</th>
                            <th class="text-end" style="min-width:130px;">Total Amount</th>
                            <th class="text-end gx-stock-actions" style="min-width:300px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requisitions as $r)
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $r->requisition_no }}</div>
                                    <div class="gx-stock-spec">submitted {{ $r->submitted_at?->format('d-M-Y') ?: '—' }}</div>
                                </td>
                                <td class="small">{{ optional($r->requisition_date)->format('d-M-Y') ?? '—' }}</td>
                                <td class="small">{{ $r->departmentLabel() }}</td>
                                <td class="small">{{ optional($r->createdBy)->name ?: '—' }}</td>
                                <td class="text-end fw-semibold">{{ $r->items_count }}</td>
                                <td class="text-end fw-semibold">{{ $money($r->items_sum_amount) }}</td>
                                <td class="text-end gx-stock-actions">
                                    <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>View
                                    </a>
                                    <a href="{{ route('store.stock.requisitions.pdf', $r) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                                    </a>
                                    @include('store.stock.requisitions._review-actions', ['requisition' => $r, 'small' => true])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="gx-stock-empty">
                                <span class="gx-stock-empty-icon"><i class="bi bi-person-check" aria-hidden="true"></i></span>
                                <div class="gx-stock-empty-title">{{ $hasFilters ? 'Nothing matches this filter' : 'Nothing waiting for your check' }}</div>
                                <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or search.' : 'Requisitions your department submits appear here until you approve or return them.' }}</div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $requisitions->links() }}</div>
        </div>
    </div>
</div>
@endsection
