@extends('layouts.app')

@section('title', 'General Stock — Monthly Purchase Requisition')

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '—';

    $typeLabels = \App\Models\PurchaseRequisitionItem::typeLabels();

    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // "ALL" first, then each category that carries a line this month.
    $tabs = collect(['ALL' => $rows])->union($byCategory);

    // Downloads take the same query string as the screen, so a file always
    // matches what was previewed.
    $exportQuery = array_filter($filters, fn ($v) => $v !== null && $v !== '');
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Monthly Report'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Monthly Purchase Requisition</h3>
                    <p class="app-hero-copy mb-0">Every requisition raised in {{ $monthLabel }}, combined and grouped by category.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.requisitions.report.pdf', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.stock.requisitions.report.excel', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel
                </a>
                <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All Requisitions
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    {{-- Month summary. --}}
    <div class="row g-3 mb-4">
        {{-- Same tiles as the Stock Report's counts, built from the same
             pieces: the card supplies the container, .gx-stock-tile-body the
             layout, and the toned icon badge the colour.

             .gx-stock-tile itself is deliberately NOT used. That class carries
             the link behaviour — hover lift, focus ring, is-active — and these
             five are read-only figures, not filters, so a hover that implies
             something to click would be a lie. --}}
        @foreach([
            ['label' => 'Requisitions', 'value' => number_format($summary['requisitions']), 'tone' => 'secondary', 'icon' => 'bi-clipboard-check'],
            ['label' => 'Item Lines', 'value' => number_format($summary['lines']), 'tone' => 'secondary', 'icon' => 'bi-list-ol'],
            ['label' => 'Distinct Items', 'value' => number_format($summary['items']), 'tone' => 'secondary', 'icon' => 'bi-box-seam'],
            ['label' => 'To Be Procured', 'value' => $qty($summary['to_be_procured']), 'tone' => 'warning', 'icon' => 'bi-cart-plus'],
            ['label' => 'Total Amount', 'value' => $money($summary['amount']), 'tone' => 'success', 'icon' => 'bi-cash-stack'],
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
                    <label class="form-label" for="categoryFilter">Category</label>
                    <select name="category" id="categoryFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($categories as $c)
                            <option value="{{ $c }}" @selected(($filters['category'] ?? '') === $c)>{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Item, requisition no, department">
                </div>
                <div class="col-12 col-md-6 col-xl-2 d-flex align-items-end">
                    {{-- Off by default so every figure can be traced back to the
                         requisition it came from; on, the month reads one line
                         per item the way an order is actually placed. --}}
                    <div class="form-check">
                        <input type="checkbox" name="merge" id="merge" value="1" class="form-check-input" @checked($merge)>
                        <label class="form-check-label" for="merge">Merge by item</label>
                    </div>
                </div>
                <div class="col-12 col-xl-2 d-flex gap-2 align-items-end">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.requisitions.report') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            @if($rows->isEmpty())
                <div class="gx-stock-empty">
                    <span class="gx-stock-empty-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                    <div class="gx-stock-empty-title">No requisitions in {{ $monthLabel }}</div>
                    <div class="gx-stock-empty-hint">Drafts are not counted — a requisition appears here once it is submitted.</div>
                </div>
            @else
                {{-- ALL first, then one tab per category present this month. --}}
                <ul class="nav nav-pills gx-report-tabs mb-3" role="tablist">
                    @foreach($tabs as $name => $tabRows)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="pill"
                                    data-bs-target="#tab-{{ md5((string) $name) }}" type="button" role="tab">
                                {{ $name }}
                                <span class="badge">{{ $tabRows->count() }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <div class="tab-content">
                    @foreach($tabs as $name => $tabRows)
                        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="tab-{{ md5((string) $name) }}" role="tabpanel">
                            @php($sectionTotal = $tabRows->sum('amount'))

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <h6 class="gx-stock-subhead mb-0">{{ $name }}</h6>
                                <span class="text-muted small">
                                    {{ $tabRows->count() }} line(s) ·
                                    To Be Procured <strong class="text-slate-900">{{ $qty($tabRows->sum('to_be_procured')) }}</strong> ·
                                    Total <strong class="text-slate-900">{{ $money($sectionTotal) }}</strong>
                                </span>
                            </div>

                            <div class="gx-line-scroll">
                                <table class="table align-middle mb-0 gx-line-table">
                                    <thead>
                                        <tr>
                                            <th class="text-center" rowspan="2" style="width:44px;">SL</th>
                                            <th rowspan="2" style="min-width:200px;">Name of Item</th>
                                            <th rowspan="2">Uom</th>
                                            <th rowspan="2" style="min-width:140px;">Specification / Brand</th>
                                            <th rowspan="2">Type</th>
                                            <th rowspan="2" style="min-width:140px;">User Dept. / Section</th>
                                            <th colspan="4" class="text-center gx-line-group">Stock Details</th>
                                            <th colspan="3" class="text-center gx-line-group">Last Purchase Details</th>
                                            <th colspan="3" class="text-center gx-line-group">To Be Procured</th>
                                            <th colspan="2" class="text-center gx-line-group">Store Ack.</th>
                                            <th colspan="2" class="text-center gx-line-group">Accounts Ack.</th>
                                            <th rowspan="2" style="min-width:120px;">Remarks</th>
                                        </tr>
                                        <tr>
                                            <th class="text-end">Qty Requested</th>
                                            <th class="text-end">Stock in Hand</th>
                                            <th class="text-end">Safety Stock</th>
                                            <th class="text-end">Cons. (Last Month)</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-end">Rate</th>
                                            <th class="text-end gx-line-date">Date</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-end">Rate Appx.</th>
                                            <th class="text-end">Amount</th>
                                            <th class="text-end">Pending Qty</th>
                                            <th>Signature</th>
                                            <th class="text-end">Pending Qty</th>
                                            <th>Signature</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($tabRows as $i => $row)
                                            <tr>
                                                <td class="text-center gx-line-no">{{ $i + 1 }}</td>
                                                <td class="fw-semibold text-slate-900">
                                                    {{ $row['item_name'] }}
                                                    {{-- Every figure traceable to the document it came
                                                         from; a merged row lists all of them. --}}
                                                    <span class="gx-line-src">{{ $row['requisition_no'] ?: '—' }}</span>
                                                </td>
                                                <td class="small">{{ $row['uom'] ?: '—' }}</td>
                                                <td class="small">{{ $row['specification'] ?: '—' }}</td>
                                                <td class="small">{{ $row['type'] ? ($typeLabels[$row['type']] ?? $row['type']) : '—' }}</td>
                                                <td class="small gx-line-dept">{{ $row['user_dept'] ?: '—' }}</td>

                                                <td class="text-end fw-bold">{{ $qty($row['qty_requested']) }}</td>
                                                <td class="text-end">{{ $qty($row['stock_in_hand']) }}</td>
                                                <td class="text-end">{{ $qty($row['safety_stock']) }}</td>
                                                <td class="text-end">{{ $qty($row['consumption_last_month']) }}</td>

                                                <td class="text-end">{{ $qty($row['last_purchase_qty']) }}</td>
                                                <td class="text-end">{{ $money($row['last_purchase_rate']) }}</td>
                                                <td class="text-end small gx-line-date">{{ $date($row['last_purchase_date']) }}</td>

                                                <td class="text-end fw-bold">{{ $qty($row['to_be_procured']) }}</td>
                                                <td class="text-end">{{ $money($row['rate_appx']) }}</td>
                                                <td class="text-end fw-semibold">{{ $money($row['amount']) }}</td>

                                                <td class="text-end">{{ $qty($row['store_pending_qty']) }}</td>
                                                <td></td>
                                                <td class="text-end">{{ $qty($row['accounts_pending_qty']) }}</td>
                                                <td></td>

                                                <td class="small text-muted">{{ $row['remarks'] ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="15" class="text-end gx-stock-total-label">Total-</td>
                                            <td class="text-end fw-bold">{{ $money($sectionTotal) }}</td>
                                            <td colspan="5"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @include('store.stock._searchable')

    <style>
        .gx-line-scroll { overflow-x: auto; }
        .gx-line-table { border-collapse: separate; border-spacing: 0; min-width: 2100px !important; }

        .gx-line-table thead th {
            font-size: .64rem; text-transform: uppercase; letter-spacing: .04em;
            font-weight: 750; color: #64748b; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0; padding: .45rem .4rem; vertical-align: bottom;
            white-space: normal !important; line-height: 1.25;
        }
        .gx-line-table thead th.gx-line-group {
            background: #eef4ff; color: #1d4ed8; font-weight: 800; text-align: center !important;
        }
        .gx-line-table thead th:first-child { border-top-left-radius: 10px; }
        .gx-line-table thead th:last-child { border-top-right-radius: 10px; }

        .gx-line-table tbody td { padding: .45rem .4rem; border-bottom: 1px solid #eef2f7; vertical-align: top; }
        .gx-line-table tbody tr:nth-child(even) td { background: #fcfdff; }
        .gx-line-no { font-size: .78rem; font-weight: 700; color: #94a3b8; }

        /* components.css centres and pills every cell of a row holding a colspan
           cell; the tfoot Total- row triggers it for this table. Scoped back so
           the item rows keep the alignment they were written with. */
        .gx-line-table tbody td { text-align: left !important; }
        .gx-line-table tbody td.text-end { text-align: right !important; white-space: nowrap; }
        .gx-line-table tbody td.text-center { text-align: center !important; }

        .gx-line-table th.gx-line-date,
        .gx-line-table td.gx-line-date { white-space: nowrap !important; min-width: 92px; }
        .gx-line-table td.gx-line-dept { white-space: normal; min-width: 140px; }

        /* The Requisition No a line came from, under the item name. */
        .gx-line-src {
            display: block; margin-top: .15rem;
            font-size: .64rem; font-weight: 600; color: #94a3b8; white-space: normal;
        }

        .gx-report-tabs .nav-link {
            border-radius: 999px; font-size: .78rem; font-weight: 700;
            color: #475569; padding: .35rem .85rem;
        }
        .gx-report-tabs .nav-link.active { background: #2563eb; color: #fff; }
        .gx-report-tabs .nav-link .badge {
            background: rgba(15, 23, 42, .08); color: inherit;
            margin-left: .35rem; font-size: .66rem;
        }
        .gx-report-tabs .nav-link.active .badge { background: rgba(255, 255, 255, .25); }
    </style>
</div>
@endsection
