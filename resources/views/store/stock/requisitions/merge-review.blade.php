@extends('layouts.app')

@section('title', 'General Stock — Review Before Merge')

@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $coveredCount = $rows->where('covered', true)->count();
@endphp

@section('styles')
<style>
    .gx-merge-row.is-covered > td { background: #ecfdf3; }
    .gx-merge-row.is-covered > td:first-child { box-shadow: inset 3px 0 0 #16a34a; }
    .gx-merge-row.is-hidden > td { opacity: .5; }
    .gx-merge-row.is-hidden .js-name { text-decoration: line-through; }
    .gx-merge-row .js-qty { width: 96px; text-align: right; }
    .gx-merge-chip { font-size: .72rem; font-weight: 600; }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) .gx-merge-row.is-covered > td { background: rgba(22,163,74,.14); }
    }
    :root[data-theme="dark"] .gx-merge-row.is-covered > td { background: rgba(22,163,74,.14); }
</style>
@endsection

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Requested', 'url' => route('store.stock.requisitions.requested')],
        ['label' => 'Review before merge'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="intersect" eyebrow="General Stock" title="Review Before Merge"
                   copy="Items already in stock are highlighted. Keep each one, reduce its quantity, or hide it from the Final requisition.">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.requested') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Requested
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    <form method="POST" action="{{ route('store.stock.requisitions.merge') }}" id="mergeReviewForm">
        @csrf
        @foreach($sources as $source)
            <input type="hidden" name="ids[]" value="{{ $source->id }}">
        @endforeach

        <div class="card gx-stock-card mb-4">
            <div class="gx-stock-card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <div class="small text-muted">
                        Merging {{ $sources->count() }} requisition{{ $sources->count() === 1 ? '' : 's' }}:
                        <strong>{{ $sources->pluck('requisition_no')->implode(', ') }}</strong>
                    </div>
                    <div class="ms-auto d-flex flex-wrap gap-2">
                        @if($coveredCount > 0)
                            <button type="button" class="btn btn-sm btn-outline-success" data-bulk="hide-covered">
                                <i class="bi bi-eye-slash me-1" aria-hidden="true"></i>Hide items in stock ({{ $coveredCount }})
                            </button>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="needed">
                            <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Reduce all to needed qty
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="reset">
                            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle gx-stock-table mb-0">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Sections</th>
                                <th class="text-end">Requested</th>
                                <th class="text-end">In stock</th>
                                <th class="text-end">Needed</th>
                                <th class="text-end">Final qty</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                <tr class="gx-merge-row {{ $row['covered'] ? 'is-covered' : '' }}"
                                    data-requested="{{ $row['requested'] }}" data-needed="{{ $row['needed'] }}" data-covered="{{ $row['covered'] ? 1 : 0 }}">
                                    <td>
                                        <div class="fw-semibold js-name">{{ $row['name'] }}</div>
                                        <div class="small text-muted">
                                            @if($row['sku']){{ $row['sku'] }} · @endif{{ $row['uom'] }}
                                        </div>
                                    </td>
                                    <td class="small">{{ $row['sections'] ?: '—' }}</td>
                                    <td class="text-end">{{ $qty($row['requested']) }}</td>
                                    <td class="text-end">
                                        @if($row['stock'] === null)
                                            <span class="text-muted">—</span>
                                        @else
                                            {{ $qty($row['stock']) }}
                                        @endif
                                        @if($row['covered'])
                                            <div><span class="badge bg-success-subtle text-success gx-merge-chip">In stock</span></div>
                                        @elseif($row['partial'])
                                            <div><span class="badge bg-warning-subtle text-warning-emphasis gx-merge-chip">Partly in stock</span></div>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">{{ $qty($row['needed']) }}</td>
                                    <td class="text-end">
                                        <input type="hidden" name="lines[{{ $row['key'] }}][include]" value="1" class="js-include">
                                        <input type="number" name="lines[{{ $row['key'] }}][qty]" value="{{ $row['requested'] }}"
                                               min="0" step="any" class="form-control form-control-sm d-inline-block js-qty"
                                               aria-label="Final quantity for {{ $row['name'] }}">
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-needed">
                                            <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Needed qty
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-toggle">
                                            <i class="bi bi-eye-slash me-1" aria-hidden="true"></i><span>Hide</span>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-3 mt-3 pt-3 border-top">
                    <span class="small text-muted" id="mergeSummary"></span>
                    <button type="submit" class="btn btn-primary ms-auto" id="mergeCreate">
                        <i class="bi bi-intersect me-1" aria-hidden="true"></i>Create Final Requisition
                    </button>
                </div>
                <p class="gx-stock-help mt-2 mb-0">
                    Hidden items stay on the department requisitions; they are only left off the Final one.
                    The Final requisition is a draft: you can still edit it before submitting.
                </p>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        var rows = Array.prototype.slice.call(document.querySelectorAll('.gx-merge-row'));
        var summary = document.getElementById('mergeSummary');
        var create = document.getElementById('mergeCreate');

        function setHidden(row, hidden) {
            row.classList.toggle('is-hidden', hidden);
            row.querySelector('.js-include').value = hidden ? '0' : '1';
            row.querySelector('.js-qty').disabled = hidden;
            var btn = row.querySelector('.js-toggle');
            btn.querySelector('span').textContent = hidden ? 'Show' : 'Hide';
            btn.querySelector('i').className = 'bi me-1 ' + (hidden ? 'bi-eye' : 'bi-eye-slash');
            refresh();
        }

        function setQty(row, value) {
            row.querySelector('.js-qty').value = value;
            refresh();
        }

        function refresh() {
            var on = rows.filter(function (r) {
                return r.querySelector('.js-include').value === '1' && parseFloat(r.querySelector('.js-qty').value) > 0;
            });
            summary.textContent = on.length + ' of ' + rows.length + ' items will be on the Final requisition.';
            create.disabled = on.length === 0;
        }

        rows.forEach(function (row) {
            row.querySelector('.js-toggle').addEventListener('click', function () {
                setHidden(row, !row.classList.contains('is-hidden'));
            });
            row.querySelector('.js-needed').addEventListener('click', function () {
                var needed = parseFloat(row.dataset.needed);
                if (needed <= 0) { setHidden(row, true); return; }
                setHidden(row, false);
                setQty(row, needed);
            });
            row.querySelector('.js-qty').addEventListener('input', refresh);
        });

        document.querySelectorAll('[data-bulk]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                rows.forEach(function (row) {
                    var mode = btn.dataset.bulk;
                    if (mode === 'hide-covered' && row.dataset.covered === '1') { setHidden(row, true); }
                    if (mode === 'needed') { row.querySelector('.js-needed').click(); }
                    if (mode === 'reset') { setHidden(row, false); setQty(row, row.dataset.requested); }
                });
            });
        });

        refresh();
    })();
</script>
@endsection
