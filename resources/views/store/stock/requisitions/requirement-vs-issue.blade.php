@extends('layouts.app')

@section('title', 'General Stock — Requirement vs Issue')

@php
    $RVI = \App\Services\RequirementVsIssueService::class;

    $qty = fn ($v) => \App\Support\Quantity::format($v);
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%';

    // A link to this page with the current filters, changed as given.
    $url = fn (array $change = []) => route('store.stock.requisitions.requirement-vs-issue', array_filter(
        array_merge($query, $change), fn ($v) => $v !== null && $v !== '' && $v !== []
    ));
    $without = function (string $key, $value = null) use ($query, $url) {
        $current = $query[$key] ?? null;
        $next = is_array($current) && $value !== null
            ? array_values(array_filter($current, fn ($v) => (string) $v !== (string) $value))
            : null;

        return $url([$key => $next]);
    };

    $months = [];
    $m = \App\Support\LocalTime::now()->startOfMonth();
    for ($i = 0; $i < 24; $i++) { $months[$m->format('Y-m')] = $m->format('F Y'); $m = $m->copy()->subMonth(); }
    foreach ([$filters['from'], $filters['to']] as $chosen) {
        $months[$chosen->format('Y-m')] ??= $chosen->format('F Y');
    }
    krsort($months);

    $statusTone = [
        $RVI::STATUS_FULL => 'bg-success-subtle text-success',
        $RVI::STATUS_PARTIAL => 'bg-warning-subtle text-warning-emphasis',
        $RVI::STATUS_NOT_ISSUED => 'bg-secondary-subtle text-secondary-emphasis',
        $RVI::STATUS_OVER => 'bg-danger-subtle text-danger',
        $RVI::STATUS_NOT_REQUESTED => 'bg-info-subtle text-info-emphasis',
    ];

    $sectionNames = $options['sections']->pluck('name', 'id');
    $chips = [];
    foreach ($filters['departments'] as $k) {
        $chips[] = ['Dept: '.($k === $RVI::NO_DEPARTMENT ? 'No department' : ($options['departments'][$k] ?? $k)), $without('departments', $k)];
    }
    foreach ($filters['sections'] as $id) {
        $chips[] = ['Section: '.($sectionNames[(int) $id] ?? $id), $without('sections', $id)];
    }
    foreach ($filters['categories'] as $k) {
        $chips[] = ['Category: '.($k === $RVI::UNCATEGORIZED ? 'Uncategorized' : ($options['categories'][$k] ?? $k)), $without('categories', $k)];
    }
    foreach ($filters['statuses'] as $s) {
        $chips[] = ['Status: '.$statusLabels[$s], $without('statuses', $s)];
    }
    if ($filters['requisition_no'] !== '') { $chips[] = ['Requisition: '.$filters['requisition_no'], $without('requisition_no')]; }
    if ($filters['search'] !== '') { $chips[] = ['Search: "'.$filters['search'].'"', $without('search')]; }

    $moreOpen = $filters['categories'] !== [] || $filters['requisition_no'] !== '';

    $sortLink = function (string $col, string $label, string $class = '') use ($filters, $url) {
        $active = $filters['sort'] === $col;
        $dir = $active && $filters['dir'] === 'desc' ? 'asc' : 'desc';
        $icon = $active ? ($filters['dir'] === 'desc' ? 'bi-sort-down' : 'bi-sort-up') : 'bi-arrow-down-up opacity-25';

        return '<a href="'.e($url(['sort' => $col, 'dir' => $dir])).'" class="text-reset text-decoration-none '.$class.'">'
            .e($label).' <i class="bi '.$icon.'" aria-hidden="true"></i></a>';
    };
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Requirement vs Issue'],
    ]" />

    @include('store.stock._stock-ui')
    @include('store.stock._searchable')

    <x-page-header icon="clipboard-data" eyebrow="General Stock" title="Requirement vs Issue"
                   copy="Requested quantities against what the Store issued, by section and item.">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.requirement-vs-issue.excel', $query) }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Download Excel
            </a>
            <a href="{{ route('store.stock.requisitions.requirement-vs-issue.pdf', $query) }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download PDF
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')
    @include('store.stock.requisitions._tabs', ['active' => 'requirement'])

    {{-- Filters: one compact bar; the less-used ones sit under More filters. --}}
    <div class="card gx-stock-card mb-3">
        <div class="gx-stock-card-body">
            <form method="GET" action="{{ route('store.stock.requisitions.requirement-vs-issue') }}" id="rviFilters">
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <input type="hidden" name="dir" value="{{ $filters['dir'] }}">
                <div class="row g-2 gx-stock-filter">
                    <div class="col-6 col-md-3 col-xl-1">
                        <label class="form-label small fw-semibold mb-1" for="rviFrom">From</label>
                        <select name="from" id="rviFrom" class="form-select form-select-sm">
                            @foreach($months as $value => $label)
                                <option value="{{ $value }}" @selected($filters['from']->format('Y-m') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-1">
                        <label class="form-label small fw-semibold mb-1" for="rviTo">To</label>
                        <select name="to" id="rviTo" class="form-select form-select-sm">
                            @foreach($months as $value => $label)
                                <option value="{{ $value }}" @selected($filters['to']->format('Y-m') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label small fw-semibold mb-1" for="rviDepartments">Department</label>
                        <select name="departments[]" id="rviDepartments" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="All departments">
                            @foreach($options['departments'] as $key => $label)
                                <option value="{{ $key }}" @selected(in_array((string) $key, $filters['departments'], true))>{{ $label }}</option>
                            @endforeach
                            <option value="{{ $RVI::NO_DEPARTMENT }}" @selected(in_array($RVI::NO_DEPARTMENT, $filters['departments'], true))>No department</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label small fw-semibold mb-1" for="rviSections">Section</label>
                        {{-- Every section is rendered with its department; the
                             script below lists only those of the checked
                             departments, and the server applies the same rule. --}}
                        <select name="sections[]" id="rviSections" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="All sections">
                            @foreach($options['sections']->groupBy('department') as $department => $sections)
                                <optgroup label="{{ $department }}">
                                    @foreach($sections as $section)
                                        <option value="{{ $section['id'] }}" data-department="{{ $section['department_key'] ?? $RVI::NO_DEPARTMENT }}"
                                                @selected(in_array((string) $section['id'], $filters['sections'], true))>{{ $section['name'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label small fw-semibold mb-1" for="rviStatuses">Issue Status</label>
                        <select name="statuses[]" id="rviStatuses" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="All statuses">
                            @foreach($statusLabels as $key => $label)
                                <option value="{{ $key }}" @selected(in_array($key, $filters['statuses'], true))>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label small fw-semibold mb-1" for="rviSearch">Search</label>
                        <input type="search" name="search" id="rviSearch" class="form-control form-control-sm" value="{{ $filters['search'] }}" placeholder="Item or specification" maxlength="100">
                    </div>
                    <div class="col-12 col-xl-2 gx-stock-filter-actions d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply</button>
                        <a href="{{ route('store.stock.requisitions.requirement-vs-issue') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rviMore" aria-expanded="{{ $moreOpen ? 'true' : 'false' }}" aria-controls="rviMore">
                            <i class="bi bi-sliders me-1" aria-hidden="true"></i>More filters
                        </button>
                    </div>
                </div>
                <div class="collapse {{ $moreOpen ? 'show' : '' }}" id="rviMore">
                    <div class="row g-2 gx-stock-filter mt-1">
                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label small fw-semibold mb-1" for="rviCategories">Item Category</label>
                            <select name="categories[]" id="rviCategories" class="form-select form-select-sm js-searchable" multiple data-checkboxes placeholder="All categories">
                                @foreach($options['categories'] as $id => $name)
                                    <option value="{{ $id }}" @selected(in_array((string) $id, $filters['categories'], true))>{{ $name }}</option>
                                @endforeach
                                <option value="{{ $RVI::UNCATEGORIZED }}" @selected(in_array($RVI::UNCATEGORIZED, $filters['categories'], true))>Uncategorized</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <label class="form-label small fw-semibold mb-1" for="rviRequisition">Requisition No.</label>
                            <input type="text" name="requisition_no" id="rviRequisition" class="form-control form-control-sm" value="{{ $filters['requisition_no'] }}" placeholder="e.g. HAPL/CUTTING/2026" maxlength="100">
                        </div>
                    </div>
                </div>
            </form>

            @if($chips !== [])
                <div class="d-flex flex-wrap gap-2 mt-3">
                    @foreach($chips as [$label, $removeUrl])
                        <a href="{{ $removeUrl }}" class="badge rounded-pill bg-primary-subtle text-primary text-decoration-none fw-semibold">
                            {{ $label }} <span aria-hidden="true">×</span><span class="visually-hidden">Remove filter</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <p class="text-muted small mb-3">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ $period }} ·
        Requested quantities come from department requisitions. Final-sheet items not linked to a section are shown as Not assigned.
        Item categories are used until requisition groups exist.
    </p>

    @if($rows->isEmpty())
        <div class="card gx-stock-card">
            <div class="gx-stock-empty">
                <span class="gx-stock-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>
                <div class="gx-stock-empty-title">No matching items</div>
                <div class="gx-stock-empty-hint">Change the period or remove a filter to see more.</div>
            </div>
        </div>
    @else
        {{-- Cards --}}
        <div class="row g-3 mb-2">
            @foreach([
                ['label' => 'Requested Value', 'value' => $money($summary['requested_value']), 'tone' => 'primary', 'icon' => 'bi-clipboard-check'],
                ['label' => 'Issued Value', 'value' => $money($summary['issued_value']), 'tone' => 'success', 'icon' => 'bi-box-arrow-right'],
                ['label' => 'Balance Value', 'value' => $money($summary['balance_value']), 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
            ] as $tile)
                <div class="col-6 col-xl-3">
                    <div class="card gx-stock-card h-100">
                        <div class="gx-stock-tile-body">
                            <span class="gx-stock-tile-icon bg-{{ $tile['tone'] }}-subtle text-{{ $tile['tone'] }}"><i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i></span>
                            <div>
                                <div class="gx-stock-tile-label">{{ $tile['label'] }} (BDT)</div>
                                <div class="gx-stock-tile-value">{{ $tile['value'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="col-6 col-xl-3">
                <div class="card gx-stock-card h-100">
                    <div class="gx-stock-tile-body">
                        <span class="gx-stock-tile-icon bg-info-subtle text-info"><i class="bi bi-percent" aria-hidden="true"></i></span>
                        <div class="flex-grow-1">
                            <div class="gx-stock-tile-label">Issued %</div>
                            <div class="gx-stock-tile-value">{{ $pct($summary['pct']) }}</div>
                            <div class="progress mt-1" style="height:6px;" role="progressbar" aria-label="Issued %" aria-valuenow="{{ (int) min(100, $summary['pct'] ?? 0) }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar bg-info" style="width: {{ min(100, $summary['pct'] ?? 0) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="small mb-3">
            @foreach($statusLabels as $key => $label)
                <a href="{{ $url(['statuses' => [$key]]) }}" class="text-decoration-none">{{ number_format($summary['counts'][$key]) }} {{ strtolower($label) }}</a>@if(! $loop->last) · @endif
            @endforeach
            @if($summary['not_requested_qty'] > 0)
                <span class="text-muted">— Not Requested issued value {{ $money($summary['not_requested_value']) }} BDT, kept out of the totals and Issued %.</span>
            @endif
            @if($summary['unrated'] > 0)
                <span class="text-muted">— {{ $summary['unrated'] }} item(s) have no rate and are left out of the values.</span>
            @endif
        </p>

        {{-- Charts: screen only, from the same rows and totals as the table. --}}
        @include('store.stock.requisitions._rvi-charts', [
            'charts' => $charts,
            'sectionUrl' => fn (int $id) => $url(['sections' => [(string) $id]]),
            'itemUrl' => fn (string $name) => $url(['search' => $name]),
        ])

        {{-- Section table, when more than one section is in view. --}}
        @if($sections->count() > 1)
            <div class="card gx-stock-card mb-3">
                <div class="gx-stock-card-body">
                    <div class="gx-stock-card-head"><h5 class="mb-0">By Section</h5></div>
                    <div class="table-responsive" style="max-height: 320px;">
                        <table class="table table-sm table-hover align-middle mb-0 gx-report-table">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Section</th>
                                    <th class="text-end">Items</th>
                                    <th class="text-end">Requested Value</th>
                                    <th class="text-end">Issued Value</th>
                                    <th style="min-width:160px;">Issued %</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sections as $s)
                                    <tr>
                                        <td class="small">{{ $s['department'] }}</td>
                                        <td class="fw-semibold">
                                            @if($s['section_id'] !== null)
                                                <a href="{{ $url(['sections' => [(string) $s['section_id']]]) }}" class="text-decoration-none">{{ $s['section_name'] }}</a>
                                            @else
                                                {{ $s['section_name'] }}
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format($s['items']) }}</td>
                                        <td class="text-end">{{ $money($s['requested_value']) }}</td>
                                        <td class="text-end">{{ $money($s['issued_value']) }}</td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height:6px;" role="progressbar" aria-label="Issued %" aria-valuenow="{{ (int) min(100, $s['pct'] ?? 0) }}" aria-valuemin="0" aria-valuemax="100">
                                                    <div class="progress-bar bg-info" style="width: {{ min(100, $s['pct'] ?? 0) }}%"></div>
                                                </div>
                                                <span class="small text-nowrap">{{ $pct($s['pct']) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        {{-- Item table: totals above and below, as in the reference file. --}}
        @php
            $totalsRow = function () use ($summary, $qty, $money, $pct) {
                return '<tr class="rvi-total"><td></td><td colspan="5" class="fw-bold">Total</td>'
                    .'<td class="text-end">'.$qty($summary['requested_qty']).'</td>'
                    .'<td class="text-end">'.$money($summary['requested_value']).'</td>'
                    .'<td class="text-end">'.$qty($summary['issued_qty']).'</td>'
                    .'<td class="text-end">'.$money($summary['issued_value']).'</td>'
                    .'<td class="text-end'.($summary['balance_qty'] < 0 ? ' text-danger' : '').'">'.$qty($summary['balance_qty']).'</td>'
                    .'<td class="text-end'.($summary['balance_value'] < 0 ? ' text-danger' : '').'">'.$money($summary['balance_value']).'</td>'
                    .'<td class="text-end">'.$pct($summary['pct']).'</td><td></td></tr>';
            };
        @endphp
        <div class="card gx-stock-card">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5 class="mb-0">Items <span class="badge bg-primary-subtle text-primary ms-1">{{ number_format($rows->count()) }}</span></h5>
                </div>
                <div class="table-responsive rvi-scroll">
                    <table class="table table-sm align-middle mb-0 gx-report-table rvi-table">
                        <thead>
                            <tr>
                                <th class="text-center">SL</th>
                                <th>{!! $sortLink('item', 'Item Name') !!}</th>
                                <th>UOM</th>
                                <th>Specification / Brand</th>
                                <th>{!! $sortLink('section', 'User Dept./Section') !!}</th>
                                <th class="text-end">{!! $sortLink('rate', 'Approx Rate') !!}</th>
                                <th class="text-end">{!! $sortLink('requested_qty', 'Requested Qty') !!}</th>
                                <th class="text-end">{!! $sortLink('requested_value', 'Requested Value (BDT)') !!}</th>
                                <th class="text-end">{!! $sortLink('issued_qty', 'Issued Qty') !!}</th>
                                <th class="text-end">{!! $sortLink('issued_value', 'Issued Value (BDT)') !!}</th>
                                <th class="text-end">{!! $sortLink('balance_qty', 'Issue Bal. Qty') !!}</th>
                                <th class="text-end">{!! $sortLink('balance_value', 'Issue Bal. Value') !!}</th>
                                <th class="text-end">{!! $sortLink('pct', 'Issued %') !!}</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!! $totalsRow() !!}
                            @foreach($rows as $i => $row)
                                <tr>
                                    <td class="text-center text-muted small">{{ $i + 1 }}</td>
                                    <td class="fw-semibold">{{ $row['item_name'] }}</td>
                                    <td class="small">{{ $row['uom'] ?: '—' }}</td>
                                    <td class="small">{{ $row['specification'] ?: '—' }}</td>
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
                            {!! $totalsRow() !!}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <style>
        .rvi-scroll { max-height: 70vh; overflow: auto; }
        .rvi-table thead th { position: sticky; top: 0; z-index: 1; white-space: nowrap; font-size: .72rem; }
        .rvi-table td.text-end { white-space: nowrap; }
        .rvi-table tr.rvi-total td { background: #fff8e1 !important; font-weight: 700; }
        .rvi-table td, .rvi-table th { text-align: left; }
        .rvi-table td.text-end, .rvi-table th.text-end { text-align: right !important; }
        .rvi-table td.text-center, .rvi-table th.text-center { text-align: center !important; }
    </style>

    <script>
        // Dependent Section list: only sections of the checked departments;
        // all sections when none is checked. The server applies the same rule.
        document.addEventListener('DOMContentLoaded', function () {
            var dept = document.getElementById('rviDepartments');
            var sect = document.getElementById('rviSections');
            if (!dept || !sect) { return; }

            var all = Array.prototype.map.call(sect.querySelectorAll('option'), function (o) {
                return { value: o.value, text: o.textContent.trim(), department: o.getAttribute('data-department'),
                         optgroup: o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.getAttribute('label') : null };
            });

            function sync() {
                var ts = sect.tomselect;
                if (!ts) { return; }
                var chosen = dept.tomselect ? dept.tomselect.getValue() : [];
                var keep = ts.getValue();
                var allowed = all.filter(function (o) { return chosen.length === 0 || chosen.indexOf(o.department) !== -1; });

                ts.clearOptions();
                allowed.forEach(function (o) { ts.addOption({ value: o.value, text: o.text, optgroup: o.optgroup }); });
                ts.setValue(keep.filter(function (v) { return allowed.some(function (o) { return o.value === v; }); }), true);
                ts.refreshOptions(false);
            }

            // TomSelect initialises on DOMContentLoaded too; wait a tick.
            setTimeout(function () {
                sync();
                if (dept.tomselect) { dept.tomselect.on('change', sync); }
            }, 0);
        });
    </script>
</div>
@endsection
