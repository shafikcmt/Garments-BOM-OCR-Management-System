@extends('layouts.app')

@section('title', 'Store Reports')

@section('content')
<div class="container-fluid">
    {{-- Merchandising reaches this screen too, and store.dashboard is closed to
         that role — linking there sent them straight to a 403. The role
         dispatcher resolves to whichever dashboard the viewer actually owns. --}}
    <x-breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Stock Reports'],
    ]" />

    <x-page-header data-aos="fade-down" icon="file-earmark-bar-graph" eyebrow="Reporting"
                   title="Stock Reports"
                   copy="Receive and issue summary by style, buyer or material.">
        @if($canDownload)
            <x-slot:actions>
                <a href="{{ route('store.reports.pdf', request()->query()) }}" class="btn btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('store.reports.excel', request()->query()) }}" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-excel" aria-hidden="true"></i>Excel
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-flash />

    {{-- Single filter panel: report type + buyer + style + material + date range --}}
    <x-card class="mb-3" body-class="p-3">
            <form method="GET" action="{{ route('store.reports.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">Report Type</label>
                    <select name="type" class="form-select">
                        @foreach($reportTypes as $key => $label)
                            <option value="{{ $key }}" {{ $type === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">Buyer</label>
                    <select name="buyer" class="form-select">
                        <option value="">All</option>
                        @foreach($options['buyers'] as $buyer)
                            <option value="{{ $buyer }}" {{ ($filters['buyer'] ?? null) === $buyer ? 'selected' : '' }}>{{ $buyer }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">Style</label>
                    <select name="style" class="form-select">
                        <option value="">All</option>
                        @foreach($options['styles'] as $style)
                            <option value="{{ $style }}" {{ ($filters['style'] ?? null) === $style ? 'selected' : '' }}>{{ $style }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Structured filters, ANDed with everything else on this bar.
                     They wrap onto a second row on smaller screens rather than
                     squeezing the existing controls. --}}
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">Season</label>
                    <select name="season" class="form-select">
                        <option value="">All</option>
                        @foreach($options['seasons'] as $season)
                            <option value="{{ $season }}" {{ ($filters['season'] ?? null) === $season ? 'selected' : '' }}>{{ $season }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">PO Number</label>
                    <select name="po_no" class="form-select">
                        <option value="">All</option>
                        @foreach($options['poNos'] as $poNo)
                            <option value="{{ $poNo }}" {{ ($filters['po_no'] ?? null) === $poNo ? 'selected' : '' }}>{{ $poNo }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">GMTS Color Name</label>
                    <select name="gmts_color" class="form-select">
                        <option value="">All</option>
                        @foreach($options['gmtsColors'] as $gmtsColor)
                            <option value="{{ $gmtsColor }}" {{ ($filters['gmts_color'] ?? null) === $gmtsColor ? 'selected' : '' }}>{{ $gmtsColor }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">Material / SAP Code</label>
                    <input name="material" value="{{ $filters['material'] }}" class="form-control" placeholder="Type to search…">
                </div>
                <div class="col-6 col-md-3 col-xl-1">
                    <label class="form-label fw-semibold small mb-1">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control">
                </div>
                <div class="col-6 col-md-3 col-xl-1">
                    <label class="form-label fw-semibold small mb-1">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control">
                </div>
                <div class="col-12 col-xl-2 d-flex gap-2">
                    {{-- No leading <i>: components.css collapses any .btn that
                         has one into a 38px icon-only square and hides its
                         label, which on a flex-grow button rendered as a wide
                         blue bar showing nothing but a funnel. --}}
                    <button class="btn btn-primary flex-grow-1">Apply</button>
                    <a href="{{ route('store.reports.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
    </x-card>

    @error('date_to')
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>{{ $message }}</div>
    @enderror

    <x-card body-class="p-0">
        <div class="store-report-head d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold">{{ $reportTypes[$type] }} Report</div>
                <div class="text-muted small">{{ $rows->count() }} {{ Str::plural(Str::lower($groupHeading), $rows->count()) }}</div>
            </div>
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Period Movement follows the date filter. Current Stock Balance is the lifetime ledger closing and ignores it.
            </div>
        </div>
        <div class="table-responsive store-report-preview">
            @include('store.reports._table')
        </div>
    </x-card>
</div>

@endsection

@section('styles')
<style>
    /* Card header strip. Was a .card-header with its own radius overrides; the
       card now owns the radius, so this only has to be the divider. */
    .store-report-head {
        padding: 1rem 1rem .85rem;
        border-bottom: 1px solid var(--gx-surface-border);
    }

    .store-report-preview .report-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .store-report-preview .report-table th {
        background: var(--gx-bg, #f8fafc); color: #475569; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .02em; text-align: right;
        padding: 10px 8px; border-bottom: 1px solid var(--gx-surface-border); vertical-align: bottom;
    }
    /* Row hover, the same affordance the Store stock tables give — on a wide
       eleven-column report it is what keeps the eye on one line. */
    .store-report-preview .report-table tbody tr { transition: background-color .15s ease; }
    .store-report-preview .report-table tbody tr:hover { background: var(--gx-bg, #f8fafc); }
    .store-report-preview .report-table th.col-sl,
    .store-report-preview .report-table th.col-group { text-align: left; }
    .store-report-preview .report-table th .sub {
        display: block; font-size: 9.5px; font-weight: 600; text-transform: none;
        letter-spacing: 0; color: #94a3b8; margin-top: 2px;
    }
    .store-report-preview .report-table td {
        padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a; vertical-align: middle;
    }
    .store-report-preview .report-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .store-report-preview .report-table th.col-sl, .store-report-preview .report-table td.col-sl { width: 48px; padding-left: 16px; }
    .store-report-preview .report-table td.col-group { min-width: 220px; max-width: 360px; word-break: break-word; }
    .store-report-preview .report-table td.empty { text-align: center; color: #94a3b8; padding: 40px 8px; font-size: 13px; }
    .store-report-preview .report-table tbody tr:has(td.empty):hover { background: transparent; }
    .store-report-preview .report-table tfoot .grand td {
        background: #f1f5f9; font-weight: 700; color: #0f172a; border-top: 2px solid #e2e8f0; border-bottom: 0;
    }

    @media (prefers-reduced-motion: reduce) {
        .store-report-preview .report-table tbody tr { transition: none; }
    }
</style>
@endsection
