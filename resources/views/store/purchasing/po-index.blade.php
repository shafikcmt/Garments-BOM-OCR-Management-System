@extends('layouts.app')

@section('title', 'General Stock — Internal POs')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[['label' => 'General Stock'], ['label' => 'Purchasing'], ['label' => 'Internal POs']]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="file-earmark-text" eyebrow="General Stock Purchasing" title="Internal POs"
                   copy="Purchase orders made from approved Comparative Statements. Store picks the PO number when goods arrive." />

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
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="PO no, supplier or item">
                </div>
                <div class="col-12 col-xl-3 gx-stock-filter-actions">
                    <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if(array_filter($filters))
                        <a href="{{ route('store.purchasing.po.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:170px;">PO No</th>
                            <th style="min-width:110px;">Date</th>
                            <th style="min-width:220px;">Supplier</th>
                            <th style="min-width:140px;">CS No</th>
                            <th class="text-end" style="min-width:70px;">Items</th>
                            <th class="text-end" style="min-width:120px;">Amount</th>
                            <th style="min-width:150px;">Status</th>
                            <th style="min-width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($orders as $po)
                            <tr>
                                <td class="fw-semibold">{{ $po->po_no }}</td>
                                <td>{{ $po->po_date?->format('d-M-Y') }}</td>
                                <td>{{ $po->supplier_name }}</td>
                                <td>{{ $po->statement?->cs_no }}</td>
                                <td class="text-end">{{ $po->items_count }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format((float) $po->total_amount, 2), '0'), '.') }}</td>
                                <td>@include('store.purchasing._badge', ['status' => $po->status, 'label' => $po->statusLabel()])</td>
                                <td class="text-end"><a href="{{ route('store.purchasing.po.show', $po) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1" aria-hidden="true"></i>View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No purchase orders yet. Make one from an approved Comparative Statement.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $orders->links() }}
        </div>
    </div>
</div>
@endsection
