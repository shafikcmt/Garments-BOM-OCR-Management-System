@extends('layouts.app')

@section('title', 'General Stock — Monthly Purchase Requisition')

@php
    $qty = fn ($v) => \App\Support\Quantity::whole($v);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '—';

    $typeLabels = \App\Models\PurchaseRequisitionItem::typeLabels();

    // $tabs comes from the controller: ALL, each category, or each section,
    // as the View picks. Downloads take the same Month + View, so a file
    // always matches what was previewed.
    $exportQuery = $filters;
    $isSectionView = $view === \App\Services\MonthlyRequisitionReportService::VIEW_SECTION;
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => \App\Models\PurchaseRequisition::seesEveryRequisition(auth()->user()) ? route('store.dashboard') : null],
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
                    <p class="app-hero-copy mb-0">Requisitions dated in {{ $monthLabel }}, one line per item, stock counted once.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('store.stock.requisitions.report.pdf', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.stock.requisitions.report.excel', $exportQuery) }}" class="btn btn-outline-secondary">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>{{ $isSectionView ? 'Excel (Section-wise)' : 'Excel' }}
                </a>
                <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
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
            ['label' => 'Requisition Lines', 'value' => number_format($summary['lines']), 'tone' => 'secondary', 'icon' => 'bi-list-ol'],
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

            {{-- The switch (All | By Category | By Section) and the Month, both
                 kept in the URL. The switch is links, so it works without
                 script; the Month form carries the switch along. --}}
            <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
                <div>
                    <div class="form-label mb-1">Show</div>
                    <div class="btn-group gx-report-switch" role="group" aria-label="Report view">
                        @foreach($viewLabels as $value => $label)
                            <a href="{{ route('store.stock.requisitions.report', ['month' => $month, 'view' => $value]) }}"
                               @class(['btn btn-sm', 'btn-primary' => $view === $value, 'btn-outline-primary' => $view !== $value])
                               @if($view === $value) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
                <form method="GET" class="d-flex align-items-end gap-2 gx-stock-filter">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <div>
                        <label class="form-label mb-1" for="month">Month</label>
                        <input type="month" name="month" id="month" value="{{ $month }}" class="form-control form-control-sm">
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                </form>
            </div>

            @if($rows->isEmpty())
                <div class="gx-stock-empty">
                    <span class="gx-stock-empty-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                    <div class="gx-stock-empty-title">No requisitions in {{ $monthLabel }}</div>
                    <div class="gx-stock-empty-hint">Drafts, sheets still with a head and returned sheets are not counted — a requisition appears here once its head has checked it.</div>
                </div>
            @endif
            @if(! empty($pending))
                {{-- What this month holds that is not counted yet, and why. --}}
                <div class="alert alert-light border small {{ $rows->isEmpty() ? 'mt-3 mb-0' : 'mb-3' }}">
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i><strong>Not counted yet in {{ $monthLabel }}:</strong>
                    @foreach($pending as $why => $count)
                        {{ number_format($count) }} {{ $why === 'draft' ? \Illuminate\Support\Str::plural('draft', $count) : 'requisition'.($count === 1 ? '' : 's').' '.$why }}{{ $loop->last ? '.' : ',' }}
                    @endforeach
                </div>
            @endif
            @if($rows->isNotEmpty())
                {{-- The tabs follow the switch: ALL; ALL + each category; or
                     each section. The selected one is kept in the URL (?tab=). --}}
                <ul class="nav nav-pills gx-report-tabs mb-3" role="tablist">
                    @foreach($tabs as $name => $tab)
                        <li class="nav-item" role="presentation">
                            <button @class(['nav-link', 'active' => $tab['slug'] === $activeTab]) data-bs-toggle="pill"
                                    data-bs-target="#tab-{{ $tab['slug'] }}" data-report-tab="{{ $tab['slug'] }}" type="button" role="tab"
                                    aria-selected="{{ $tab['slug'] === $activeTab ? 'true' : 'false' }}">
                                {{ $name }}
                                <span class="badge">{{ $tab['rows']->count() }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <div class="tab-content">
                    @foreach($tabs as $name => $tab)
                        @php($tabRows = $tab['rows'])
                        @php($header = $tab['header'])
                        <div @class(['tab-pane fade', 'show active' => $tab['slug'] === $activeTab]) id="tab-{{ $tab['slug'] }}" role="tabpanel">
                            @php($sectionTotal = $tabRows->sum('amount'))

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                <h6 class="gx-stock-subhead mb-0">{{ $name }}</h6>
                                <span class="text-muted small">
                                    {{ $tabRows->count() }} item(s) ·
                                    To Be Procured <strong class="text-slate-900">{{ $qty($tabRows->sum('to_be_procured')) }}</strong> ·
                                    Total <strong class="text-slate-900">{{ $money($sectionTotal) }}</strong>
                                </span>
                            </div>
                            {{-- The printed header (Requisition No, Section, Name of
                                 User, Contact) is for the PDF and Excel only. The
                                 screen says which sheets the tab was built from,
                                 behind one small toggle. --}}
                            @php($sourceCount = count($tab['sources']))
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 gx-report-meta">
                                @if($sourceCount > 0)
                                    <button type="button" class="btn btn-sm btn-outline-secondary gx-report-sources-toggle"
                                            data-bs-toggle="collapse" data-bs-target="#sources-{{ $tab['slug'] }}"
                                            aria-expanded="false" aria-controls="sources-{{ $tab['slug'] }}">
                                        <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Includes {{ $sourceCount }} requisition{{ $sourceCount === 1 ? '' : 's' }}
                                    </button>
                                @endif
                                @if($tab['type'] === \App\Services\MonthlyRequisitionReportService::TAB_SECTION)
                                    <span class="small text-muted">{{ \App\Services\MonthlyRequisitionReportService::SECTION_STOCK_NOTE }}</span>
                                @endif
                            </div>
                            @if($sourceCount > 0)
                                <div class="collapse mb-2" id="sources-{{ $tab['slug'] }}">
                                    <ul class="list-unstyled small mb-0 gx-report-sources">
                                        @foreach($tab['sources'] as $no)
                                            <li>{{ $no }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="gx-line-scroll">
                                <table class="table align-middle mb-0 gx-line-table">
                                    <thead>
                                        <tr>
                                            <th class="text-center" rowspan="2" style="width:44px;">SL</th>
                                            <th rowspan="2" class="text-nowrap">SKU</th>
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
                                            {{-- By tab, not by switch: the ALL tab of By Section keeps the full stock. --}}
                                            <th class="text-end">{{ $tab['type'] === \App\Services\MonthlyRequisitionReportService::TAB_SECTION ? 'Stock Allocated' : 'Stock in Hand' }}</th>
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
                                                <td class="small fw-semibold text-nowrap">{{ ($row['sku'] ?? null) ?: '—' }}</td>
                                                <td class="fw-semibold text-slate-900">{{ $row['item_name'] }}</td>
                                                <td class="small">{{ $row['uom'] ?: '—' }}</td>
                                                <td class="small">{{ $row['specification'] ?: '—' }}</td>
                                                <td class="small">{{ $row['type'] ? ($typeLabels[$row['type']] ?? $row['type']) : '—' }}</td>
                                                <td class="small gx-line-dept">{{ $row['user_dept'] ?: '—' }}</td>

                                                <td class="text-end fw-bold">{{ $qty($row['qty_requested']) }}</td>
                                                <td class="text-end">{{ $qty($row['stock_in_hand']) }}</td>
                                                <td class="text-end">{{ \App\Support\Quantity::safety($row['safety_stock']) }}</td>
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
                                            <td colspan="16" class="text-end gx-stock-total-label">Total-</td>
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

    {{-- Keep the selected tab in the URL, so a reload or a shared link opens it. --}}
    <script>
        document.querySelectorAll('[data-report-tab]').forEach(function (button) {
            button.addEventListener('shown.bs.tab', function () {
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', button.dataset.reportTab);
                    window.history.replaceState(null, '', url);
                } catch (e) { /* an old browser keeps the tab without the URL */ }
            });
        });
    </script>

    <style>
        .gx-report-switch .btn { font-weight: 700; font-size: .78rem; padding: .3rem .9rem; }
        .gx-report-meta .gx-report-sources-toggle { font-size: .72rem; font-weight: 700; padding: .15rem .6rem; }
        .gx-report-sources {
            display: flex; flex-wrap: wrap; gap: .3rem .5rem;
            padding: .5rem .65rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
        }
        .gx-report-sources li { font-weight: 600; color: #334155; }
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
