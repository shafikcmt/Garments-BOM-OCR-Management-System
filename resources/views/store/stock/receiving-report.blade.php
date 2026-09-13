@extends('layouts.app')

@section('title', 'General Stock — Receiving Report')

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $fmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-Y') : '—';

    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // Downloads take the same query string as the screen, so a file always
    // matches what was previewed.
    $exportQuery = array_filter($filters, fn ($v) => $v !== null && $v !== '');
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Receiving', 'url' => route('store.stock.purchases.index')],
        ['label' => 'Report'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Receiving Report</h3>
                    <p class="app-hero-copy mb-0">Goods received in {{ $periodLabel }}, one line per item.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.purchases.report.pdf', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.stock.purchases.report.excel', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel
                </a>
                {{-- Print opens the PDF itself, so what comes out of the printer
                     is the same document the Download button gives. --}}
                <a href="{{ route('store.stock.purchases.report.pdf', $exportQuery + ['preview' => 1]) }}"
                   target="_blank" rel="noopener" class="btn btn-outline-secondary">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
                </a>
                <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All Receiving
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    {{-- Period summary. Same five-tile pattern as the monthly requisition
         report: read-only figures, so .gx-stock-tile (which carries the link
         hover) is deliberately not used. --}}
    <div class="row g-3 mb-4">
        @foreach([
            ['label' => 'Deliveries', 'value' => number_format($summary['deliveries']), 'tone' => 'secondary', 'icon' => 'bi-truck'],
            ['label' => 'Item Lines', 'value' => number_format($summary['lines']), 'tone' => 'secondary', 'icon' => 'bi-list-ol'],
            ['label' => 'Suppliers', 'value' => number_format($summary['suppliers']), 'tone' => 'secondary', 'icon' => 'bi-shop'],
            ['label' => 'Total Qty', 'value' => $qty($summary['qty']), 'tone' => 'warning', 'icon' => 'bi-box-seam'],
            ['label' => 'Total Value', 'value' => $money($summary['value']), 'tone' => 'success', 'icon' => 'bi-cash-stack'],
        ] as $tile)
            <div class="col-6 col-md-4 col-xl">
                <div class="card gx-stock-card h-100">
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}">
                            <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                            <div class="gx-stock-tile-value">{{ $tile['value'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">

            <form method="GET" class="row g-3 gx-stock-filter mb-4">
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="month">Month</label>
                    <input type="month" name="month" id="month" value="{{ $month }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="challan_no">Challan / Invoice No</label>
                    <input type="text" name="challan_no" id="challan_no" value="{{ $filters['challan_no'] ?? '' }}" class="form-control" placeholder="Any">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="rv_no">GRN No</label>
                    <input type="text" name="rv_no" id="rv_no" value="{{ $filters['rv_no'] ?? '' }}" class="form-control" placeholder="Any">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="supplier">Supplier</label>
                    <select name="supplier" id="supplier" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($suppliers as $s)
                            <option value="{{ $s }}" @selected(($filters['supplier'] ?? '') === $s)>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="from">Challan Date From</label>
                    <input type="date" name="from" id="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="to">Challan Date To</label>
                    <input type="date" name="to" id="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="rcv_from">RCV Date From</label>
                    <input type="date" name="rcv_from" id="rcv_from" value="{{ $filters['rcv_from'] ?? '' }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="rcv_to">RCV Date To</label>
                    <input type="date" name="rcv_to" id="rcv_to" value="{{ $filters['rcv_to'] ?? '' }}" class="form-control">
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Item, challan, GRN, supplier">
                </div>
                <div class="col-12 col-md-6 col-xl-3 d-flex gap-2 align-items-end">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.purchases.report') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            @if($rows->isEmpty())
                <div class="gx-stock-empty">
                    <span class="gx-stock-empty-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                    <div class="gx-stock-empty-title">No deliveries in {{ $periodLabel }}</div>
                    <div class="gx-stock-empty-hint">Clear the filters, or record a receiving to see it here.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 gx-report-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:48px;">SL</th>
                                <th>GRN No</th>
                                <th>Challan / Inv No</th>
                                <th>Challan Date</th>
                                <th>RCV Date</th>
                                <th>Supplier</th>
                                <th style="min-width:220px;">Item Name</th>
                                <th style="min-width:150px;">Brand/Specification</th>
                                <th>UOM</th>
                                <th style="min-width:130px;">Category</th>
                                <th class="text-end">Received Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Received Value</th>
                                <th style="min-width:170px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- One row per ITEM RECEIVED, not per delivery: a
                                 challan carrying three items prints three rows,
                                 each repeating that delivery's GRN, challan,
                                 dates and supplier so every row can be read on
                                 its own — and so a remark always sits level
                                 with the item it was written against. $sl runs
                                 across the whole table, so the last SL is the
                                 number of item lines shown. --}}
                            @php($sl = 0)
                            @foreach($rows as $row)
                                @php($groupLines = $lines[$row->group_key] ?? collect())
                                @forelse($groupLines as $line)
                                    @php($sl++)
                                    <tr>
                                        <td class="text-center text-muted small">{{ $sl }}</td>
                                        <td class="fw-semibold text-slate-900">{{ $row->rv_no ?: '—' }}</td>
                                        <td class="small">{{ $row->challan_no ?: '—' }}</td>
                                        <td class="small">{{ $fmt($row->purchase_date) }}</td>
                                        <td class="small">{{ $fmt($row->rcv_date) }}</td>
                                        <td class="small">{{ $row->supplier_name ?: '—' }}</td>
                                        <td class="small">{{ optional($line->stockItem)->name ?: '—' }}</td>
                                        {{-- Brand/Specification and Category off
                                             the item master, the same source the
                                             Record Receiving form reads them from
                                             — so this report, Purchase History and
                                             the entry screen all name an item the
                                             same way. --}}
                                        <td class="small text-muted">{{ optional($line->stockItem)->brand ?: '—' }}</td>
                                        <td class="small">{{ optional($line->stockItem)->uom ?: '—' }}</td>
                                        <td class="small text-muted">{{ optional($line->stockItem)->category ?: '—' }}</td>
                                        <td class="text-end">{{ $qty($line->qty) }}</td>
                                        <td class="text-end small">{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2) : '—' }}</td>
                                        <td class="text-end fw-semibold">{{ $money($line->total_value) }}</td>
                                        <td class="small text-muted">{{ $line->remarks ?: '—' }}</td>
                                    </tr>
                                @empty
                                    {{-- A delivery with no item lines left still
                                         belongs in the table: dropping it would
                                         make the Deliveries tile disagree with
                                         what is printed. --}}
                                    @php($sl++)
                                    <tr>
                                        <td class="text-center text-muted small">{{ $sl }}</td>
                                        <td class="fw-semibold text-slate-900">{{ $row->rv_no ?: '—' }}</td>
                                        <td class="small">{{ $row->challan_no ?: '—' }}</td>
                                        <td class="small">{{ $fmt($row->purchase_date) }}</td>
                                        <td class="small">{{ $fmt($row->rcv_date) }}</td>
                                        <td class="small">{{ $row->supplier_name ?: '—' }}</td>
                                        <td class="small">—</td>
                                        <td class="small">—</td>
                                        <td class="small">—</td>
                                        <td class="small">—</td>
                                        <td class="text-end">{{ $qty($row->group_total_qty) }}</td>
                                        <td class="text-end small">—</td>
                                        <td class="text-end fw-semibold">{{ $money($row->group_total_value) }}</td>
                                        <td class="small text-muted">—</td>
                                    </tr>
                                @endforelse
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                {{-- Brand/Specification and Category were added
                                     after Item Name, so the label runs two
                                     columns further. Unit Price is a per-line
                                     rate and has no meaningful total, so its
                                     cell is left empty rather than summing
                                     rates into a number that means nothing. --}}
                                <td colspan="10" class="text-end gx-stock-total-label">Total-</td>
                                <td class="text-end fw-bold">{{ $qty($summary['qty']) }}</td>
                                <td></td>
                                <td class="text-end fw-bold">{{ $money($summary['value']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @include('store.stock._searchable')
</div>
@endsection
