{{--
    The Stock Summary — Purchase View and Issue View of the same
    period, from StockSummaryService. Rendered only for whoever
    RequirementVsIssueService::canView() admits; the caller decides.

    Full (the requisition list): filters under sum[...], view switch,
    paginated table, totals over every filtered row, exports.
    Compact (dashboards, $compact = true): the cards and the first rows of the
    Purchase View for the current month, with a link to the full card.

    Expects: $stockSummary, and for the full card $stockSummaryPage.
--}}
@php
    $compact = $compact ?? false;
    $s = $stockSummary;
    $f = $s['filters'];
    $view = $compact ? \App\Services\StockSummaryService::VIEW_PURCHASE : $s['view'];
    $RVI = \App\Services\RequirementVsIssueService::class;

    $qty = fn ($v) => $v === null ? '—' : (rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.') ?: '0');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%';

    $sumQuery = \App\Services\StockSummaryService::query($f, $view);
    // This page's URL with the summary changed as given; the list's own
    // filters (month, status, section, search) are kept untouched.
    $here = function (array $sum) {
        $params = request()->except(['sum', 'sum_page']);
        if ($sum !== []) {
            $params['sum'] = $sum;
        }

        return request()->url().($params ? '?'.http_build_query($params) : '');
    };

    $statusTone = [
        $RVI::STATUS_FULL => 'bg-success-subtle text-success',
        $RVI::STATUS_PARTIAL => 'bg-warning-subtle text-warning-emphasis',
        $RVI::STATUS_NOT_ISSUED => 'bg-secondary-subtle text-secondary-emphasis',
        $RVI::STATUS_OVER => 'bg-danger-subtle text-danger',
        $RVI::STATUS_NOT_REQUESTED => 'bg-info-subtle text-info-emphasis',
    ];

    $months = [];
    $m = now()->startOfMonth();
    for ($i = 0; $i < 24; $i++) { $months[$m->format('Y-m')] = $m->format('F Y'); $m = $m->copy()->subMonth(); }
    foreach ([$f['from'], $f['to']] as $chosen) { $months[$chosen->format('Y-m')] ??= $chosen->format('F Y'); }
    krsort($months);

    $rows = $compact ? $s['purchase']['rows']->take(8) : $stockSummaryPage->getCollection();
    $offset = $compact ? 0 : ($stockSummaryPage->currentPage() - 1) * $stockSummaryPage->perPage();
    $total = $view === 'issue' ? $s['issue']['rows']->count() : $s['purchase']['rows']->count();
@endphp

<div class="gx-stock-card-head">
    <h5 class="mb-0">
        Stock Summary
        <span class="badge bg-primary-subtle text-primary ms-1">{{ number_format($total) }} {{ $view === 'issue' ? 'rows' : 'items' }}</span>
    </h5>
    <div class="d-flex flex-wrap gap-2">
        @if($compact)
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Open Stock Summary
            </a>
        @else
            <a href="{{ route('store.stock.requisitions.requirement-vs-issue', \App\Services\StockSummaryService::query($f)) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Full Report
            </a>
            <a href="{{ route('store.stock.requisitions.stock-summary.excel', ['sum' => $sumQuery]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel
            </a>
            <a href="{{ route('store.stock.requisitions.stock-summary.pdf', ['sum' => $sumQuery]) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
            </a>
        @endif
    </div>
</div>

@unless($compact)
    {{-- Same filters as Requirement vs Issue, kept under sum[...] in the URL. --}}
    <form method="GET" class="row g-2 gx-stock-filter mb-3">
        @foreach(request()->except(['sum', 'sum_page']) as $name => $value)
            @if(is_scalar($value))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="hidden" name="sum[view]" value="{{ $view }}">
        <div class="col-6 col-md-3 col-xl-2">
            <label class="form-label small fw-semibold mb-1" for="sumFrom">From</label>
            <select name="sum[from]" id="sumFrom" class="form-select form-select-sm">
                @foreach($months as $value => $label)
                    <option value="{{ $value }}" @selected($f['from']->format('Y-m') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label class="form-label small fw-semibold mb-1" for="sumTo">To</label>
            <select name="sum[to]" id="sumTo" class="form-select form-select-sm">
                @foreach($months as $value => $label)
                    <option value="{{ $value }}" @selected($f['to']->format('Y-m') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @php($purchaseView = $view === 'purchase')
        @if($purchaseView)
            {{-- Not used by the Purchase View, but kept for the Issue View. --}}
            @foreach($f['departments'] as $value)<input type="hidden" name="sum[departments][]" value="{{ $value }}">@endforeach
            @foreach($f['sections'] as $value)<input type="hidden" name="sum[sections][]" value="{{ $value }}">@endforeach
        @endif
        <div class="col-12 col-md-6 col-xl-2">
            <label class="form-label small fw-semibold mb-1" for="sumDepartments">Department</label>
            <select @unless($purchaseView) name="sum[departments][]" @endunless id="sumDepartments" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="{{ $purchaseView ? 'Not used in Purchase View' : 'All departments' }}" @disabled($purchaseView)>
                @foreach($s['options']['departments'] as $key => $label)
                    <option value="{{ $key }}" @selected(! $purchaseView && in_array((string) $key, $f['departments'], true))>{{ $label }}</option>
                @endforeach
                <option value="{{ $RVI::NO_DEPARTMENT }}" @selected(! $purchaseView && in_array($RVI::NO_DEPARTMENT, $f['departments'], true))>No department</option>
            </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
            <label class="form-label small fw-semibold mb-1" for="sumSections">Section</label>
            <select @unless($purchaseView) name="sum[sections][]" @endunless id="sumSections" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="{{ $purchaseView ? 'Not used in Purchase View' : 'All sections' }}" @disabled($purchaseView)>
                @foreach($s['sectionOptions']->groupBy('department') as $department => $sections)
                    <optgroup label="{{ $department }}">
                        @foreach($sections as $section)
                            <option value="{{ $section['id'] }}" @selected(! $purchaseView && in_array((string) $section['id'], $f['sections'], true))>{{ $section['name'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
            <label class="form-label small fw-semibold mb-1" for="sumStatuses">Issue Status</label>
            <select name="sum[statuses][]" id="sumStatuses" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="All statuses">
                @foreach($RVI::statusLabels() as $key => $label)
                    <option value="{{ $key }}" @selected(in_array($key, $f['statuses'], true))>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
            <label class="form-label small fw-semibold mb-1" for="sumSearch">Search</label>
            <input type="search" name="sum[search]" id="sumSearch" class="form-control form-control-sm" value="{{ $f['search'] }}" placeholder="Item or specification" maxlength="100">
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply</button>
            <a href="{{ $here([]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
        </div>
    </form>
@endunless

{{-- Cards: the active view's own totals, so they always equal its totals
     row. Dashboards (compact) show the Purchase View's. --}}
@php($cards = $s['cards'][$view])
<div class="row g-2 mb-3">
    @foreach($cards as $tile)
        <div class="col-6 col-md-4 {{ count($cards) > 4 ? 'col-xl-2' : 'col-xl-3' }}">
            <div class="card gx-stock-card h-100">
                <div class="gx-stock-tile-body">
                    <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}"><i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i></span>
                    <div>
                        <div class="gx-stock-tile-label">{{ $tile['label'] }}</div>
                        <div class="gx-stock-tile-value" data-card="{{ $tile['label'] }}">{{ match ($tile['format']) { 'money' => $money($tile['value']), 'pct' => $pct($tile['value']), default => $qty($tile['value']) } }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@unless($compact)
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div class="btn-group btn-group-sm" role="group" aria-label="Summary view">
            <a href="{{ $here(\App\Services\StockSummaryService::query($f, 'purchase')) }}" class="btn {{ $view === 'purchase' ? 'btn-primary' : 'btn-outline-primary' }}" @if($view === 'purchase') aria-current="true" @endif>
                <i class="bi bi-truck me-1" aria-hidden="true"></i>Purchase View
            </a>
            <a href="{{ $here(\App\Services\StockSummaryService::query($f, 'issue')) }}" class="btn {{ $view === 'issue' ? 'btn-primary' : 'btn-outline-primary' }}" @if($view === 'issue') aria-current="true" @endif>
                <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Issue View
            </a>
        </div>
        <span class="text-muted small">{{ $s['period'] }} · Drafts excluded. Current Stock = today.@if($view === 'purchase') Purchase and stock are company-wide.@endif</span>
    </div>
@endunless

@if($rows->isEmpty())
    <div class="gx-stock-empty">
        <span class="gx-stock-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>
        <div class="gx-stock-empty-title">No matching items</div>
        <div class="gx-stock-empty-hint">Change the period or remove a filter to see more.</div>
    </div>
@elseif($view === 'issue')
    @php($sum = $s['issue']['summary'])
    {{-- Screen-only charts, from the same rows and totals as this table. --}}
    @include('store.stock.requisitions._rvi-charts', [
        'charts' => $s['charts']['issue'],
        'sectionUrl' => fn (int $id) => $here(['sections' => [(string) $id]] + \App\Services\StockSummaryService::query($f, 'issue')),
        'itemUrl' => fn (string $name) => $here(['search' => $name] + \App\Services\StockSummaryService::query($f, 'issue')),
    ])
    <div class="table-responsive gx-sum-scroll">
        <table class="table table-sm align-middle mb-0 gx-report-table gx-sum-table">
            <thead>
                <tr>
                    <th class="text-center">SL</th><th>Item</th><th>UOM</th><th>User Dept./Section</th>
                    <th class="text-end">Approx Rate</th><th class="text-end">Requested Qty</th><th class="text-end">Requested Value</th>
                    <th class="text-end">Issued Qty</th><th class="text-end">Issued Value</th><th class="text-end">Issue Bal. Qty</th>
                    <th class="text-end">Issue Bal. Value</th><th class="text-end">Issued %</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $row)
                    <tr>
                        <td class="text-center text-muted small">{{ $offset + $i + 1 }}</td>
                        <td class="fw-semibold">{{ $row['item_name'] }}</td>
                        <td class="small">{{ $row['uom'] ?: '—' }}</td>
                        <td class="small">{{ $row['section_name'] }}</td>
                        <td class="text-end">{{ $money($row['rate']) }}</td>
                        <td class="text-end">{{ $qty($row['requested_qty']) }}</td>
                        <td class="text-end">{{ $money($row['requested_value']) }}</td>
                        <td class="text-end">{{ $qty($row['issued_qty']) }}</td>
                        <td class="text-end">{{ $money($row['issued_value']) }}</td>
                        <td class="text-end {{ $row['balance_qty'] < 0 ? 'text-danger fw-semibold' : '' }}">{{ $qty($row['balance_qty']) }}</td>
                        <td class="text-end {{ ($row['balance_value'] ?? 0) < 0 ? 'text-danger fw-semibold' : '' }}">{{ $money($row['balance_value']) }}</td>
                        <td class="text-end">@include('store.stock.requisitions._rvi-pct-cell', ['row' => $row])</td>
                        <td><span class="badge {{ $statusTone[$row['status']] }}">{{ $row['status_label'] }}</span></td>
                    </tr>
                @endforeach
                {{-- Every filtered row, not only this page: the same totals as
                     Requirement vs Issue (requested items; net balance). --}}
                <tr class="gx-sum-total">
                    <td></td><td colspan="4">Total</td>
                    <td class="text-end">{{ $qty($sum['requested_qty']) }}</td>
                    <td class="text-end">{{ $money($sum['requested_value']) }}</td>
                    <td class="text-end">{{ $qty($sum['issued_qty']) }}</td>
                    <td class="text-end">{{ $money($sum['issued_value']) }}</td>
                    <td class="text-end {{ $sum['balance_qty'] < 0 ? 'text-danger' : '' }}">{{ $qty($sum['balance_qty']) }}</td>
                    <td class="text-end {{ $sum['balance_value'] < 0 ? 'text-danger' : '' }}">{{ $money($sum['balance_value']) }}</td>
                    <td class="text-end">{{ $pct($sum['pct']) }}</td><td></td>
                </tr>
            </tbody>
        </table>
    </div>
@else
    @php($sum = $s['purchase']['totals'])
    {{-- One small chart on the full card; dashboards stay light. --}}
    @unless($compact)
        @include('store.stock.requisitions._purchase-chart', ['chart' => $s['charts']['purchase']])
    @endunless
    <div class="table-responsive gx-sum-scroll">
        <table class="table table-sm align-middle mb-0 gx-report-table gx-sum-table">
            <thead>
                <tr>
                    <th class="text-center">SL</th><th>Item</th><th>UOM</th>
                    <th class="text-end">Requested</th><th class="text-end">Current Stock</th>
                    <th class="text-end">Purchased</th><th class="text-end">To Purchase</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $row)
                    <tr>
                        <td class="text-center text-muted small">{{ $offset + $i + 1 }}</td>
                        <td class="fw-semibold">
                            {{ $row['item_name'] }}
                            @unless($row['in_master'])
                                <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ \App\Models\PurchaseRequisitionItem::NOT_IN_MASTER_NOTE }}</span>
                            @endunless
                        </td>
                        <td class="small">{{ $row['uom'] ?: '—' }}</td>
                        <td class="text-end">{{ $qty($row['requested']) }}</td>
                        <td class="text-end">{{ $qty($row['stock_in_hand']) }}</td>
                        <td class="text-end">{{ $qty($row['purchased']) }}</td>
                        <td class="text-end fw-semibold">{{ $qty($row['pending']) }}</td>
                        <td>
                            @if($row['pending'] > 0)
                                <span class="badge bg-warning-subtle text-warning-emphasis">To Purchase</span>
                            @elseif($row['over_received'] > 0)
                                <span class="badge bg-info-subtle text-info-emphasis">Over Received</span>
                            @else
                                <span class="badge bg-success-subtle text-success">Purchased</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @unless($compact)
                    <tr class="gx-sum-total">
                        <td></td><td colspan="2">Total</td>
                        <td class="text-end">{{ $qty($sum['requested']) }}</td>
                        <td class="text-end">{{ $qty($sum['stock_in_hand']) }}</td>
                        <td class="text-end">{{ $qty($sum['purchased']) }}</td>
                        <td class="text-end">{{ $qty($sum['pending']) }}</td><td></td>
                    </tr>
                @endunless
            </tbody>
        </table>
    </div>
@endif

@if($compact)
    <p class="text-muted small mb-0 mt-2">{{ $s['period'] }} · Drafts excluded. Current Stock = today. Purchase and stock are company-wide.
        @if($total > $rows->count()) {{ number_format($total - $rows->count()) }} more on the Stock Summary. @endif
    </p>
@elseif($stockSummaryPage->hasPages())
    <div class="mt-3">{{ $stockSummaryPage->links() }}</div>
@endif

<style>
    .gx-sum-scroll { max-height: 70vh; overflow: auto; }
    .gx-sum-table thead th { position: sticky; top: 0; z-index: 1; white-space: nowrap; }
    .gx-sum-table td, .gx-sum-table th { text-align: left; }
    .gx-sum-table td.text-end, .gx-sum-table th.text-end { text-align: right !important; white-space: nowrap; }
    .gx-sum-table td.text-center, .gx-sum-table th.text-center { text-align: center !important; }
    .gx-sum-table tr.gx-sum-total td { background: #fff8e1 !important; font-weight: 700; }
</style>
