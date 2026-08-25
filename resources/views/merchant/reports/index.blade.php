@extends('layouts.app')

@section('title', 'Merchandising Reports')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Merchandising Reports'],
    ]" />

    <x-page-header data-aos="fade-down" icon="file-earmark-bar-graph" eyebrow="Reporting"
                   title="Merchandising Reports"
                   copy="BOM lines shown through the columns Merchandising owns.">
        @if($canDownload)
            <x-slot:actions>
                <a href="{{ route('merchant.reports.pdf', request()->query()) }}" class="btn btn-outline-danger">
                    <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>PDF
                </a>
                <a href="{{ route('merchant.reports.excel', request()->query()) }}" class="btn btn-outline-success">
                    <i class="bi bi-file-earmark-excel" aria-hidden="true"></i>Excel
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-flash />

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" action="{{ route('merchant.reports.index') }}" class="row g-2 align-items-end">
            @foreach(['buyer', 'style', 'season', 'gmts_color', 'po_no'] as $filterKey)
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label fw-semibold small mb-1">{{ $reports->filterLabel($filterKey) }}</label>
                    <select name="{{ $filterKey }}" class="form-select">
                        <option value="">All</option>
                        @foreach($options[$filterKey] as $value)
                            <option value="{{ $value }}" {{ ($filters[$filterKey] ?? null) === $value ? 'selected' : '' }}>{{ $value }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
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
                {{-- No leading <i>: components.css collapses any .btn that has
                     one into a 38px icon-only square and hides its label. --}}
                <button class="btn btn-primary flex-grow-1">Apply</button>
                <a href="{{ route('merchant.reports.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </x-card>

    @error('date_to')
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>{{ $message }}</div>
    @enderror

    <x-card body-class="p-0">
        <div class="merchant-report-head d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold">BOM Line Report</div>
                <div class="text-muted small">
                    {{ $rows->total() }} {{ Str::plural('line', $rows->total()) }}
                    &middot; {{ $columns->count() }} Merchandising {{ Str::plural('column', $columns->count()) }}
                </div>
            </div>
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>The date range applies to {{ $reports->dateLabel() }}. Excel carries every column; the PDF prints the summary columns only.
            </div>
        </div>
        <div class="table-responsive merchant-report-preview">
            @include('merchant.reports._table', ['startIndex' => $rows->firstItem() ? $rows->firstItem() - 1 : 0])
        </div>
        @if($rows->hasPages())
            <div class="p-3 border-top">
                {{ $rows->links() }}
            </div>
        @endif
    </x-card>
</div>
@endsection

@section('styles')
<style>
    .merchant-report-head {
        padding: 1rem 1rem .85rem;
        border-bottom: 1px solid var(--gx-surface-border);
    }

    /* 35 columns do not fit any screen, so the table scrolls sideways inside
       the card rather than squashing every column to unreadable width. The
       page body itself never scrolls horizontally. */
    .merchant-report-preview { overflow-x: auto; }
    .merchant-report-preview .report-table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 13px; }
    .merchant-report-preview .report-table th {
        background: var(--gx-bg, #f8fafc); color: #475569; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .02em; text-align: left; white-space: nowrap;
        padding: 10px 8px; border-bottom: 1px solid var(--gx-surface-border); vertical-align: bottom;
    }
    .merchant-report-preview .report-table th.num { text-align: right; }
    .merchant-report-preview .report-table tbody tr { transition: background-color .15s ease; }
    .merchant-report-preview .report-table tbody tr:hover { background: var(--gx-bg, #f8fafc); }
    .merchant-report-preview .report-table td {
        padding: 8px; border-bottom: 1px solid #f1f5f9; color: #0f172a; vertical-align: middle;
        max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .merchant-report-preview .report-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
    /* SL stays visible while the buyer scrolls right through 35 columns — it is
       the only anchor telling them which line they are reading. */
    .merchant-report-preview .report-table th.col-sl,
    .merchant-report-preview .report-table td.col-sl {
        position: sticky; left: 0; z-index: 2; width: 48px; padding-left: 16px;
        background: #fff; border-right: 1px solid var(--gx-surface-border);
    }
    .merchant-report-preview .report-table th.col-sl { background: var(--gx-bg, #f8fafc); }
    .merchant-report-preview .report-table td.empty {
        position: static; text-align: center; color: #94a3b8; padding: 40px 8px; font-size: 13px;
        max-width: none; white-space: normal;
    }
    .merchant-report-preview .report-table tbody tr:has(td.empty):hover { background: transparent; }

    @media (prefers-reduced-motion: reduce) {
        .merchant-report-preview .report-table tbody tr { transition: none; }
    }
</style>
@endsection
