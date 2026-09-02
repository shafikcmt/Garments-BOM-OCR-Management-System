@extends('layouts.app')

@section('title', 'General Stock — Stock Report')

@php
    // The row formatters and the status badge map moved into _ledger-rows with
    // the table itself, so an AJAX render has them without a parent view.
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    // Same trimming the table uses, so the card and the Total row agree.
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

    // Drives the Clear button only — the filtering itself is the controller's.
    // ->filter() with no callback so an unticked "include inactive" (false) and
    // a blank box do not count as a filter being applied.
    $hasFilters = collect($filters)->filter()->isNotEmpty();
@endphp

@section('content')
{{-- gx-ledger scopes _ledger-ui to this screen. Nothing else carries it, which
     is what keeps the restyle off the other thirteen General Store pages that
     share _stock-ui. --}}
<div class="container-fluid gx-stock-scope gx-ledger">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Stock Report'],
    ]" />

    @include('store.stock._stock-ui')
    @include('store.stock._ledger-ui')

    <x-page-header icon="journal-text" eyebrow="General Stock" title="Stock Report"
                   copy="Opening Stock + Addition − Consumption = Stock as on Date · {{ $monthLabel }}">
        <x-slot:actions>
            {{-- data-ledger-export is what keeps these two honest.

                 Both hrefs are built server-side from the query as it was AT
                 PAGE LOAD. The filter JS swaps the table and rewrites the
                 address bar without reloading, so without this hook the buttons
                 stayed frozen on the month the page opened with: pick August on
                 a page that opened in September and the table said August while
                 the download said September. stock-ledger-table.js re-points
                 both after every filter change. --}}
            <a href="{{ route('store.stock.ledger.pdf', request()->query()) }}" class="btn btn-outline-secondary" data-ledger-export>
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF
            </a>
            <a href="{{ route('store.stock.ledger.excel', request()->query()) }}" class="btn btn-outline-secondary" data-ledger-export>
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Download Excel
            </a>
            @if($canImport ?? false)
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importReference">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Import Reference Data
                </button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    {{-- Status summary. Each tile links back into the same report with the
         matching status filter applied, so a manager can go straight from the
         count to the list behind it. --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Items Tracked', 'value' => $summary['items'], 'status' => null, 'tone' => 'secondary', 'icon' => 'bi-box-seam'],
            ['label' => 'Out of Stock', 'value' => $summary['out'], 'status' => 'out', 'tone' => 'danger', 'icon' => 'bi-x-octagon'],
            ['label' => 'Place Order', 'value' => $summary['place_order'], 'status' => 'place_order', 'tone' => 'danger', 'icon' => 'bi-cart-plus'],
            ['label' => 'Low Stock', 'value' => $summary['low'], 'status' => 'low', 'tone' => 'warning', 'icon' => 'bi-exclamation-triangle'],
        ] as $tile)
            @php $tileIsActive = ($filters['status'] ?? '') === (string) $tile['status'] && $tile['status'] !== null; @endphp
            <div class="col-6 col-lg-4 col-xl-2">
                <a href="{{ route('store.stock.ledger', array_merge(request()->query(), ['status' => $tile['status']])) }}"
                   class="card gx-stock-card gx-stock-tile h-100 {{ $tileIsActive ? 'is-active' : '' }}"
                   @if($tileIsActive) aria-current="true" @endif>
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}">
                            <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                            {{-- Keyed so a live filter can refresh the figure. These
                                 count the whole filtered month, so leaving them at
                                 the unfiltered numbers after an AJAX narrow would
                                 put four wrong totals at the top of the report. --}}
                            <div class="gx-stock-tile-value" data-ledger-tile="{{ $tile['status'] ?? 'items' }}">{{ number_format($tile['value']) }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach

        {{-- The two money/quantity figures. Same tile shape as the four counts
             beside them, but not links: there is no "filter by total". Both
             cover the whole filtered month, exactly like the report's Total
             row, not the page on screen. --}}
        @foreach ([
            ['label' => 'Total Stock Qty', 'key' => 'stock_qty', 'tone' => 'primary', 'icon' => 'bi-boxes',
             'value' => $qty($summary['stock_as_on']), 'hint' => 'Sum of Stock as on Date'],
            ['label' => 'Closing Stock Value', 'key' => 'closing_value', 'tone' => 'success', 'icon' => 'bi-cash-stack',
             'value' => $money($summary['closing_value']), 'hint' => 'Stock as on Date x unit price'],
        ] as $tile)
            <div class="col-6 col-lg-4 col-xl-2">
                <div class="card gx-stock-card h-100">
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}">
                            <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <div class="gx-ledger-figure">
                            <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                            <div class="gx-stock-tile-value gx-ledger-figure-value" data-ledger-tile="{{ $tile['key'] }}">{{ $tile['value'] }}</div>
                            <div class="gx-ledger-figure-hint">{{ $tile['hint'] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Rendered whenever no status filter is set, but hidden while the count is
         zero: a live filter can empty it and fill it again without a reload, and
         re-creating the banner from JS would mean keeping a copy of its markup
         in two places. --}}
    @if(empty($filters['status']))
        <div class="alert alert-warning border-0 shadow-sm rounded-3 d-flex flex-wrap align-items-center justify-content-between gap-2 gx-ledger-action-alert {{ $actionList->isEmpty() ? 'd-none' : '' }}"
             data-ledger-action-alert>
            <span>
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                <strong data-ledger-action-count>{{ $actionList->count() }}</strong> item(s) need purchase action this month.
            </span>
            <a href="{{ route('store.stock.ledger', array_merge(request()->query(), ['status' => 'attention'])) }}"
               class="btn btn-sm btn-warning">View Place Order list</a>
        </div>
    @endif

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5>
                    <span data-ledger-month-label>{{ $monthLabel }}</span>
                    <span class="badge bg-primary-subtle text-primary ms-1"><span data-ledger-count>{{ $rows->count() }}</span> items</span>
                    {{-- Sits next to the count it belongs to, so the feedback is
                         where the user is already looking while typing. Hidden
                         until a request is actually in flight. --}}
                    <span class="spinner-border spinner-border-sm text-primary ms-2 d-none" role="status" data-ledger-spinner>
                        <span class="visually-hidden">Updating the report…</span>
                    </span>
                </h5>
                {{-- Closing Stock Value moved up into a summary card with Total
                     Stock Qty, so the two figures sit together instead of one
                     being a line of text in a heading. --}}
            </div>

            {{-- The note that used to sit here explained why two columns near
                 the left showed the same number. There is one opening column
                 now, so there is nothing left to explain — and the explanation
                 was covering for the defect rather than fixing it.

                 Said here rather than in _report-table: that partial is shared
                 with the PDF and the Excel export, and a note belongs on the
                 screen somebody is puzzling at, not in a management document. --}}
            <p class="gx-stock-help mb-3">
                <strong>Opening Stock</strong> is last month's closing stock, carried forward.
                This month's Addition and Consumption move it to <strong>Stock as on Date</strong>.
            </p>

            {{-- Shown only when the period actually predates some item's stock
                 count. Before this, such a month reported those items as
                 0.00 and flagged them Out of Stock — 1,138 of them in a July
                 2026 report — which reads as an empty store and is a buying
                 signal off a figure nobody counted. --}}
            {{-- Always rendered, hidden when the count is zero: the filter JS
                 toggles and refills it from the fragment's meta, and an element
                 that is not in the DOM cannot be toggled back on when the user
                 switches to a month that does need it. --}}
            <div class="alert alert-secondary d-flex gap-2 mb-3 {{ ($summary['not_counted'] ?? 0) > 0 ? '' : 'd-none' }}"
                 role="status" data-ledger-uncounted-alert>
                <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
                <div>
                    <strong data-ledger-uncounted-count>{{ number_format($summary['not_counted']) }}</strong> of
                    <span data-ledger-count>{{ number_format($summary['items']) }}</span> items had not been
                    stock-counted as at <span data-ledger-month-label>{{ $monthLabel }}</span>.
                    Their figures show as &mdash; and are left out of the totals, which cover the
                    <span data-ledger-counted-count>{{ number_format($summary['counted_items']) }}</span>
                    counted items.
                </div>
            </div>

            {{-- Filters sit inside the card they narrow, the same as every other
                 General Stock list. As a separate card above they read as their
                 own step and cost a full card's padding of vertical room. --}}
            {{-- Stays a plain GET form with a working Filter button. The JS below
                 is an enhancement on top: with JS off, or if the fetch fails,
                 submitting still reloads the page with the same filters. --}}
            <form method="GET" class="row g-3 gx-stock-filter mb-4" data-ledger-filters
                  action="{{ route('store.stock.ledger') }}">
                {{-- Nine controls, laid out as 3 + 3 + 3 at tablet width and
                     two runs of 6 at desktop. Each breakpoint still adds to a
                     full 12, which is what stops the last column stranding a
                     third of the card empty. --}}
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterMonth">Month</label>
                    <input type="month" id="ledgerFilterMonth" name="month" value="{{ $month }}" class="form-control">
                </div>
                {{-- From / To move the whole ledger window: Balance B/F becomes
                     everything before From, Addition and Consumption become
                     what moved between the two dates, and Stock as on Date
                     becomes the balance at To.

                     They sit NEXT TO Month rather than replacing it because
                     Month is what every existing link, bookmark and export
                     filename is written in. Leave them blank and the report is
                     the month, exactly as it has always been. The helper text
                     under them says which one is winning, so the pair can never
                     read as two controls quietly fighting. --}}
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterFrom">From Date</label>
                    <input type="date" id="ledgerFilterFrom" name="from" value="{{ $filters['from'] ?? '' }}"
                           class="form-control @error('from') is-invalid @enderror">
                    @error('from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterTo">To Date</label>
                    <input type="date" id="ledgerFilterTo" name="to" value="{{ $filters['to'] ?? '' }}"
                           class="form-control @error('to') is-invalid @enderror">
                    @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterSupplier">Supplier</label>
                    <select id="ledgerFilterSupplier" name="supplier_id" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}"
                                @selected((int) ($filters['supplier_id'] ?? 0) === (int) $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterSearch">Search</label>
                    <input id="ledgerFilterSearch" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Item or brand/spec.">
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterCategory">Category</label>
                    <select id="ledgerFilterCategory" name="category" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($categories as $category)
                            <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterStatus">Status</label>
                    <select id="ledgerFilterStatus" name="status" class="form-select">
                        <option value="">All</option>
                        <option value="attention" @selected(($filters['status'] ?? '') === 'attention')>Needs Attention</option>
                        @foreach($statusLabels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="form-check">
                        <input type="hidden" name="include_inactive" value="0">
                        <input class="form-check-input" type="checkbox" name="include_inactive" value="1" id="includeInactive"
                               @checked($filters['include_inactive'] ?? false)>
                        <label class="form-check-label" for="includeInactive">Include inactive items</label>
                    </div>
                </div>
                {{-- Says which of Month and the date range is actually in force,
                     and what Supplier did. Two controls that both look like
                     "the period" need the screen to state the answer rather
                     than leaving the reader to work it out from the figures. --}}
                @if($period['is_range'] || ! empty($filters['supplier_id']))
                    <div class="col-12">
                        <div class="form-text mb-0">
                            @if($period['is_range'])
                                Showing <strong>{{ $monthLabel }}</strong> — the date range is in use, so the Month
                                box is ignored. Safety Stock, Re-order Level and Status still read the consumption
                                pattern of {{ $period['end']->copy()->subMonthNoOverflow()->format('F Y') }}, so they
                                match the Item Master.
                            @endif
                            @if(! empty($filters['supplier_id']))
                                Supplier narrows which items are listed. Every figure shown is still the item's
                                whole-store figure, not that supplier's share.
                            @endif
                        </div>
                    </div>
                @endif

                <div class="col-12 col-md-4 col-xl-2 gx-stock-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            {{-- Everything the filters change lives in this container: the JS
                 swaps it wholesale for the same Blade re-rendered server-side,
                 so a live search and a printed page can never disagree. --}}
            <div id="stockLedgerTable" data-ledger-table>
                @include("store.stock._ledger-rows")
            </div>
        </div>
    </div>

    @include('store.stock._searchable')
</div>
    @if($canImport ?? false)
        @include('store.stock._import-reference-modal')
    @endif
@endsection
