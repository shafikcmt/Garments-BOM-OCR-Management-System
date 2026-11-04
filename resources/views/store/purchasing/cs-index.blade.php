@extends('layouts.app')

@section('title', 'General Stock — Comparative Statements')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[['label' => 'General Stock'], ['label' => 'Purchasing'], ['label' => 'Comparative Statements']]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="columns-gap" eyebrow="General Stock Purchasing" title="Comparative Statements"
                   copy="Supplier rates compared item by item. An approved statement is what the purchase order is made from.">
        <x-slot:actions>
            @if($canCreate)
                <a href="{{ route('store.purchasing.queue') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Comparative Statement
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @include('store.purchasing._nav')

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <form method="GET" class="row g-3 gx-stock-filter mb-3">
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="statusFilter">Status</label>
                    <select name="status" id="statusFilter" class="form-select">
                        <option value="">All</option>
                        @foreach($statusLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="CS no, item or supplier">
                </div>
                <div class="col-12 col-xl-3 gx-stock-filter-actions">
                    <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if(array_filter($filters))
                        <a href="{{ route('store.purchasing.cs.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:150px;">CS No</th>
                            <th style="min-width:110px;">Date</th>
                            <th class="text-end" style="min-width:70px;">Items</th>
                            <th style="min-width:260px;">Suppliers compared</th>
                            <th style="min-width:150px;">Made by</th>
                            <th style="min-width:170px;">Status</th>
                            <th style="min-width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($statements as $cs)
                            <tr>
                                <td class="fw-semibold">{{ $cs->cs_no }}</td>
                                <td>{{ $cs->cs_date?->format('d-M-Y') }}</td>
                                <td class="text-end">{{ $cs->items_count }}</td>
                                <td>{{ $cs->suppliers->pluck('name')->implode(', ') }}</td>
                                <td>{{ $cs->creator?->name }}</td>
                                <td>@include('store.purchasing._badge', ['status' => $cs->status, 'label' => $cs->statusLabel()])</td>
                                <td class="text-end">
                                    <a href="{{ route('store.purchasing.cs.show', $cs) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1" aria-hidden="true"></i>View</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No Comparative Statements yet. Start one from the To Purchase list.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $statements->links() }}
        </div>
    </div>
</div>
@endsection
