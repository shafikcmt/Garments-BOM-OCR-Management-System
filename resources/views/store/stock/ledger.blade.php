@extends('layouts.app')

@section('title', 'General Stock — Stock Report')

@php
    // Trim trailing zeros so a whole-number qty reads "15", not "15.0000".
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $date = fn ($v) => $v ? $v->format('d-M-y') : '—';

    // Drives the Clear button only — the filtering itself is the controller's.
    // ->filter() with no callback so an unticked "include inactive" (false) and
    // a blank box do not count as a filter being applied.
    $hasFilters = collect($filters)->filter()->isNotEmpty();

    $badges = [
        'out' => ['bg-danger text-white', 'bi-x-octagon'],
        'place_order' => ['bg-danger-subtle text-danger', 'bi-cart-plus'],
        'low' => ['bg-warning-subtle text-warning-emphasis', 'bi-exclamation-triangle'],
        'ok' => ['bg-success-subtle text-success', 'bi-check2'],
    ];
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Stock Report'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="journal-text" eyebrow="General Stock" title="Stock Report"
                   copy="Opening + Addition − Consumption = Stock as on Date · {{ $monthLabel }}">
        <x-slot:actions>
            <a href="{{ route('store.stock.ledger.pdf', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF
            </a>
            <a href="{{ route('store.stock.ledger.excel', request()->query()) }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Download Excel
            </a>
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
            <div class="col-6 col-lg-3">
                <a href="{{ route('store.stock.ledger', array_merge(request()->query(), ['status' => $tile['status']])) }}"
                   class="card gx-stock-card gx-stock-tile h-100 {{ $tileIsActive ? 'is-active' : '' }}"
                   @if($tileIsActive) aria-current="true" @endif>
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}">
                            <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                            <div class="gx-stock-tile-value">{{ number_format($tile['value']) }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    @if($actionList->isNotEmpty() && empty($filters['status']))
        <div class="alert alert-warning border-0 shadow-sm rounded-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>
                <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                <strong>{{ $actionList->count() }}</strong> item(s) need purchase action this month.
            </span>
            <a href="{{ route('store.stock.ledger', array_merge(request()->query(), ['status' => 'attention'])) }}"
               class="btn btn-sm btn-warning">View Place Order list</a>
        </div>
    @endif

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5>{{ $monthLabel }} <span class="badge bg-primary-subtle text-primary ms-1">{{ $rows->count() }} items</span></h5>
                <div class="small text-muted">
                    Closing Stock Value: <strong class="text-slate-900">{{ $money($summary['closing_value']) }}</strong>
                </div>
            </div>

            {{-- Filters sit inside the card they narrow, the same as every other
                 General Stock list. As a separate card above they read as their
                 own step and cost a full card's padding of vertical room. --}}
            <form method="GET" class="row g-3 gx-stock-filter mb-4">
                {{-- Six controls, so the row is laid out as 3 + 3 at tablet width
                     and a single run of 6 at desktop. Each breakpoint adds to a
                     full 12, which is what stops the last column stranding a
                     third of the card empty. --}}
                <div class="col-6 col-md-4 col-xl-2">
                    <label class="form-label" for="ledgerFilterMonth">Month</label>
                    <input type="month" id="ledgerFilterMonth" name="month" value="{{ $month }}" class="form-control">
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
                <div class="col-12 col-md-4 col-xl-2 gx-stock-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            {{-- 20 columns, same order as the reference sheet. The wrapper
                 scrolls on its own so the page body never scrolls sideways.
                 gx-stock-scroll caps its height as well, which is what lets the
                 column headers stay pinned while a long month is read down. --}}
            <div class="table-responsive gx-stock-scroll">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:110px;">Order?</th>
                            <th class="text-end">Sl</th>
                            <th style="min-width:200px;">Item Name</th>
                            <th style="min-width:140px;">Brand/Specification</th>
                            <th>Uom</th>
                            <th>Category</th>
                            {{-- Opening is the counted Item Master figure and never
                                 moves. Balance B/F is last month's closing — the
                                 figure this month's arithmetic actually builds on. --}}
                            <th class="text-end" title="Counted stock from the Item Master — never changed by a purchase or an issue">Opening</th>
                            <th class="text-end" title="Balance brought forward — last month's closing stock">Balance B/F</th>
                            <th class="text-end" title="Last month consumption pattern (per day)">Cons./Day</th>
                            <th class="text-end" title="Safety Stock Level (7 days stock)">Safety</th>
                            <th class="text-end" title="Safety stock + (consumption per day x (lead time + time to place order))">Re-order</th>
                            <th class="text-end" title="Lead time in days">Lead</th>
                            <th class="text-end">Addition</th>
                            <th title="Date of last addition">Last Add.</th>
                            <th class="text-end">Consumption</th>
                            <th title="Date of last consumption">Last Cons.</th>
                            <th class="text-end">Stock as on Date</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Closing Value</th>
                            <th style="min-width:120px;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pageRows as $index => $r)
                            @php [$badgeClass, $badgeIcon] = $badges[$r['status']]; @endphp
                            <tr @class(['table-danger' => $r['status'] === 'out'])>
                                <td>
                                    <span class="badge {{ $badgeClass }}"><i class="bi {{ $badgeIcon }} me-1" aria-hidden="true"></i>{{ $statusLabels[$r['status']] }}</span>
                                </td>
                                {{-- Serial runs across the whole report, so page 2
                                     starts at 101 rather than back at 1. --}}
                                <td class="text-end text-muted">{{ $pageRows->firstItem() + $index }}</td>
                                <td>
                                    <div class="fw-semibold text-slate-900">{{ $r['item']->name }}</div>
                                </td>
                                <td>{{ $r['item']->brand ?: '—' }}</td>
                                <td>{{ $r['item']->uom ?: '—' }}</td>
                                <td>{{ $r['item']->category ?: '—' }}</td>
                                {{-- "—" before the item was counted: no count had
                                     happened, which is not the same as zero. --}}
                                <td class="text-end">{{ $qty($r['opening']) }}</td>
                                <td class="text-end fw-semibold">{{ $qty($r['balance_bf']) }}</td>
                                <td class="text-end text-muted">{{ number_format($r['consumption_per_day'], 2) }}</td>
                                <td class="text-end">
                                    {{ $qty($r['safety']) }}
                                    @if($r['safety_is_manual'])<i class="bi bi-pin-angle-fill text-primary ms-1" title="Set by hand in the item master" aria-hidden="true"></i>@endif
                                </td>
                                <td class="text-end">
                                    {{ $r['reorder'] === null ? '—' : $qty($r['reorder']) }}
                                    @if($r['reorder_is_manual'])<i class="bi bi-pin-angle-fill text-primary ms-1" title="Set by hand in the item master" aria-hidden="true"></i>@endif
                                </td>
                                <td class="text-end text-muted">{{ $r['lead_time_days'] }}</td>
                                <td class="text-end text-success">{{ $qty($r['addition']) }}</td>
                                <td class="gx-stock-micro">{{ $date($r['last_addition_date']) }}</td>
                                <td class="text-end text-danger">{{ $qty($r['consumption']) }}</td>
                                <td class="gx-stock-micro">{{ $date($r['last_consumption_date']) }}</td>
                                <td class="text-end fw-bold text-slate-900">{{ $qty($r['stock_as_on']) }}</td>
                                <td class="text-end">{{ $money($r['unit_price']) }}</td>
                                <td class="text-end fw-semibold">{{ $money($r['closing_value']) }}</td>
                                <td class="gx-stock-micro">{{ $r['remarks'] ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="20" class="gx-stock-empty">
                                            <span class="gx-stock-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>
                                            <div class="gx-stock-empty-title">Nothing to show</div>
                                            <div class="gx-stock-empty-hint">Try a different month, category or status.</div>
                                        </td></tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                        <tfoot>
                            <tr>
                                {{-- 12, not 11: Balance B/F sits inside this span and
                                     is deliberately not totalled — summing a
                                     brought-forward balance across unrelated items
                                     says nothing. --}}
                                <td colspan="12" class="text-end gx-stock-total-label">Total</td>
                                <td class="text-end text-success">{{ $qty($summary['addition']) }}</td>
                                <td></td>
                                <td class="text-end text-danger">{{ $qty($summary['consumption']) }}</td>
                                <td colspan="3"></td>
                                <td class="text-end">{{ $money($summary['closing_value']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- Only shown once the report is longer than one page. The count
                 line stays useful either way, so it is not hidden with the
                 links: it says how much of the month is on screen. --}}
            @if($pageRows->total() > 0)
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                    <div class="small text-muted">
                        Showing {{ number_format($pageRows->firstItem()) }}–{{ number_format($pageRows->lastItem()) }}
                        of {{ number_format($pageRows->total()) }} items.
                        Totals above cover the full month.
                    </div>
                    @if($pageRows->hasPages())
                        <div>{{ $pageRows->links() }}</div>
                    @endif
                </div>
            @endif

            <p class="gx-stock-help mt-3">
                Safety Stock = last month's consumption ÷ {{ config('stock.general_stock.working_days_per_month') }} working days ×
                {{ config('stock.general_stock.safety_stock_days') }} days. Re-order Level adds the lead-time cover on top. A value
                <i class="bi bi-pin-angle-fill text-primary" aria-hidden="true"></i> pinned in the Item Master overrides the calculated one.
            </p>
        </div>
    </div>

    @include('store.stock._searchable')
</div>
@endsection
