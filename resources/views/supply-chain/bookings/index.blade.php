@extends('layouts.app')

@section('title', 'Booking Preview / Booking Generate')

@section('styles')
<style>
    :root {
        --booking-primary: #4338ca;
        --booking-primary-dark: #1e1b4b;
        --booking-primary-soft: #eef2ff;
        --booking-ink: #0f172a;
        --booking-muted: #64748b;
        --booking-border: #e2e8f0;
        --booking-card-shadow: 0 24px 70px rgba(15, 23, 42, .08);
    }
    .booking-page {
        min-height: calc(100vh - 110px);
        background:
            radial-gradient(circle at 8% 0%, rgba(99, 102, 241, .14), transparent 30%),
            radial-gradient(circle at 92% 8%, rgba(14, 165, 233, .12), transparent 28%),
            linear-gradient(180deg, #f8fafc 0%, #eff6ff 100%);
    }
    .booking-shell { max-width: 1480px; margin: 0 auto; }
    .booking-hero {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, #11114f 0%, #27206f 58%, #4438ca 100%);
        color: #fff;
        border-radius: 24px;
        padding: 28px 30px;
        box-shadow: 0 22px 58px rgba(67, 56, 202, .24);
    }
    .booking-hero::before,
    .booking-hero::after {
        content: "";
        position: absolute;
        border-radius: 999px;
        pointer-events: none;
    }
    .booking-hero::before {
        right: -92px;
        top: -92px;
        width: 290px;
        height: 290px;
        background: rgba(255,255,255,.11);
    }
    .booking-hero::after {
        right: 120px;
        bottom: -140px;
        width: 220px;
        height: 220px;
        background: rgba(125, 211, 252, .12);
    }
    .booking-hero > * { position: relative; z-index: 1; }
    .booking-hero h4 { letter-spacing: -.55px; font-size: clamp(1.35rem, 2vw, 1.85rem); }
    .booking-hero-copy { max-width: 680px; }
    .booking-hero-icon {
        width: 64px;
        height: 64px;
        flex: 0 0 64px;
        border-radius: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.1);
        border: 1px solid rgba(255,255,255,.18);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.16);
        font-size: 30px;
    }
    .booking-hero-stats { min-width: 350px; justify-content: flex-end; }
    .booking-stat {
        min-width: 170px;
        display: inline-flex;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        border-radius: 18px;
        background: rgba(255,255,255,.96);
        color: var(--booking-ink);
        box-shadow: 0 18px 35px rgba(15, 23, 42, .16);
        border: 1px solid rgba(255,255,255,.65);
    }
    .booking-stat-icon {
        width: 38px;
        height: 38px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--booking-primary);
        background: #eef2ff;
        font-size: 18px;
        flex: 0 0 38px;
    }
    .booking-stat-label { display: block; font-size: 12px; color: var(--booking-muted); font-weight: 700; line-height: 1.1; }
    .booking-stat-value { display: block; margin-top: 3px; font-size: 24px; line-height: 1; color: var(--booking-ink); font-weight: 900; letter-spacing: -.03em; }
    .booking-card {
        border: 1px solid rgba(226, 232, 240, .9);
        border-radius: 24px;
        box-shadow: var(--booking-card-shadow);
        overflow: visible;
        background: rgba(255,255,255,.94);
        backdrop-filter: blur(12px);
    }
    .booking-card .card-header {
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        border-bottom: 1px solid var(--booking-border);
        border-radius: 24px 24px 0 0;
    }
    .booking-section-title { display: flex; align-items: center; gap: 12px; color: var(--booking-ink); }
    .booking-title-icon {
        width: 40px;
        height: 40px;
        border-radius: 15px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--booking-primary-soft);
        color: var(--booking-primary);
        font-size: 18px;
    }
    .booking-filter label {
        font-size: 11px;
        font-weight: 900;
        color: #1e293b;
        text-transform: uppercase;
        letter-spacing: .055em;
    }
    .booking-filter .form-control,
    .booking-filter .form-select {
        min-height: 48px;
        border-radius: 14px;
        border-color: #dbe4f0;
        color: var(--booking-ink);
        box-shadow: none;
        font-weight: 600;
        background-color: #fff;
    }
    .booking-filter .form-control::placeholder { color: #94a3b8; font-weight: 500; }
    .booking-filter .form-control:focus,
    .booking-filter .form-select:focus {
        border-color: #818cf8;
        box-shadow: 0 0 0 .22rem rgba(99, 102, 241, .13);
    }
    .booking-search-field { position: relative; }
    .booking-search-field .form-control { padding-left: 46px; }
    .booking-search-icon {
        position: absolute;
        left: 16px;
        bottom: 14px;
        color: #64748b;
        font-size: 18px;
        pointer-events: none;
    }
    .booking-primary-btn,
    .btn-generate {
        border: 0;
        border-radius: 14px;
        background: linear-gradient(135deg, #4f46e5, #312e81);
        color: #fff;
        font-weight: 900;
        min-height: 42px;
        box-shadow: 0 14px 28px rgba(79, 70, 229, .24);
    }
    .booking-primary-btn:hover,
    .btn-generate:hover { color: #fff; background: linear-gradient(135deg, #4338ca, #1e1b4b); transform: translateY(-1px); }
    .btn-soft-reset {
        border-radius: 14px;
        min-height: 42px;
        border-color: #d8e0ea;
        color: #475569;
        font-weight: 800;
        background: #fff;
    }
    .btn-soft-reset:hover { background: #f8fafc; color: var(--booking-ink); }
    .booking-toolbar {
        background: #fbfdff;
        border-bottom: 1px solid var(--booking-border);
    }
    .booking-table-wrap { position: relative; min-height: 180px; }
    .booking-table-wrap .table-responsive { overflow: visible; }
    .booking-loading {
        display: none;
        position: absolute;
        inset: 0;
        z-index: 20;
        background: rgba(255,255,255,.78);
        backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
        font-weight: 900;
        color: var(--booking-primary);
    }
    .booking-loading.show { display: flex; }
    .booking-table {
        --bs-table-hover-bg: #f8faff;
        border-color: #e6edf5 !important;
    }
    .booking-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: linear-gradient(180deg, #f3f4ff 0%, #eef2ff 100%) !important;
        color: #1e1b4b;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .01em;
        white-space: nowrap;
        vertical-align: middle;
        padding: 14px 12px;
        border-color: #dde4f2 !important;
    }
    .booking-table tbody td {
        vertical-align: middle;
        font-size: 13px;
        padding: 13px 12px;
        line-height: 1.32;
        color: #172033;
    }
    .booking-table tbody tr:nth-child(even) { background: #fcfdff; }
    .booking-table tbody tr:hover { background: #f6f7ff; }
    .item-cell .small { line-height: 1.25; }
    .action-cell .btn { border-radius: 12px; padding: 8px 14px; }
    .preview-outline-btn {
        border: 1px solid #c7d2fe;
        color: var(--booking-primary);
        background: #fff;
        font-weight: 900;
        box-shadow: 0 8px 18px rgba(67, 56, 202, .08);
    }
    .preview-outline-btn:hover { background: #eef2ff; color: #312e81; border-color: #a5b4fc; }
    .booking-action-wrap { white-space: nowrap; }
    .booking-kebab-btn {
        width: 38px;
        height: 38px;
        padding: 0 !important;
        border-radius: 13px !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff;
        color: #334155;
        border: 1px solid #dbe4f0;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }
    .booking-kebab-btn:hover,
    .booking-kebab-btn:focus { background: #eef2ff; color: var(--booking-primary); border-color: #c7d2fe; }
    .booking-action-dropdown {
        min-width: 190px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 8px;
        box-shadow: 0 22px 45px rgba(15, 23, 42, .16);
        z-index: 1080;
    }
    .booking-action-dropdown .dropdown-item {
        border-radius: 10px;
        padding: 9px 10px;
        font-weight: 700;
        color: #334155;
        font-size: 13px;
    }
    .booking-action-dropdown .dropdown-item:hover { background: #eef2ff; color: #312e81; }
    .po-pill,
    .po-mini-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #eef2ff;
        color: #312e81;
        font-weight: 900;
        font-size: 12px;
    }
    .po-mini-pill { max-width: 112px; overflow: hidden; text-overflow: ellipsis; }
    .booking-empty { min-height:190px; display:flex; align-items:center; justify-content:center; }
    .booking-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }
    .booking-pagination .page-group { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .booking-pagination a,
    .booking-pagination span.page-num {
        min-width: 38px;
        height: 36px;
        padding: 0 12px;
        border: 1px solid #dbe4f0;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: var(--booking-ink);
        background: #fff;
        font-weight: 900;
        font-size: 12px;
    }
    .booking-pagination a:hover { background: #eef2ff; border-color: #c7d2fe; color: #312e81; }
    .booking-pagination .active { background: #4f46e5 !important; border-color: #4f46e5 !important; color: #fff !important; box-shadow: 0 12px 24px rgba(79, 70, 229, .24); }
    .booking-pagination .disabled { opacity: .48; cursor: not-allowed; background: #f8fafc !important; }
    .booking-preview-modal .modal-dialog { max-width: min(980px, 97vw); }
    .booking-preview-modal .modal-content { border-radius: 20px; box-shadow: 0 30px 90px rgba(15, 23, 42, .32); }
    .booking-preview-modal .modal-header { background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); gap: 10px; }
    .booking-preview-title-wrap { flex: 1 1 auto; min-width: 250px; }
    .booking-preview-header-tools { display: inline-flex; align-items: center; gap: 6px; flex-wrap: nowrap; margin-left: auto; }
    .booking-preview-zoom-btn { width: 32px; height: 30px; padding: 0; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; }
    .booking-preview-zoom-value { min-width: 48px; text-align: center; font-size: 12px; font-weight: 900; color: #312e81; }
    .booking-preview-inner { max-height: 86vh; overflow: auto; background: #edf3f8; padding: .5rem !important; }
    .text-slate-900 { color:#0f172a !important; }
    .text-slate-700 { color:#334155 !important; }
    .text-slate-300 { color:#cbd5e1 !important; }
    @media (max-width: 991px) {
        .booking-hero-stats { min-width: 100%; justify-content: flex-start; }
        .booking-stat { flex: 1 1 170px; }
    }
    @media (max-width: 767px) {
        .booking-page { padding: 8px !important; }
        .booking-hero { border-radius: 18px; padding: 20px; }
        .booking-hero-icon { width: 52px; height: 52px; flex-basis: 52px; border-radius: 18px; }
        .booking-table-wrap .table-responsive { overflow-x: auto; }
        .booking-table thead th, .booking-table tbody td { padding: 10px 8px; }
        .booking-preview-modal .modal-header { align-items: flex-start; }
        .booking-preview-header-tools { order: 3; width: 100%; justify-content: flex-start; margin-left: 0; }
    }

    .revision-mini-pill,
    .needs-regenerate-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border-radius: 999px;
        padding: 3px 8px;
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
    }
    .revision-mini-pill { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
    .needs-regenerate-pill { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
</style>
@endsection

@section('content')
@php
    $selectedBuyer = request('buyer');
    $selectedSeason = request('season');
    $selectedVendor = request('vendor');
    $selectedIhod = request('ihod');
    $selectedKeyword = request('keyword');
@endphp

<div class="booking-page p-2 p-md-3"><div class="booking-shell">
    <div class="booking-hero mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3 booking-hero-copy">
            <span class="booking-hero-icon"><i class="bi bi-file-earmark-text"></i></span>
            <div>
                <h4 class="mb-1 fw-bold">Booking Preview / Booking Generate</h4>
                <div class="small opacity-75">Supply-chain module: filter workspace orders, preview booking format first, then generate PO from the preview.</div>
            </div>
        </div>
        <div class="booking-hero-stats d-flex gap-3 flex-wrap">
            <span class="booking-stat">
                <span class="booking-stat-icon"><i class="bi bi-clipboard-check"></i></span>
                <span><span class="booking-stat-label">Pending / Applied</span><span class="booking-stat-value" id="pendingTotal">{{ $pendingRows->total() }}</span></span>
            </span>
            <span class="booking-stat">
                <span class="booking-stat-icon"><i class="bi bi-clock-history"></i></span>
                <span><span class="booking-stat-label">Recent PO</span><span class="booking-stat-value" id="recentTotal">{{ $generatedPos->count() }}</span></span>
            </span>
        </div>
    </div>

    <div id="bookingAjaxAlert" class="alert d-none" role="alert"></div>

    <div class="card booking-card mb-4">
        <div class="card-header py-3">
            <h6 class="mb-0 fw-bold booking-section-title"><span class="booking-title-icon"><i class="bi bi-funnel"></i></span>Filter pending booking data</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('supply_chain.bookings.index') }}" class="booking-filter" id="bookingFilterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Buyer</label>
                        <select name="buyer" class="form-select booking-auto-filter">
                            <option value="">All Buyer</option>
                            @foreach($filterOptions['buyers'] as $buyer)
                                <option value="{{ $buyer }}" @selected($selectedBuyer === $buyer)>{{ $buyer }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Season</label>
                        <select name="season" class="form-select booking-auto-filter">
                            <option value="">All Season</option>
                            @foreach($filterOptions['seasons'] as $season)
                                <option value="{{ $season }}" @selected($selectedSeason === $season)>{{ $season }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vendor</label>
                        <select name="vendor" class="form-select booking-auto-filter">
                            <option value="">All Vendor / Supplier</option>
                            @foreach($filterOptions['vendors'] as $vendor)
                                <option value="{{ $vendor }}" @selected($selectedVendor === $vendor)>{{ $vendor }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">IHOD</label>
                        <select name="ihod" class="form-select booking-auto-filter">
                            <option value="">All IHOD</option>
                            @foreach($filterOptions['ihods'] as $ihod)
                                <option value="{{ $ihod }}" @selected($selectedIhod === $ihod)>{{ $ihod }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-9 booking-search-field">
                        <label class="form-label">Quick keyword search</label>
                        <i class="bi bi-search booking-search-icon"></i>
                        <input type="text" name="keyword" value="{{ $selectedKeyword }}" class="form-control booking-auto-filter-text" placeholder="Search buyer / season / vendor / item / style / PO keyword...">
                    </div>
                    <div class="col-md-3 d-grid d-md-flex gap-2">
                        <button type="submit" class="btn booking-primary-btn flex-fill" title="Apply filter"><i class="bi bi-search me-1"></i>Search</button>
                        <button type="button" class="btn btn-soft-reset" id="bookingFilterReset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card booking-card mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold booking-section-title"><span class="booking-title-icon"><i class="bi bi-list-check"></i></span>Pending PO Generate Orders</h6>
            <small class="text-muted">Select orders to preview booking format first; generate PO only from the preview bottom button.</small>
        </div>
        <div class="booking-toolbar p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <label class="mb-0 small fw-bold">
                    <input type="checkbox" class="form-check-input me-1" id="bookingSelectAll"> Select all visible orders
                </label>
                <span class="small text-muted"><span id="bookingSelectedCount">0</span> selected</span>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn booking-primary-btn btn-sm" id="bulkPreviewBtn"><i class="bi bi-eye me-1"></i>Preview selected</button>
            </div>
        </div>
        <div class="card-body p-0 booking-table-wrap">
            <div class="booking-loading" id="bookingLoading"><span class="spinner-border spinner-border-sm me-2"></span>Loading data...</div>
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 booking-table">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:46px;">#</th>
                            <th>Buyer</th>
                            <th>Season</th>
                            <th>IHOD</th>
                            <th>Vendor</th>
                            <th>Item</th>
                            <th class="text-end">Qty</th>
                            <th class="text-center" style="width:180px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="bookingRowsBody">
                        @include('supply-chain.bookings.partials.rows', ['pendingRows' => $pendingRows])
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white" id="bookingPagination">
            @include('supply-chain.bookings.partials.pagination', ['pendingRows' => $pendingRows])
        </div>
    </div>

    <div class="modal fade booking-preview-modal" id="bookingPreviewPanel" tabindex="-1" aria-labelledby="bookingPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header bg-white border-bottom">
                    <div class="booking-preview-title-wrap">
                        <h6 class="modal-title fw-bold mb-0" id="bookingPreviewTitle"><i class="bi bi-file-earmark-richtext me-2 text-primary"></i>Booking Format Preview</h6>
                        <small class="text-muted">Preview the booking format in popup, then click Generate PO. The order will be completed automatically after PO generation.</small>
                    </div>
                    <div class="booking-preview-header-tools no-print" aria-label="Booking preview zoom controls">
                        <button type="button" class="btn btn-outline-secondary btn-sm booking-preview-zoom-btn" id="bookingPreviewZoomOut" title="Zoom out" aria-label="Zoom out"><i class="bi bi-zoom-out"></i></button>
                        <span class="booking-preview-zoom-value" id="bookingPreviewZoomValue">100%</span>
                        <button type="button" class="btn btn-outline-secondary btn-sm booking-preview-zoom-btn" id="bookingPreviewZoomIn" title="Zoom in" aria-label="Zoom in"><i class="bi bi-zoom-in"></i></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm booking-preview-zoom-btn" id="bookingPreviewZoomReset" title="Reset zoom" aria-label="Reset zoom"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </div>
                    <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body booking-preview-inner p-2" id="bookingPreviewContent"></div>
            </div>
        </div>
    </div>

    <div class="card booking-card">
        <div class="card-header py-3">
            <h6 class="mb-0 fw-bold booking-section-title"><span class="booking-title-icon"><i class="bi bi-clock-history"></i></span>Recent Generated PO</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 booking-table">
                    <thead>
                        <tr>
                            <th>PO No</th>
                            <th>Buyer</th>
                            <th>Season</th>
                            <th>Vendor</th>
                            <th>Style / Item</th>
                            <th class="text-end">Qty</th>
                            <th class="text-center">Booking Format</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($generatedPos as $po)
                            <tr>
                                <td>
                                    <span class="po-pill"><i class="bi bi-upc-scan"></i>{{ $po->po_no }}</span>
                                    @if(($po->revision_no ?? 0) > 0)
                                        <span class="revision-mini-pill ms-1">R-{{ $po->revision_no }}</span>
                                    @endif
                                    @if($po->needs_regenerate ?? false)
                                        <span class="needs-regenerate-pill ms-1"><i class="bi bi-exclamation-triangle"></i>Source changed</span>
                                    @endif
                                </td>
                                <td>{{ $po->buyer_name ?: '-' }}</td>
                                <td>{{ $po->season_name ?: '-' }}</td>
                                <td>{{ $po->vendor_name ?: '-' }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $po->style_name ?: '-' }}</div>
                                    <div class="small text-muted">{{ $po->item_name ?: '-' }}</div>
                                </td>
                                <td class="text-end fw-bold">{{ $po->qty !== null && $po->qty !== '' ? $po->qty : '-' }}</td>
                                <td class="text-center">
                                    <div class="dropdown d-inline-block">
                                        <button class="btn booking-kebab-btn btn-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More actions">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end booking-action-dropdown">
                                            <li><a href="{{ route('supply_chain.bookings.show', $po) }}" class="dropdown-item"><i class="bi bi-eye me-2"></i>View booking</a></li>
                                            <li><a href="{{ route('supply_chain.bookings.print', $po) }}" target="_blank" class="dropdown-item"><i class="bi bi-printer me-2"></i>Print</a></li>
                                            <li><a href="{{ route('supply_chain.bookings.download', $po) }}" target="_blank" class="dropdown-item"><i class="bi bi-filetype-pdf me-2"></i>Download PDF</a></li>
                                            <li>
                                                <button type="button" class="dropdown-item regenerate-po-preview-btn" data-url="{{ route('supply_chain.bookings.regenerate_preview', $po) }}">
                                                    <i class="bi bi-arrow-repeat me-2"></i>Re-generate PO
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a href="{{ route('supply_chain.bookings.download_excel', $po) }}" target="_blank" class="dropdown-item"><i class="bi bi-file-earmark-excel me-2"></i>Download Excel</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No PO generated yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div></div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('bookingFilterForm');
    const rowsBody = document.getElementById('bookingRowsBody');
    const pagination = document.getElementById('bookingPagination');
    const loading = document.getElementById('bookingLoading');
    const alertBox = document.getElementById('bookingAjaxAlert');
    const selectAll = document.getElementById('bookingSelectAll');
    const selectedCount = document.getElementById('bookingSelectedCount');
    const pendingTotal = document.getElementById('pendingTotal');
    const recentTotal = document.getElementById('recentTotal');
    const previewPanel = document.getElementById('bookingPreviewPanel');
    const previewContent = document.getElementById('bookingPreviewContent');
    const zoomOutBtn = document.getElementById('bookingPreviewZoomOut');
    const zoomInBtn = document.getElementById('bookingPreviewZoomIn');
    const zoomResetBtn = document.getElementById('bookingPreviewZoomReset');
    const zoomValue = document.getElementById('bookingPreviewZoomValue');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const dataUrl = @json(route('supply_chain.bookings.data'));
    const indexUrl = @json(route('supply_chain.bookings.index'));
    const bulkPreviewUrl = @json(route('supply_chain.bookings.bulk_preview'));
    const bulkGenerateUrl = @json(route('supply_chain.bookings.bulk_generate'));

    if (!form || !rowsBody) return;

    let filterTimer = null;
    let bookingPreviewZoom = 1;
    const bookingPreviewZoomMin = 0.7;
    const bookingPreviewZoomMax = 1.4;
    const bookingPreviewZoomStep = 0.1;

    function applyPreviewZoom(value) {
        bookingPreviewZoom = Math.max(bookingPreviewZoomMin, Math.min(bookingPreviewZoomMax, Number(value.toFixed(2))));
        previewContent?.style.setProperty('--booking-preview-zoom', bookingPreviewZoom);
        if (zoomValue) zoomValue.textContent = Math.round(bookingPreviewZoom * 100) + '%';
        if (zoomOutBtn) zoomOutBtn.disabled = bookingPreviewZoom <= bookingPreviewZoomMin;
        if (zoomInBtn) zoomInBtn.disabled = bookingPreviewZoom >= bookingPreviewZoomMax;
    }

    function formQueryString() {
        const params = new URLSearchParams(new FormData(form));
        [...params.entries()].forEach(([key, value]) => {
            if (!value) params.delete(key);
        });
        return params.toString();
    }

    function showLoading(show) {
        loading?.classList.toggle('show', !!show);
    }

    function showAlert(message, type = 'success') {
        if (!alertBox) return;
        alertBox.className = 'alert alert-' + type;
        alertBox.textContent = message;
        alertBox.classList.remove('d-none');
        clearTimeout(showAlert.timer);
        showAlert.timer = setTimeout(() => alertBox.classList.add('d-none'), 4500);
    }

    function showPreview(html) {
        if (!html || !previewPanel || !previewContent) return;
        previewContent.innerHTML = html;
        applyPreviewZoom(bookingPreviewZoom);

        if (window.bootstrap && previewPanel.classList.contains('modal')) {
            const modal = window.bootstrap.Modal.getOrCreateInstance(previewPanel);
            modal.show();
            return;
        }

        previewPanel.classList.remove('d-none');
        previewPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function updateSelectionCount() {
        const checked = rowsBody.querySelectorAll('.booking-row-check:checked').length;
        selectedCount.textContent = checked;
        if (selectAll) {
            const all = rowsBody.querySelectorAll('.booking-row-check').length;
            selectAll.checked = all > 0 && checked === all;
            selectAll.indeterminate = checked > 0 && checked < all;
        }
    }

    async function loadRows(url = null) {
        const query = formQueryString();
        let requestUrl = url || dataUrl + (query ? '?' + query : '');
        if (url && !url.includes('/booking-generate/data')) {
            requestUrl = url.replace(indexUrl, dataUrl);
        }

        showLoading(true);
        try {
            const response = await fetch(requestUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Could not load orders.');

            rowsBody.innerHTML = data.rows_html;
            pagination.innerHTML = data.pagination_html || '';
            pendingTotal.textContent = data.pending_total ?? pendingTotal.textContent;
            recentTotal.textContent = data.recent_total ?? recentTotal.textContent;
            updateSelectionCount();

            const newUrl = indexUrl + (query ? '?' + query : '');
            window.history.replaceState({}, '', newUrl);
        } catch (error) {
            showAlert(error.message || 'Something went wrong.', 'danger');
        } finally {
            showLoading(false);
        }
    }

    async function postJson(url, payload = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok || data.success === false) {
            throw new Error(data.message || 'Request failed.');
        }
        return data;
    }

    function formDataToObject(formEl) {
        const payload = {};
        if (!formEl) return payload;

        const assign = (name, value) => {
            const parts = [];
            name.replace(/([^\[\]]+)|\[([^\]]*)\]/g, function (_, first, second) {
                parts.push(first ?? second);
            });

            let cursor = payload;
            parts.forEach(function (part, index) {
                const last = index === parts.length - 1;
                const nextPart = parts[index + 1];

                if (last) {
                    if (part === '') {
                        if (!Array.isArray(cursor)) return;
                        cursor.push(value);
                    } else if (Object.prototype.hasOwnProperty.call(cursor, part)) {
                        if (!Array.isArray(cursor[part])) cursor[part] = [cursor[part]];
                        cursor[part].push(value);
                    } else {
                        cursor[part] = value;
                    }
                    return;
                }

                if (part === '') {
                    return;
                }

                if (!Object.prototype.hasOwnProperty.call(cursor, part)) {
                    cursor[part] = nextPart === '' ? [] : {};
                }
                cursor = cursor[part];
            });
        };

        new FormData(formEl).forEach((value, name) => assign(name, value));
        return payload;
    }

    function selectedRows() {
        return Array.from(rowsBody.querySelectorAll('.booking-row-check:checked'));
    }

    applyPreviewZoom(bookingPreviewZoom);

    zoomOutBtn?.addEventListener('click', function () {
        applyPreviewZoom(bookingPreviewZoom - bookingPreviewZoomStep);
    });

    zoomInBtn?.addEventListener('click', function () {
        applyPreviewZoom(bookingPreviewZoom + bookingPreviewZoomStep);
    });

    zoomResetBtn?.addEventListener('click', function () {
        applyPreviewZoom(1);
        previewContent?.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        loadRows();
    });

    document.querySelectorAll('.booking-auto-filter').forEach(function (select) {
        select.addEventListener('change', function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(() => loadRows(), 150);
        });
    });

    document.querySelectorAll('.booking-auto-filter-text').forEach(function (input) {
        input.addEventListener('input', function () {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(() => loadRows(), 450);
        });
    });

    document.getElementById('bookingFilterReset')?.addEventListener('click', function () {
        form.querySelectorAll('select, input').forEach(function (field) {
            field.value = '';
        });
        loadRows();
    });

    selectAll?.addEventListener('change', function () {
        rowsBody.querySelectorAll('.booking-row-check').forEach(cb => cb.checked = selectAll.checked);
        updateSelectionCount();
    });

    rowsBody.addEventListener('change', function (event) {
        if (event.target.classList.contains('booking-row-check')) {
            updateSelectionCount();
        }
    });

    rowsBody.addEventListener('click', async function (event) {
        const previewBtn = event.target.closest('.preview-single-btn');
        if (previewBtn) {
            previewBtn.disabled = true;
            showLoading(true);
            try {
                const data = await postJson(previewBtn.dataset.url, {});
                showAlert(data.message || 'Booking preview ready.');
                showPreview(data.preview_html);
            } catch (error) {
                showAlert(error.message, 'danger');
            } finally {
                previewBtn.disabled = false;
                showLoading(false);
            }
        }

    });

    document.getElementById('bulkPreviewBtn')?.addEventListener('click', async function () {
        const ids = selectedRows().map(cb => cb.value);
        if (!ids.length) {
            showAlert('Please select at least one order.', 'warning');
            return;
        }
        showLoading(true);
        try {
            const data = await postJson(bulkPreviewUrl, { rows: ids });
            showAlert(data.message || 'Selected booking preview ready.');
            showPreview(data.preview_html);
        } catch (error) {
            showAlert(error.message, 'danger');
        } finally {
            showLoading(false);
        }
    });

    document.addEventListener('click', async function (event) {
        const regeneratePreviewBtn = event.target.closest('.regenerate-po-preview-btn');
        if (!regeneratePreviewBtn) return;

        event.preventDefault();
        if (regeneratePreviewBtn.disabled) return;

        regeneratePreviewBtn.disabled = true;
        showLoading(true);
        try {
            const data = await postJson(regeneratePreviewBtn.dataset.url, {});
            showAlert(data.message || 'Re-generate preview ready. Edit first, then confirm re-generate.');
            showPreview(data.preview_html);
        } catch (error) {
            showAlert(error.message, 'danger');
        } finally {
            regeneratePreviewBtn.disabled = false;
            showLoading(false);
        }
    });

    previewContent?.addEventListener('click', async function (event) {
        const editToggle = event.target.closest('.booking-preview-edit-toggle');
        if (editToggle) {
            const box = editToggle.closest('.booking-format-preview-box');
            const panel = box?.querySelector('.booking-preview-edit-panel');
            panel?.classList.toggle('d-none');
            editToggle.innerHTML = panel && !panel.classList.contains('d-none')
                ? '<i class="bi bi-eye me-1"></i>Hide Edit'
                : '<i class="bi bi-pencil-square me-1"></i>Edit Preview';
            return;
        }

        const addNoteBtn = event.target.closest('.booking-preview-add-note');
        if (addNoteBtn) {
            const panel = addNoteBtn.closest('.booking-preview-edit-panel');
            const list = panel?.querySelector('.booking-preview-notes-list');
            if (list) {
                const row = document.createElement('div');
                row.className = 'booking-preview-note-row';
                row.innerHTML = '<textarea name="notes[]" rows="2" class="form-control" placeholder="Instruction text"></textarea><button type="button" class="btn btn-outline-danger btn-sm booking-preview-remove-note" title="Remove"><i class="bi bi-x-lg"></i></button>';
                list.appendChild(row);
            }
            return;
        }

        const removeNoteBtn = event.target.closest('.booking-preview-remove-note');
        if (removeNoteBtn) {
            const list = removeNoteBtn.closest('.booking-preview-notes-list');
            const row = removeNoteBtn.closest('.booking-preview-note-row');
            if (row && list && list.querySelectorAll('.booking-preview-note-row').length > 1) {
                row.remove();
            } else if (row) {
                const textarea = row.querySelector('textarea');
                if (textarea) textarea.value = '';
            }
            return;
        }

        const generateBtn = event.target.closest('.preview-generate-po-btn');
        if (!generateBtn) return;

        const editForm = generateBtn.closest('.booking-preview-edit-form');
        const editPanel = editForm?.querySelector('.booking-preview-edit-panel');
        const isRegenerate = generateBtn.dataset.regenerate === '1';
        const payload = editForm && (isRegenerate || (editPanel && !editPanel.classList.contains('d-none'))) ? formDataToObject(editForm) : {};

        if (isRegenerate) {
            const confirmed = window.confirm('Confirm re-generate this PO? PO number will stay the same and revision count will increase.');
            if (!confirmed) return;
        }

        generateBtn.disabled = true;
        showLoading(true);
        try {
            const data = await postJson(generateBtn.dataset.url, payload);
            const successMessage = data.message || (isRegenerate ? 'PO re-generated successfully.' : 'PO generated successfully. Booking completed.');
            showAlert(successMessage);
            window.alert(successMessage);
            if (data.preview_html) {
                showPreview(data.preview_html);
            }
            if (isRegenerate) {
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                await loadRows();
            }
        } catch (error) {
            showAlert(error.message, 'danger');
        } finally {
            generateBtn.disabled = false;
            showLoading(false);
        }
    });

    previewContent?.addEventListener('change', function (event) {
        const select = event.target.closest('.booking-delivery-destination-select');
        if (!select) return;

        const selected = select.options[select.selectedIndex];
        const panel = select.closest('.booking-preview-edit-panel');
        const nameInput = panel?.querySelector('.booking-delivery-destination-name');
        const detailsInput = panel?.querySelector('.booking-delivery-destination-details');

        if (nameInput) nameInput.value = selected?.dataset?.title || '';
        if (detailsInput && selected?.dataset?.details) detailsInput.value = selected.dataset.details;
        if (detailsInput && !select.value) detailsInput.value = '';
    });


    pagination.addEventListener('click', function (event) {
        const link = event.target.closest('a');
        if (!link) return;
        event.preventDefault();
        loadRows(link.href);
    });
});
</script>
@endsection
