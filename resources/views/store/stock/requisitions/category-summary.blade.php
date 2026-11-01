@extends('layouts.app')

@section('title', 'General Stock — Category Summary')

@php
    $qty = fn ($v) => \App\Support\Quantity::whole($v);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $exportQuery = ['month' => $month];
    $palette = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#0891b2', '#dc2626', '#65a30d', '#db2777'];
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => \App\Models\PurchaseRequisition::seesEveryRequisition(auth()->user()) ? route('store.dashboard') : null],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Category Summary'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Category Summary</h3>
                    <p class="app-hero-copy mb-0">Where the purchase money goes, by item category — {{ $monthLabel }}.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.requisitions.category-summary.pdf', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF
                </a>
                <a href="{{ route('store.stock.requisitions.category-summary.excel', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Download Excel
                </a>
                <a href="{{ route('store.stock.requisitions.report', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Monthly Report
                </a>
                <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    <style>
        .gx-stock-scope .gx-stock-tile-value { font-size: 1.3rem; overflow-wrap: normal !important; word-break: normal; }
        .gx-stock-scope .gx-stock-tile-value.is-text { font-size: 1.05rem; }
        .gx-cat-bar { height: 10px; border-radius: 999px; background: #eef2f7; overflow: hidden; min-width: 120px; }
        .gx-cat-bar > i { display: block; height: 100%; border-radius: 999px; }
        .gx-cat-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: .5rem; }
        .gx-cat-split { display: flex; height: 18px; border-radius: 999px; overflow: hidden; background: #eef2f7; }
        .gx-cat-split > i { display: block; height: 100%; }
    </style>

    <div class="row g-3 mb-4">
        @foreach([
            ['label' => 'Requisitions', 'value' => number_format($totals['requisitions']), 'tone' => 'secondary', 'icon' => 'bi-clipboard-check'],
            ['label' => 'Distinct Items', 'value' => number_format($totals['items']), 'tone' => 'secondary', 'icon' => 'bi-box-seam'],
            ['label' => 'To Be Procured', 'value' => $qty($totals['to_be_procured']), 'tone' => 'warning', 'icon' => 'bi-cart-plus'],
            ['label' => 'Total Amount', 'value' => $money($totals['amount']), 'tone' => 'success', 'icon' => 'bi-cash-stack'],
            ['label' => 'Top Category', 'value' => $top ? $top['name'] : '—', 'tone' => 'primary', 'icon' => 'bi-trophy', 'note' => $top ? number_format($top['share'], 1).'% of spend' : null],
        ] as $tile)
            <div class="col-6 col-md-4 col-xl">
                <div class="card gx-stock-card h-100">
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}"><i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i></span>
                        <div style="min-width:0">
                            <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                            <div class="gx-stock-tile-value {{ isset($tile['note']) ? 'is-text' : '' }}">{{ $tile['value'] }}</div>
                            @if(!empty($tile['note']))<div class="small text-muted">{{ $tile['note'] }}</div>@endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Monthly against Individual requisitions, as raised. --}}
    <div class="row g-3 mb-4">
        @foreach($kinds as $kind)
            <div class="col-12 col-md-6">
                <div class="card gx-stock-card h-100">
                    <div class="gx-stock-tile-body justify-content-between">
                        <div>
                            <div class="gx-stock-tile-label">{{ $kind['label'] }} requisitions</div>
                            <div class="gx-stock-tile-value">{{ number_format($kind['requisitions']) }} <span class="small text-muted fw-normal">sheets</span></div>
                        </div>
                        <div class="text-end">
                            <div class="gx-stock-tile-label">Amount</div>
                            <div class="gx-stock-tile-value">{{ $money($kind['amount']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
                <h5 class="mb-0">By Category</h5>
                <form method="GET" class="d-flex align-items-end gap-2 gx-stock-filter">
                    <div>
                        <label class="form-label mb-1" for="month">Month</label>
                        <input type="month" name="month" id="month" value="{{ $month }}" class="form-control form-control-sm">
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Show</button>
                </form>
            </div>

            @if($categories->isEmpty())
                <div class="gx-stock-empty">
                    <span class="gx-stock-empty-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
                    <div class="gx-stock-empty-title">No counted requisitions in {{ $monthLabel }}</div>
                    <div class="gx-stock-empty-hint">Drafts and sheets still with a head are not counted. Pick another month.</div>
                </div>
            @else
                <div class="gx-cat-split mb-3" role="img" aria-label="Share of spend by category">
                    @foreach($categories as $i => $row)
                        <i style="width:{{ $row['share'] }}%;background:{{ $palette[$i % count($palette)] }}" title="{{ $row['name'] }} {{ $row['share'] }}%"></i>
                    @endforeach
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr>
                            <th>Category</th><th class="text-end">Items</th><th class="text-end">Qty Requested</th>
                            <th class="text-end">To Be Procured</th><th class="text-end">Amount</th><th style="min-width:170px">Share of spend</th>
                            <th class="text-end">vs {{ $previousLabel }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach($categories as $i => $row)
                            <tr>
                                <td class="fw-semibold"><span class="gx-cat-dot" style="background:{{ $palette[$i % count($palette)] }}"></span>{{ $row['name'] }}</td>
                                <td class="text-end">{{ number_format($row['items']) }}</td>
                                <td class="text-end">{{ $qty($row['qty_requested']) }}</td>
                                <td class="text-end">{{ $qty($row['to_be_procured']) }}</td>
                                <td class="text-end fw-semibold">{{ $money($row['amount']) }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="gx-cat-bar flex-grow-1"><i style="width:{{ $row['share'] }}%;background:{{ $palette[$i % count($palette)] }}"></i></div>
                                        <span class="small text-muted" style="width:44px">{{ number_format($row['share'], 1) }}%</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    @if($row['change'] === null)
                                        <span class="text-muted">—</span>
                                    @else
                                        <span class="{{ $row['change'] > 0 ? 'text-danger' : 'text-success' }}">{{ $row['change'] > 0 ? '▲ +' : '▼ ' }}{{ number_format($row['change'], 1) }}%</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr class="fw-bold">
                            <td>Total</td><td class="text-end">{{ number_format($totals['items']) }}</td><td class="text-end">{{ $qty($totals['qty_requested']) }}</td>
                            <td class="text-end">{{ $qty($totals['to_be_procured']) }}</td><td class="text-end">{{ $money($totals['amount']) }}</td>
                            <td></td>
                            <td class="text-end">
                                @php($before = (float) $previous['amount'])
                                @if($before > 0)
                                    @php($delta = round(((float) $totals['amount'] - $before) / $before * 100, 1))
                                    <span class="{{ $delta > 0 ? 'text-danger' : 'text-success' }}">{{ $delta > 0 ? '▲ +' : '▼ ' }}{{ number_format($delta, 1) }}%</span>
                                @else — @endif
                            </td>
                        </tr></tfoot>
                    </table>
                </div>
                <p class="small text-muted mt-3 mb-0">Counted requisitions as raised: the same figures as the Final Requisition's category sums (the Monthly Report recalculates stock across sheets, so its totals can differ). Categories run largest spend first, as on the Final Requisition. A red arrow means more spend than {{ $previousLabel }}; green means less.</p>
            @endif
        </div>
    </div>
</div>
@endsection
