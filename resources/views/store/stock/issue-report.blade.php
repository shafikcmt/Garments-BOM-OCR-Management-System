@extends('layouts.app')

@section('title', 'General Stock — Issue Report')

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
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
        ['label' => 'Issues', 'url' => route('store.stock.issues.index')],
        ['label' => 'Report'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Issue Report</h3>
                    <p class="app-hero-copy mb-0">Consumption in {{ $periodLabel }}, one line per item issued.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.issues.report.pdf', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.stock.issues.report.excel', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel
                </a>
                {{-- Print opens the PDF itself, so what comes out of the printer
                     is the same document the Download button gives. --}}
                <a href="{{ route('store.stock.issues.report.pdf', $exportQuery + ['preview' => 1]) }}"
                   target="_blank" rel="noopener" class="btn btn-outline-secondary">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
                </a>
                <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All Issues
                </a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    {{-- Period summary. Read-only figures, so .gx-stock-tile (which carries the
         link hover) is deliberately not used.

         Labels are written for store and management staff, not for whoever
         built the table: an issue "line" is an entry to them.

         There is no Requisitions tile. Requisition Number is optional on the
         Record Issue form, so the figure counts only the lines that were given
         one and reads far below the number of documents the period actually
         covered — a number that needs explaining is worse on a dashboard than
         no number. $summary['requisitions'] is still computed and is still
         printed on the PDF/Excel copy, which is unchanged. --}}
    <div class="row g-3 mb-4">
        @foreach([
            ['label' => 'Total Entries', 'value' => number_format($summary['lines']), 'tone' => 'secondary', 'icon' => 'bi-list-ol'],
            ['label' => 'Different Items', 'value' => number_format($summary['items']), 'tone' => 'secondary', 'icon' => 'bi-box-seam'],
            ['label' => 'Sections', 'value' => number_format($summary['sections']), 'tone' => 'secondary', 'icon' => 'bi-diagram-3'],
            ['label' => 'Total Qty Issued', 'value' => $qty($summary['qty']), 'tone' => 'warning', 'icon' => 'bi-box-arrow-up'],
        ] as $tile)
            {{-- Four tiles, so col-md-3 rather than the col-md-4 that suited
                 five: on a tablet 4 sits as one even row instead of 3 plus a
                 stranded one. --}}
            <div class="col-6 col-md-3 col-xl">
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
                    <label class="form-label" for="requisition_no">Requisition No</label>
                    <input type="text" name="requisition_no" id="requisition_no" value="{{ $filters['requisition_no'] ?? '' }}" class="form-control" placeholder="Any">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="itemFilter">Item</label>
                    <select name="item" id="itemFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($items as $it)
                            <option value="{{ $it->id }}" @selected((string) ($filters['item'] ?? '') === (string) $it->id)>{{ $it->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="sectionFilter">Indent Section</label>
                    <select name="section" id="sectionFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($sections as $s)
                            <option value="{{ $s->id }}" @selected((string) ($filters['section'] ?? '') === (string) $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="personFilter">Indent Person</label>
                    <select name="person" id="personFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($persons as $p)
                            <option value="{{ $p->id }}" @selected((string) ($filters['person'] ?? '') === (string) $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="categoryFilter">Category</label>
                    <select name="category" id="categoryFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" @selected((string) ($filters['category'] ?? '') === (string) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="from">Date From</label>
                    <input type="date" name="from" id="from" value="{{ $filters['from'] ?? '' }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="to">Date To</label>
                    <input type="date" name="to" id="to" value="{{ $filters['to'] ?? '' }}" class="form-control">
                </div>
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Item or requisition no">
                </div>
                <div class="col-12 col-md-6 col-xl-3 d-flex gap-2 align-items-end">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.issues.report') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            @if($rows->isEmpty())
                <div class="gx-stock-empty">
                    <span class="gx-stock-empty-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
                    <div class="gx-stock-empty-title">No issues in {{ $periodLabel }}</div>
                    <div class="gx-stock-empty-hint">Clear the filters, or record an issue to see it here.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table align-middle mb-0 gx-report-table">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:48px;">SL</th>
                                <th>Date</th>
                                <th>Section</th>
                                <th>Person</th>
                                <th>Approved By</th>
                                <th>Req No</th>
                                <th>Type</th>
                                <th>Item</th>
                                <th>Category</th>
                                <th class="text-end">Issued Qty</th>
                                <th style="min-width:180px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $i => $row)
                                <tr>
                                    <td class="text-center text-muted small">{{ $i + 1 }}</td>
                                    <td class="small">{{ $fmt($row->issue_date) }}</td>
                                    <td class="small">{{ optional($row->indentSection)->name ?: '—' }}</td>
                                    <td class="small">{{ optional($row->indentPerson)->name ?: '—' }}</td>
                                    <td class="small">{{ optional($row->approver)->name ?: '—' }}</td>
                                    <td class="small">{{ $row->requisition_no ?: '—' }}</td>
                                    <td class="small">
                                        @if($row->requisition_type)
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $row->requisition_type }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="fw-semibold text-slate-900">{{ optional($row->stockItem)->name ?: '—' }}</td>
                                    <td class="small text-muted">{{ optional($row->itemCategory)->name ?: '—' }}</td>
                                    <td class="text-end fw-semibold">{{ $qty($row->qty) }}</td>
                                    <td class="small text-muted">{{ $row->remarks ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="9" class="text-end gx-stock-total-label">Total-</td>
                                <td class="text-end fw-bold">{{ $qty($summary['qty']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- What the store actually spent the period on — the figure a
                     purchase plan is built from. --}}
                @if($byCategory->isNotEmpty())
                    <h6 class="gx-stock-subhead mt-4 mb-2">Consumption by Category</h6>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 gx-report-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th class="text-end">Distinct Items</th>
                                    <th class="text-end">Issue Lines</th>
                                    <th class="text-end">Qty Issued</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($byCategory as $name => $group)
                                    <tr>
                                        <td>{{ $name }}</td>
                                        <td class="text-end">{{ number_format($group['items']) }}</td>
                                        <td class="text-end">{{ number_format($group['lines']) }}</td>
                                        <td class="text-end fw-semibold">{{ $qty($group['qty']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>

    @include('store.stock._searchable')
</div>
@endsection
