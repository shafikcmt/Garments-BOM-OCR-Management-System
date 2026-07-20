@extends('layouts.app')

@section('title', 'Store Reports')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-file-earmark-bar-graph"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Store</div>
                    <h3 class="app-hero-title mb-0">Stock Reports</h3>
                    <p class="app-hero-copy mb-0">Receive and issue summary by style, buyer or material.</p>
                </div>
            </div>
            @if($canDownload)
                <div class="d-flex gap-2">
                    <a href="{{ route('store.reports.pdf', request()->query()) }}" class="btn btn-outline-danger">
                        <i class="bi bi-file-earmark-pdf me-1"></i>PDF
                    </a>
                    <a href="{{ route('store.reports.excel', request()->query()) }}" class="btn btn-outline-success">
                        <i class="bi bi-file-earmark-excel me-1"></i>Excel
                    </a>
                </div>
            @endif
        </div>
    </div>

    @include('store._flash')

    {{-- Single filter panel: report type + buyer + style + material + date range --}}
    <div class="card border-0 shadow-sm mb-3" style="border-radius:var(--gx-radius);">
        <div class="card-body p-3">
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
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <a href="{{ route('store.reports.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    @error('date_to')
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $message }}</div>
    @enderror

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-header bg-white border-0 pt-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2" style="border-radius:var(--gx-radius) 14px 0 0;">
            <div>
                <div class="fw-semibold">{{ $reportTypes[$type] }} Report</div>
                <div class="text-muted small">{{ $rows->count() }} {{ Str::plural(Str::lower($groupHeading), $rows->count()) }}</div>
            </div>
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>Period Movement follows the date filter. Current Stock Balance is the lifetime ledger closing and ignores it.
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive store-report-preview">
                @include('store.reports._table')
            </div>
        </div>
    </div>
</div>

@endsection

@section('styles')
<style>
    .store-report-preview .report-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .store-report-preview .report-table th {
        background: #f8fafc; color: #475569; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .02em; text-align: right;
        padding: 10px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: bottom;
    }
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
    .store-report-preview .report-table td.empty { text-align: center; color: #94a3b8; padding: 28px 8px; }
    .store-report-preview .report-table tfoot .grand td {
        background: #f1f5f9; font-weight: 700; color: #0f172a; border-top: 2px solid #e2e8f0; border-bottom: 0;
    }
</style>
@endsection
