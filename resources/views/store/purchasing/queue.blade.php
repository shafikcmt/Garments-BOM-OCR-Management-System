@extends('layouts.app')

@section('title', 'General Stock — To Purchase')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[['label' => 'General Stock'], ['label' => 'Purchasing'], ['label' => 'To Purchase']]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="cart-plus" eyebrow="General Stock Purchasing" title="To Purchase"
                   copy="Items from approved requisitions that are not on a Comparative Statement yet. Tick the items, then make a Comparative Statement." />

    <x-flash />
    @include('store.purchasing._nav')

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <form method="GET" class="row g-3 gx-stock-filter mb-3">
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="typeFilter">Requisition type</label>
                    <select name="type" id="typeFilter" class="form-select">
                        <option value="">All</option>
                        <option value="final" @selected(($filters['type'] ?? '') === 'final')>Final (monthly)</option>
                        <option value="individual" @selected(($filters['type'] ?? '') === 'individual')>Individual</option>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="q" id="search" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Item, specification or requisition no">
                </div>
                <div class="col-12 col-xl-3 gx-stock-filter-actions">
                    <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if(array_filter($filters))
                        <a href="{{ route('store.purchasing.queue') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            @if($total === 0)
                <div class="gx-stock-empty text-center py-5">
                    <div class="gx-stock-empty-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                    <div class="gx-stock-empty-title">Nothing waiting to be purchased</div>
                    <div class="gx-stock-empty-hint">Items appear here once a Final or Individual requisition is fully approved.</div>
                </div>
            @else
                <form method="GET" action="{{ route('store.purchasing.cs.create') }}" id="queueForm">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 px-3 py-2 border rounded bg-body-tertiary">
                        <span class="small"><strong>{{ number_format($total) }}</strong> item(s) waiting.</span>
                        <span class="small text-muted ms-auto" data-count>0 selected</span>
                        @if($canCreate)
                            <button type="submit" class="btn btn-sm btn-primary" data-submit disabled>
                                <i class="bi bi-columns-gap me-1" aria-hidden="true"></i>Make Comparative Statement
                            </button>
                        @endif
                    </div>

                    @foreach($lines as $requisitionId => $items)
                        @php($req = $items->first()->requisition)
                        <div class="table-responsive mb-4">
                            <table class="table align-middle gx-stock-table mb-0">
                                <thead>
                                    <tr class="table-light">
                                        <th colspan="6">
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                @if($canCreate)
                                                    <input type="checkbox" class="form-check-input" data-group="{{ $requisitionId }}" aria-label="Select every item of {{ $req->requisition_no }}">
                                                @endif
                                                <span class="fw-semibold">{{ $req->requisition_no }}</span>
                                                <span class="badge bg-primary-subtle text-primary">{{ $req->isIndividual() ? 'Individual' : 'Final' }}</span>
                                                <span class="small text-muted">Approved {{ $req->approved_at?->format('d-M-Y') ?? $req->requisition_date?->format('d-M-Y') }}</span>
                                            </div>
                                        </th>
                                    </tr>
                                    <tr>
                                        @if($canCreate)<th style="width:40px;"></th>@endif
                                        <th style="min-width:240px;">Item</th>
                                        <th style="min-width:160px;">Specification</th>
                                        <th style="min-width:70px;">UoM</th>
                                        <th class="text-end" style="min-width:110px;">Qty to buy</th>
                                        <th class="text-end" style="min-width:120px;">Last rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($items as $line)
                                        <tr>
                                            @if($canCreate)
                                                <td><input type="checkbox" class="form-check-input" name="lines[]" value="{{ $line->id }}" data-line data-group-id="{{ $requisitionId }}" aria-label="Select {{ $line->displayName() }}"></td>
                                            @endif
                                            <td class="fw-semibold">{{ $line->displayName() }}
                                                @if($line->isCustomItem())<span class="badge bg-warning-subtle text-warning-emphasis ms-1">Not in Item Master</span>@endif
                                            </td>
                                            <td>{{ $line->specification }}</td>
                                            <td>{{ $line->uom_snapshot ?: $line->stockItem?->uom }}</td>
                                            <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->outstanding_qty, 4), '0'), '.') }}</td>
                                            <td class="text-end">{{ $line->last_purchase_rate_snapshot !== null ? rtrim(rtrim(number_format((float) $line->last_purchase_rate_snapshot, 2), '0'), '.') : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </form>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var form = document.getElementById('queueForm');
    if (!form) return;
    var boxes = form.querySelectorAll('[data-line]');
    var count = form.querySelector('[data-count]');
    var submit = form.querySelector('[data-submit]');
    function refresh() {
        var n = form.querySelectorAll('[data-line]:checked').length;
        count.textContent = n + ' selected';
        if (submit) submit.disabled = n === 0;
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });
    form.querySelectorAll('[data-group]').forEach(function (g) {
        g.addEventListener('change', function () {
            form.querySelectorAll('[data-group-id="' + g.dataset.group + '"]').forEach(function (b) { b.checked = g.checked; });
            refresh();
        });
    });
    refresh();
})();
</script>
@endsection
