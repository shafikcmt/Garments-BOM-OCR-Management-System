@extends('layouts.app')

@section('title', 'PO Generate Control')

@section('styles')
<style>
    .po-control-page {
        --po-ink: #0f172a;
        --po-muted: #64748b;
        --po-border: #dbe7f3;
        --po-blue: #2563eb;
        --po-red: #dc2626;
        --po-orange: #f59e0b;
        --po-green: #059669;
    }
    .po-control-hero {
        border: 1px solid rgba(191, 219, 254, .7);
        border-radius: 22px;
        background: radial-gradient(circle at top right, rgba(37, 99, 235, .14), transparent 34%), linear-gradient(135deg, #ffffff 0%, #eef6ff 100%);
        box-shadow: 0 20px 55px rgba(15, 23, 42, .08);
    }
    .po-control-icon {
        width: 54px;
        height: 54px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #dbeafe;
        color: var(--po-blue);
        font-size: 24px;
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .12);
    }
    .po-control-eyebrow {
        color: var(--po-blue);
        font-size: 11px;
        letter-spacing: .14em;
        font-weight: 900;
        text-transform: uppercase;
    }
    .po-control-title { color: var(--po-ink); font-weight: 900; letter-spacing: -.03em; }
    .po-control-copy { color: var(--po-muted); }
    .po-stat-card {
        border: 1px solid var(--po-border);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 16px 40px rgba(15, 23, 42, .06);
        height: 100%;
    }
    .po-stat-card .po-control-icon { width: 44px; height: 44px; border-radius: 15px; font-size: 20px; }
    .po-stat-label { color: var(--po-muted); font-size: 12px; font-weight: 800; }
    .po-stat-value { color: var(--po-ink); font-size: 28px; font-weight: 950; line-height: 1; }
    .po-filter-card,
    .po-table-card {
        border: 1px solid var(--po-border);
        border-radius: 20px;
        background: #fff;
        box-shadow: 0 16px 45px rgba(15, 23, 42, .07);
    }
    .po-filter-card .form-control,
    .po-filter-card .form-select {
        min-height: 44px;
        border-radius: 13px;
        border-color: #cbd5e1;
        font-weight: 700;
        color: var(--po-ink);
    }
    .po-control-hint {
        border: 1px solid #bfdbfe;
        border-radius: 18px;
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
        color: #1e3a8a;
    }
    .po-table {
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0 10px;
        padding: 0 12px 12px;
    }
    .po-table thead th {
        background: #f8fafc;
        color: #334155;
        border: 0;
        border-bottom: 1px solid var(--po-border);
        font-size: 10px;
        font-weight: 950;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 14px;
        white-space: nowrap;
    }
    .po-table tbody tr.po-row-main {
        filter: drop-shadow(0 10px 22px rgba(15, 23, 42, .045));
    }
    .po-table tbody tr.po-row-main td {
        border-top: 1px solid #e7edf5;
        border-bottom: 1px solid #e7edf5;
        background: #fff;
        padding: 14px;
        vertical-align: middle;
        color: #1e293b;
    }
    .po-table tbody tr.po-row-main td:first-child {
        border-left: 1px solid #e7edf5;
        border-top-left-radius: 17px;
        border-bottom-left-radius: 17px;
    }
    .po-table tbody tr.po-row-main td:last-child {
        border-right: 1px solid #e7edf5;
        border-top-right-radius: 17px;
        border-bottom-right-radius: 17px;
    }
    .po-row-main:hover td { background: #f8fbff !important; }
    .po-no-pill,
    .po-state-pill,
    .po-change-pill,
    .po-control-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }
    .po-no-pill { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .po-state-pill.generated { background: #ecfdf5; color: #047857; border: 1px solid #bbf7d0; }
    .po-state-pill.regenerated { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
    .po-change-pill { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .po-change-pill.clean { background: #f0fdf4; color: #047857; border-color: #bbf7d0; }
    .po-control-chip.open { background: #ecfdf5; color: #047857; border: 1px solid #bbf7d0; }
    .po-control-chip.locked { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .po-control-chip.permission { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
    .po-action-stack { display: inline-flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: nowrap; white-space: nowrap; min-width: 124px; }
    .po-table thead th:last-child,
    .po-table tbody td:last-child { width: 148px; min-width: 148px; }
    .po-icon-btn {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        font-weight: 900;
        border-width: 1px;
        flex: 0 0 36px;
        box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
    }
    .po-icon-btn.btn-primary { box-shadow: 0 10px 22px rgba(37, 99, 235, .18); }
    .po-icon-btn .bi,
    .po-control-icon .bi,
    .po-no-pill .bi,
    .po-state-pill .bi,
    .po-change-pill .bi,
    .po-control-chip .bi {
        width: 1em;
        height: 1em;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
    }
    .po-icon-btn .bi::before,
    .po-control-icon .bi::before,
    .po-no-pill .bi::before,
    .po-state-pill .bi::before,
    .po-change-pill .bi::before,
    .po-control-chip .bi::before { line-height: 1; }
    .po-pending-card {
        border: 1px solid #bfdbfe;
        border-radius: 20px;
        background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
        box-shadow: 0 16px 45px rgba(15, 23, 42, .065);
    }
    .po-pending-table { margin-bottom: 0; }
    .po-pending-table thead th {
        background: #eff6ff;
        color: #1e3a8a;
        border-bottom: 1px solid #dbeafe;
        font-size: 10px;
        font-weight: 950;
        letter-spacing: .08em;
        text-transform: uppercase;
        padding: 12px;
        white-space: nowrap;
    }
    .po-pending-table td {
        padding: 13px 12px;
        vertical-align: middle;
        border-color: #edf2f7;
        color: #1e293b;
    }
    .po-pending-row:hover td { background: #f8fbff; }
    .po-pending-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #fff7ed;
        color: #c2410c;
        border: 1px solid #fed7aa;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }
    .po-owner-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        border: 1px solid #bbf7d0;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }
    .po-detail-row td {
        background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
        padding: 0 14px 16px;
    }
    .po-detail-panel {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 14px;
        background: #fff;
    }
    .po-change-table { margin-bottom: 0; }
    .po-change-table th {
        color: #475569;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .06em;
    }
    .po-change-table td { font-size: 12px; padding: 8px !important; border-bottom: 1px solid #eef2f7 !important; }
    .po-history-line {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
        background: #f8fafc;
        margin-bottom: 8px;
    }
    .po-empty-state {
        min-height: 240px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--po-muted);
    }
    .po-detail-row.d-none { display: none; }
    .po-history-toggle.is-open { background: #eef2ff; border-color: #bfdbfe; color: #1d4ed8; }
    .po-access-modal .modal-content { border: 0; border-radius: 22px; box-shadow: 0 24px 70px rgba(15, 23, 42, .2); }
    .po-access-modal .modal-header { border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%); border-top-left-radius: 22px; border-top-right-radius: 22px; }
    .po-access-modal .form-control,
    .po-access-modal .form-select { border-radius: 13px; border-color: #cbd5e1; font-weight: 700; }
    .po-access-modal .form-label { color: #475569; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; }

    .po-section-tabs {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }
    .po-section-tab {
        border: 1px solid var(--po-border);
        border-radius: 18px;
        background: #fff;
        color: var(--po-ink);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 16px 18px;
        text-decoration: none;
        box-shadow: 0 12px 32px rgba(15, 23, 42, .055);
        transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    }
    .po-section-tab:hover { color: var(--po-ink); transform: translateY(-1px); box-shadow: 0 18px 44px rgba(15, 23, 42, .09); border-color: #bfdbfe; }
    .po-section-tab.active {
        background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
        border-color: #93c5fd;
        box-shadow: 0 18px 48px rgba(37, 99, 235, .13);
    }
    .po-section-tab-icon {
        width: 42px;
        height: 42px;
        border-radius: 15px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #dbeafe;
        color: var(--po-blue);
        flex: 0 0 42px;
    }
    .po-pager {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 14px 16px;
        background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
    }
    .po-pager-summary { color: #475569; font-size: 13px; font-weight: 700; }
    .po-pager-actions { display: inline-flex; align-items: center; gap: 7px; flex-wrap: wrap; justify-content: flex-end; }
    .po-pager-btn, .po-pager-dot {
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        font-size: 13px;
        font-weight: 900;
        text-decoration: none;
        box-shadow: 0 6px 14px rgba(15, 23, 42, .045);
    }
    .po-pager-btn:hover { color: var(--po-blue); border-color: #93c5fd; background: #eff6ff; }
    .po-pager-btn.active { color: #fff; background: var(--po-blue); border-color: var(--po-blue); box-shadow: 0 10px 22px rgba(37, 99, 235, .22); }
    .po-pager-btn.disabled { opacity: .45; pointer-events: none; background: #f8fafc; }
    .po-pager-dot { border: 0; box-shadow: none; background: transparent; min-width: 22px; color: #94a3b8; }
    .po-modal-audit {
        border: 1px solid #dbe7f3;
        border-radius: 18px;
        background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
        padding: 16px;
    }
    @media (max-width: 767.98px) {
        .po-section-tabs { grid-template-columns: 1fr; }
        .po-pager { align-items: stretch; flex-direction: column; }
        .po-pager-actions { justify-content: flex-start; }
    }
    @media (max-width: 991.98px) {
        .po-control-hero { padding: 22px !important; }
        .po-table { min-width: 1180px; }
    }
</style>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-po-detail-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const target = document.querySelector(this.dataset.poDetailToggle);
            if (!target) return;
            const isOpen = !target.classList.contains('d-none');
            target.classList.toggle('d-none', isOpen);
            this.classList.toggle('is-open', !isOpen);
            this.setAttribute('aria-expanded', String(!isOpen));
            this.setAttribute('title', !isOpen ? 'Hide history' : 'Show history');
            this.innerHTML = !isOpen
                ? '<i class="bi bi-eye-slash"></i><span class="visually-hidden">Hide history</span>'
                : '<i class="bi bi-clock-history"></i><span class="visually-hidden">History</span>';
        });
    });
});
</script>
@endsection

@section('content')
<div class="container-fluid po-control-page">
    <div class="po-control-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="po-control-icon"><i class="bi bi-shield-lock"></i></span>
                <div>
                    <div class="po-control-eyebrow">Admin Only</div>
                    <h2 class="po-control-title mb-1">PO Generate Control</h2>
                    <p class="po-control-copy mb-0">Control generated PO, re-generated PO, edit access, user authorization, lock status, delete action and before/after audit history.</p>
                </div>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-primary rounded-pill fw-bold px-4">
                <i class="bi bi-arrow-left me-1"></i>Admin Dashboard
            </a>
        </div>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-5 g-3 mb-4">
        <div class="col">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Total Generated PO</div><div class="po-stat-value">{{ $stats['total'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-file-earmark-check"></i></span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Pending PO</div><div class="po-stat-value">{{ $stats['pending'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-hourglass-split"></i></span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Re-generated PO</div><div class="po-stat-value">{{ $stats['regenerated'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-arrow-repeat"></i></span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Locked PO</div><div class="po-stat-value">{{ $stats['locked'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-lock"></i></span>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="po-stat-card p-3">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="po-stat-label">Supply Chain Authorized</div><div class="po-stat-value">{{ $stats['authorized'] ?? 0 }}</div></div>
                    <span class="po-control-icon"><i class="bi bi-person-check"></i></span>
                </div>
            </div>
        </div>
    </div>

    <div class="po-control-hint p-3 mb-4 d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div class="small fw-semibold">
            Admin controls are saved inside each PO: lock/unlock, edit permission mode, Supply Chain authorized users and control notes. Default PO generate/re-generate owner is Supply Chain only. Locked PO cannot be edited or re-generated until admin unlocks it.
        </div>
    </div>

    <form method="GET" action="{{ ($activePoPage ?? 'generated') === 'pending' ? route('admin.po-generate-control.pending') : route('admin.po-generate-control.generated') }}" class="po-filter-card p-3 p-lg-4 mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small fw-bold text-muted">Search PO / buyer / vendor / style</label>
                <input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Search PO no, vendor, buyer...">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label small fw-bold text-muted">Control State</label>
                <select name="state" class="form-select">
                    <option value="all" @selected(request('state', 'all') === 'all')>All PO</option>
                    <option value="pending" @selected(request('state') === 'pending')>Pending PO</option>
                    <option value="generated" @selected(request('state') === 'generated')>Generated</option>
                    <option value="regenerated" @selected(request('state') === 'regenerated')>Re-generated</option>
                    <option value="changed" @selected(request('state') === 'changed')>Need Re-generate</option>
                    <option value="completed" @selected(request('state') === 'completed')>Completed</option>
                    <option value="locked" @selected(request('state') === 'locked')>Locked</option>
                    <option value="authorized" @selected(request('state') === 'authorized')>Authorized Users</option>
                    <option value="admin_only" @selected(request('state') === 'admin_only')>Admin Only</option>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label small fw-bold text-muted">Buyer</label>
                <input type="text" name="buyer" value="{{ request('buyer') }}" class="form-control" placeholder="Buyer" list="poBuyerOptions" autocomplete="off">
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label small fw-bold text-muted">Vendor</label>
                <input type="text" name="vendor" value="{{ request('vendor') }}" class="form-control" placeholder="Vendor" list="poVendorOptions" autocomplete="off">
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-primary rounded-pill fw-bold flex-fill" type="submit" title="Filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ ($activePoPage ?? 'generated') === 'pending' ? route('admin.po-generate-control.pending') : route('admin.po-generate-control.generated') }}" class="btn btn-outline-secondary rounded-pill fw-bold" title="Reset"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </div>
    </form>

    <datalist id="poBuyerOptions">
        @foreach(($filterOptions['buyers'] ?? collect()) as $buyerOption)
            <option value="{{ $buyerOption }}"></option>
        @endforeach
    </datalist>
    <datalist id="poVendorOptions">
        @foreach(($filterOptions['vendors'] ?? collect()) as $vendorOption)
            <option value="{{ $vendorOption }}"></option>
        @endforeach
    </datalist>

    @php
        $tabQuery = request()->except(['page', 'pending_page']);
        $pendingTabUrl = route('admin.po-generate-control.pending', $tabQuery);
        $generatedTabUrl = route('admin.po-generate-control.generated', $tabQuery);
    @endphp
    <div class="po-section-tabs mb-4">
        <a href="{{ $pendingTabUrl }}" class="po-section-tab {{ ($activePoPage ?? 'generated') === 'pending' ? 'active' : '' }}">
            <div class="d-flex align-items-center gap-3">
                <span class="po-section-tab-icon"><i class="bi bi-hourglass-split"></i></span>
                <div>
                    <div class="fw-bold">Pending PO Information</div>
                    <div class="small text-muted">Supply Chain pending generate list</div>
                </div>
            </div>
            <span class="badge rounded-pill text-bg-warning px-3 py-2">{{ $stats['pending'] ?? 0 }}</span>
        </a>
        <a href="{{ $generatedTabUrl }}" class="po-section-tab {{ ($activePoPage ?? 'generated') === 'generated' ? 'active' : '' }}">
            <div class="d-flex align-items-center gap-3">
                <span class="po-section-tab-icon"><i class="bi bi-shield-check"></i></span>
                <div>
                    <div class="fw-bold">Generated / Re-generated Control</div>
                    <div class="small text-muted">Admin lock, permission and audit control</div>
                </div>
            </div>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">{{ $stats['total'] ?? 0 }}</span>
        </a>
    </div>

    @if(($activePoPage ?? 'generated') === 'pending')
    <div class="po-pending-card overflow-hidden mb-4">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap p-3 border-bottom">
            <div>
                <h5 class="fw-bold mb-0 text-slate-900">Pending PO Information</h5>
                <div class="small text-muted">Rows/groups still waiting for Supply Chain PO generate. Buyer and vendor filters also apply here.</div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="po-owner-pill"><i class="bi bi-person-check"></i>Generate owner: Supply Chain</span>
                <span class="badge rounded-pill text-bg-warning px-3 py-2">{{ $pendingRows->total() }} pending</span>
            </div>
        </div>
        @if($pendingRows->count())
            <div class="table-responsive">
                <table class="table po-pending-table align-middle">
                    <thead>
                        <tr>
                            <th>Row / Group</th>
                            <th>Buyer / Season</th>
                            <th>Vendor / IHOD</th>
                            <th>Style / Item</th>
                            <th class="text-end">Qty</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingRows as $pendingRow)
                            @php
                                $preview = $pendingRow->booking_preview ?? [];
                                $groupCount = (int) ($pendingRow->booking_group_count ?? 1);
                                $groupItems = collect($pendingRow->booking_group_items ?? [])->filter()->take(3)->implode(', ');
                                $qtyTotal = $pendingRow->booking_group_qty_total ?? ($preview['qty'] ?? null);
                            @endphp
                            <tr class="po-pending-row">
                                <td>
                                    <span class="po-pending-pill"><i class="bi bi-hourglass-split"></i>Pending</span>
                                    <div class="small text-muted mt-1">Row #{{ $pendingRow->row_number ?? $pendingRow->id }} @if($groupCount > 1) · {{ $groupCount }} rows @endif</div>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $preview['buyer_name'] ?? '-' }}</div>
                                    <div class="small text-muted">Season: {{ $preview['season_name'] ?? '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $preview['vendor_name'] ?? '-' }}</div>
                                    <div class="small text-muted">IHOD: {{ $preview['ihod'] ?? '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $preview['style_name'] ?? '-' }}</div>
                                    <div class="small text-muted">{{ $groupItems !== '' ? $groupItems : ($preview['item_name'] ?? '-') }}</div>
                                </td>
                                <td class="text-end fw-bold">{{ $qtyTotal !== null && $qtyTotal !== '' ? $qtyTotal : '-' }}</td>
                                <td>
                                    <span class="po-owner-pill"><i class="bi bi-person-check"></i>Supply Chain</span>
                                    <div class="small text-muted mt-1">Can generate PO</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($pendingRows->hasPages())
                @php
                    $pendingCurrent = $pendingRows->currentPage();
                    $pendingLast = $pendingRows->lastPage();
                    $pendingStart = max(1, $pendingCurrent - 2);
                    $pendingEnd = min($pendingLast, $pendingCurrent + 2);
                @endphp
                <div class="po-pager border-top">
                    <div class="po-pager-summary">
                        Showing <strong>{{ $pendingRows->firstItem() }}</strong> to <strong>{{ $pendingRows->lastItem() }}</strong> of <strong>{{ $pendingRows->total() }}</strong> pending results
                    </div>
                    <div class="po-pager-actions">
                        <a class="po-pager-btn {{ $pendingRows->onFirstPage() ? 'disabled' : '' }}" href="{{ $pendingRows->previousPageUrl() ?: '#' }}" aria-label="Previous pending page"><i class="bi bi-chevron-left"></i></a>
                        @if($pendingStart > 1)
                            <a class="po-pager-btn" href="{{ $pendingRows->url(1) }}">1</a>
                            @if($pendingStart > 2)<span class="po-pager-dot">...</span>@endif
                        @endif
                        @for($pageNumber = $pendingStart; $pageNumber <= $pendingEnd; $pageNumber++)
                            <a class="po-pager-btn {{ $pageNumber === $pendingCurrent ? 'active' : '' }}" href="{{ $pendingRows->url($pageNumber) }}">{{ $pageNumber }}</a>
                        @endfor
                        @if($pendingEnd < $pendingLast)
                            @if($pendingEnd < $pendingLast - 1)<span class="po-pager-dot">...</span>@endif
                            <a class="po-pager-btn" href="{{ $pendingRows->url($pendingLast) }}">{{ $pendingLast }}</a>
                        @endif
                        <a class="po-pager-btn {{ $pendingRows->hasMorePages() ? '' : 'disabled' }}" href="{{ $pendingRows->nextPageUrl() ?: '#' }}" aria-label="Next pending page"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
            @else
                <div class="po-pager border-top"><div class="po-pager-summary">Showing <strong>{{ $pendingRows->count() }}</strong> pending results</div></div>
            @endif
        @else
            <div class="p-4 text-center text-muted">
                <span class="po-control-icon mb-2"><i class="bi bi-check2-circle"></i></span>
                <div class="fw-bold text-slate-900">No pending PO found</div>
                <div class="small">Pending rows will appear here before Supply Chain generates PO.</div>
            </div>
        @endif
    </div>
    @endif

    @if(($activePoPage ?? 'generated') === 'generated')
    <div class="po-table-card overflow-hidden">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap p-3 border-bottom">
            <div>
                <h5 class="fw-bold mb-0 text-slate-900">Generated / Re-generated PO Control List</h5>
                <div class="small text-muted">Admin-controlled PO audit rows with lock, edit permission, authorized users and revision history.</div>
            </div>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">{{ $bookingPos->total() }} PO found</span>
        </div>

        @if($bookingPos->count())
            <div class="table-responsive">
                <table class="table po-table align-middle">
                    <thead>
                        <tr>
                            <th>PO</th>
                            <th>Buyer / Season</th>
                            <th>Vendor</th>
                            <th>Item / Style</th>
                            <th class="text-end">Qty</th>
                            <th>State</th>
                            <th>Change Control</th>
                            <th>Admin Control</th>
                            <th>Generated By</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bookingPos as $bookingPo)
                            @php
                                $data = $bookingPo->booking_data ?: [];
                                $sourceChanges = collect($data['source_change_log'] ?? []);
                                $history = collect($data['generation_history'] ?? [])->reverse()->values();
                                $controlHistory = collect($data['admin_control_history'] ?? [])->reverse()->values();
                                $control = is_array($data['admin_control'] ?? null) ? $data['admin_control'] : [];
                                if (empty($control)) {
                                    $control = [
                                        'locked' => false,
                                        'lock_scope' => 'all_users',
                                        'locked_user_ids' => [],
                                        'locked_users_snapshot' => [],
                                        'locked_role_ids' => [],
                                        'locked_roles_snapshot' => [],
                                        'edit_permission' => 'authorized_users',
                                        'authorized_user_ids' => $poControlUsers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                                        'authorized_users_snapshot' => $poControlUsers->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values()->all(),
                                        'generate_permission' => 'supply_chain_only',
                                    ];
                                }
                                $isLocked = (bool) ($control['locked'] ?? false);
                                $permissionMode = $control['edit_permission'] ?? 'authorized_users';
                                $permissionText = match ($permissionMode) {
                                    'authorized_users' => 'Supply Chain users',
                                    'all_users' => 'All users',
                                    default => 'Admin only',
                                };
                                $authorizedSnapshot = collect($control['authorized_users_snapshot'] ?? []);
                                $lockScope = $control['lock_scope'] ?? 'all_users';
                                $lockScopeText = match ($lockScope) {
                                    'specific_users' => 'Locked users only',
                                    'specific_roles' => 'Locked roles only',
                                    default => 'All users locked',
                                };
                                $lockedUsersSnapshot = collect($control['locked_users_snapshot'] ?? []);
                                $lockedRolesSnapshot = collect($control['locked_roles_snapshot'] ?? []);
                                $revisionNo = max(0, (int) $bookingPo->revision_no);
                                if ($history->isEmpty()) {
                                    $history = collect([[
                                        'action' => 'generated',
                                        'revision_no' => $revisionNo,
                                        'changed_by_name' => optional($bookingPo->generatedBy)->name ?: 'System',
                                        'changed_at' => optional($bookingPo->generated_at)->format('d M Y, h:i A') ?: optional($bookingPo->created_at)->format('d M Y, h:i A') ?: '-',
                                        'changes' => [],
                                        'source_changes' => [],
                                    ]]);
                                }
                                $latestHistory = $history->first();
                                $needsRegenerate = (bool) $bookingPo->needs_regenerate || $sourceChanges->isNotEmpty();
                                $collapseId = 'poControlDetails' . $bookingPo->id;
                            @endphp
                            <tr class="po-row-main">
                                <td>
                                    <span class="po-no-pill"><i class="bi bi-upc-scan"></i>{{ $bookingPo->po_no }}</span>
                                    @if($revisionNo > 0)
                                        <div class="small text-warning-emphasis fw-bold mt-1">Revision R-{{ $revisionNo }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $bookingPo->buyer_name ?: '-' }}</div>
                                    <div class="small text-muted">Season: {{ $bookingPo->season_name ?: '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $bookingPo->vendor_name ?: ($data['to'] ?? '-') }}</div>
                                    <div class="small text-muted">IHOD: {{ $bookingPo->ihod ?: '-' }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ $bookingPo->item_name ?: ($data['item_type'] ?? '-') }}</div>
                                    <div class="small text-muted">Style: {{ $bookingPo->style_name ?: ($data['order_style_no'] ?? '-') }}</div>
                                </td>
                                <td class="text-end fw-bold">{{ $bookingPo->qty !== null ? $bookingPo->qty : '-' }}</td>
                                <td>
                                    @if($revisionNo > 0)
                                        <span class="po-state-pill regenerated"><i class="bi bi-arrow-repeat"></i>Re-generated</span>
                                    @else
                                        <span class="po-state-pill generated"><i class="bi bi-check2-circle"></i>Generated</span>
                                    @endif
                                    <div class="small text-muted mt-1">{{ optional($bookingPo->generated_at)->format('d M Y h:i A') ?: '-' }}</div>
                                </td>
                                <td>
                                    @if($needsRegenerate)
                                        <span class="po-change-pill"><i class="bi bi-exclamation-triangle"></i>{{ $sourceChanges->count() }} Changed</span>
                                    @else
                                        <span class="po-change-pill clean"><i class="bi bi-shield-check"></i>Clean</span>
                                    @endif
                                    @if($latestHistory)
                                        <div class="small text-muted mt-1">Last: {{ ucfirst(str_replace('_', ' ', $latestHistory['action'] ?? 'generated')) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1 align-items-start">
                                        @if($isLocked)
                                            <span class="po-control-chip locked"><i class="bi bi-lock-fill"></i>Locked</span>
                                        @else
                                            <span class="po-control-chip open"><i class="bi bi-unlock"></i>Open</span>
                                        @endif
                                        <span class="po-control-chip permission"><i class="bi bi-person-gear"></i>{{ $permissionText }}</span>
                                        @if($isLocked)
                                            <div class="small text-danger fw-bold">{{ $lockScopeText }}</div>
                                        @endif
                                        <div class="small text-muted">{{ $authorizedSnapshot->count() }} authorized supply-chain user(s)</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-bold">{{ optional($bookingPo->generatedBy)->name ?: ($latestHistory['changed_by_name'] ?? 'System') }}</div>
                                    <div class="small text-muted">Completed by: {{ optional($bookingPo->completedBy)->name ?: '-' }}</div>
                                </td>
                                <td class="text-end">
                                    <div class="po-action-stack">
                                        <a href="{{ route('admin.po-generate-control.show', $bookingPo) }}" class="btn btn-primary btn-sm po-icon-btn" title="Open PO control">
                                            <i class="bi bi-box-arrow-up-right"></i><span class="visually-hidden">Open</span>
                                        </a>
                                        <button class="btn btn-outline-primary btn-sm po-icon-btn" type="button" data-bs-toggle="modal" data-bs-target="#poAccessModal{{ $bookingPo->id }}" title="Control, permission and history">
                                            <i class="bi bi-sliders2"></i><span class="visually-hidden">Control and history</span>
                                        </button>
                                        <form method="POST" action="{{ route('admin.po-generate-control.destroy', $bookingPo) }}" class="d-inline" onsubmit="return confirm('Delete PO {{ $bookingPo->po_no }}? This will remove only the generated PO number and move source rows back to Pending PO.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm po-icon-btn" title="Delete PO">
                                                <i class="bi bi-trash3"></i><span class="visually-hidden">Delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr class="po-detail-row d-none" id="{{ $collapseId }}">
                                <td colspan="10">
                                    <div class="po-detail-panel mt-2">
                                        <div class="row g-3">
                                            <div class="col-lg-7">
                                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                                    <h6 class="fw-bold mb-0"><i class="bi bi-arrow-left-right me-1 text-danger"></i>Source Data Before / After</h6>
                                                    <span class="badge rounded-pill text-bg-light border">{{ $sourceChanges->count() }} change(s)</span>
                                                </div>
                                                @if($sourceChanges->isNotEmpty())
                                                    <div class="table-responsive">
                                                        <table class="table table-sm po-change-table">
                                                            <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                                                            <tbody>
                                                            @foreach($sourceChanges->take(12) as $change)
                                                                <tr>
                                                                    <td class="fw-bold">{{ $change['label'] ?? '-' }}</td>
                                                                    <td>{{ trim((string)($change['before'] ?? '')) !== '' ? $change['before'] : 'Blank' }}</td>
                                                                    <td>{{ trim((string)($change['after'] ?? '')) !== '' ? $change['after'] : 'Blank' }}</td>
                                                                </tr>
                                                            @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                @else
                                                    <div class="alert alert-success rounded-4 mb-0"><i class="bi bi-check2-circle me-1"></i>No source data change found after generation.</div>
                                                @endif
                                            </div>
                                            <div class="col-lg-5">
                                                <h6 class="fw-bold mb-2"><i class="bi bi-clock-history me-1 text-primary"></i>Generation / Admin History</h6>
                                                @forelse($history->take(4) as $entry)
                                                    <div class="po-history-line">
                                                        <div class="fw-bold text-slate-900">{{ ucfirst(str_replace('_', ' ', $entry['action'] ?? 'generated')) }} @if(($entry['revision_no'] ?? 0) > 0)<span class="text-warning-emphasis">R-{{ $entry['revision_no'] }}</span>@endif</div>
                                                        <div class="small text-muted">{{ $entry['changed_by_name'] ?? 'System' }} - {{ $entry['changed_at'] ?? '-' }}</div>
                                                        @if(! empty($entry['changes']))
                                                            <div class="small text-danger fw-bold mt-1">{{ count($entry['changes']) }} edited field(s)</div>
                                                        @endif
                                                    </div>
                                                @empty
                                                    <div class="alert alert-light border rounded-4 mb-2">No generation history stored yet.</div>
                                                @endforelse

                                                @foreach($controlHistory->take(3) as $adminEntry)
                                                    <div class="po-history-line border-primary-subtle">
                                                        <div class="fw-bold text-primary"><i class="bi bi-shield-check me-1"></i>{{ ucfirst(str_replace('_', ' ', $adminEntry['action'] ?? 'control')) }}</div>
                                                        <div class="small text-muted">{{ $adminEntry['changed_by_name'] ?? 'Admin' }} - {{ $adminEntry['changed_at'] ?? '-' }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @foreach($bookingPos as $modalPo)
                @php
                    $modalData = $modalPo->booking_data ?: [];
                    $modalControl = is_array($modalData['admin_control'] ?? null) ? $modalData['admin_control'] : [];
                    if (empty($modalControl)) {
                        $modalControl = [
                            'locked' => false,
                            'lock_scope' => 'all_users',
                            'locked_user_ids' => [],
                            'locked_users_snapshot' => [],
                            'locked_role_ids' => [],
                            'locked_roles_snapshot' => [],
                            'edit_permission' => 'authorized_users',
                            'authorized_user_ids' => $poControlUsers->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                            'authorized_users_snapshot' => $poControlUsers->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values()->all(),
                            'generate_permission' => 'supply_chain_only',
                            'control_note' => 'Default PO generate and re-generate owner: Supply Chain users only.',
                        ];
                    }
                    $modalLocked = (bool) ($modalControl['locked'] ?? false);
                    $modalLockScope = $modalControl['lock_scope'] ?? 'all_users';
                    $modalLockedUserIds = collect($modalControl['locked_user_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
                    $modalLockedRoleIds = collect($modalControl['locked_role_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
                    $modalPermission = $modalControl['edit_permission'] ?? 'authorized_users';
                    $modalAuthorizedIds = collect($modalControl['authorized_user_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
                    $modalSourceChanges = collect($modalData['source_change_log'] ?? []);
                    $modalHistory = collect($modalData['generation_history'] ?? [])->reverse()->values();
                    $modalControlHistory = collect($modalData['admin_control_history'] ?? [])->reverse()->values();
                    $modalRevisionNo = max(0, (int) $modalPo->revision_no);
                    if ($modalHistory->isEmpty()) {
                        $modalHistory = collect([[
                            'action' => 'generated',
                            'revision_no' => $modalRevisionNo,
                            'changed_by_name' => optional($modalPo->generatedBy)->name ?: 'System',
                            'changed_at' => optional($modalPo->generated_at)->format('d M Y, h:i A') ?: optional($modalPo->created_at)->format('d M Y, h:i A') ?: '-',
                            'changes' => [],
                            'source_changes' => [],
                        ]]);
                    }
                @endphp
                <div class="modal fade po-access-modal" id="poAccessModal{{ $modalPo->id }}" tabindex="-1" aria-labelledby="poAccessModalLabel{{ $modalPo->id }}" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <div class="small fw-bold text-primary text-uppercase">Admin PO Control</div>
                                    <h5 class="modal-title fw-bold" id="poAccessModalLabel{{ $modalPo->id }}">{{ $modalPo->po_no }} Permission & Lock</h5>
                                    <div class="small text-muted">Delete PO, lock PO, authorize Supply Chain users, manage edit permission and review history.</div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="poAccessForm{{ $modalPo->id }}" method="POST" action="{{ route('admin.po-generate-control.access', $modalPo) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="locked" value="0">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">PO Lock</label>
                                            <div class="form-check form-switch border rounded-4 p-3 ps-5 bg-light">
                                                <input class="form-check-input" type="checkbox" role="switch" id="lockPo{{ $modalPo->id }}" name="locked" value="1" @checked($modalLocked)>
                                                <label class="form-check-label fw-bold" for="lockPo{{ $modalPo->id }}">Lock source row edit / update</label>
                                            </div>
                                            <div class="form-text">Locked source rows become read-only in the worksheet for the selected users/roles.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Lock Applies To</label>
                                            <select name="lock_scope" class="form-select">
                                                <option value="all_users" @selected($modalLockScope === 'all_users')>Lock all users</option>
                                                <option value="specific_roles" @selected($modalLockScope === 'specific_roles')>Lock selected roles</option>
                                                <option value="specific_users" @selected($modalLockScope === 'specific_users')>Lock selected users</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Locked Roles</label>
                                            <select name="locked_role_ids[]" class="form-select" multiple size="5">
                                                @foreach($poControlRoles as $controlRole)
                                                    <option value="{{ $controlRole->id }}" @selected(in_array((int) $controlRole->id, $modalLockedRoleIds, true))>{{ ucfirst(str_replace('_', ' ', $controlRole->name)) }}</option>
                                                @endforeach
                                            </select>
                                            <div class="form-text">Used when Lock Applies To is "Lock selected roles".</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Locked Users</label>
                                            <select name="locked_user_ids[]" class="form-select" multiple size="5">
                                                @foreach($poLockUsers as $lockUser)
                                                    <option value="{{ $lockUser->id }}" @selected(in_array((int) $lockUser->id, $modalLockedUserIds, true))>{{ $lockUser->name }} - {{ $lockUser->email }}</option>
                                                @endforeach
                                            </select>
                                            <div class="form-text">Used when Lock Applies To is "Lock selected users".</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Edit Permission</label>
                                            <select name="edit_permission" class="form-select">
                                                <option value="admin_only" @selected($modalPermission === 'admin_only')>Admin only</option>
                                                <option value="authorized_users" @selected($modalPermission === 'authorized_users')>Only authorized Supply Chain users</option>
                                                <option value="all_users" @selected($modalPermission === 'all_users')>All users can edit</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Authorize Supply Chain Users For This PO</label>
                                            <select name="authorized_user_ids[]" class="form-select" multiple size="8">
                                                @foreach($poControlUsers as $controlUser)
                                                    <option value="{{ $controlUser->id }}" @selected(in_array((int) $controlUser->id, $modalAuthorizedIds, true))>
                                                        {{ $controlUser->name }} - {{ $controlUser->email }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="form-text">Only active Supply Chain users are suggested here. Hold Ctrl/Cmd to select multiple users.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Lock Reason</label>
                                            <textarea name="lock_reason" rows="4" class="form-control" placeholder="Why is this PO locked?">{{ $modalControl['lock_reason'] ?? '' }}</textarea>
                                            <label class="form-label mt-3">Control Note</label>
                                            <textarea name="control_note" rows="3" class="form-control" placeholder="Admin note for permission / authorization">{{ $modalControl['control_note'] ?? '' }}</textarea>
                                        </div>
                                    </div>

                                    <div class="po-modal-audit mt-4">
                                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                                            <div>
                                                <div class="small fw-bold text-primary text-uppercase">Control History</div>
                                                <h6 class="fw-bold mb-0">Before / After, Generation and Admin Updates</h6>
                                            </div>
                                            <span class="badge rounded-pill text-bg-light border">{{ $modalSourceChanges->count() }} source change(s)</span>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-lg-7">
                                                <div class="fw-bold mb-2"><i class="bi bi-arrow-left-right me-1 text-danger"></i>Source Data Before / After</div>
                                                @if($modalSourceChanges->isNotEmpty())
                                                    <div class="table-responsive">
                                                        <table class="table table-sm po-change-table">
                                                            <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
                                                            <tbody>
                                                            @foreach($modalSourceChanges->take(10) as $change)
                                                                <tr>
                                                                    <td class="fw-bold">{{ $change['label'] ?? '-' }}</td>
                                                                    <td>{{ trim((string)($change['before'] ?? '')) !== '' ? $change['before'] : 'Blank' }}</td>
                                                                    <td>{{ trim((string)($change['after'] ?? '')) !== '' ? $change['after'] : 'Blank' }}</td>
                                                                </tr>
                                                            @endforeach
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                @else
                                                    <div class="alert alert-success rounded-4 mb-0"><i class="bi bi-check2-circle me-1"></i>No source data change found after generation.</div>
                                                @endif
                                            </div>
                                            <div class="col-lg-5">
                                                <div class="fw-bold mb-2"><i class="bi bi-clock-history me-1 text-primary"></i>Generation / Admin History</div>
                                                @forelse($modalHistory->take(4) as $entry)
                                                    <div class="po-history-line">
                                                        <div class="fw-bold text-slate-900">{{ ucfirst(str_replace('_', ' ', $entry['action'] ?? 'generated')) }} @if(($entry['revision_no'] ?? 0) > 0)<span class="text-warning-emphasis">R-{{ $entry['revision_no'] }}</span>@endif</div>
                                                        <div class="small text-muted">{{ $entry['changed_by_name'] ?? 'System' }} - {{ $entry['changed_at'] ?? '-' }}</div>
                                                        @if(! empty($entry['changes']))
                                                            <div class="small text-danger fw-bold mt-1">{{ count($entry['changes']) }} edited field(s)</div>
                                                        @endif
                                                    </div>
                                                @empty
                                                    <div class="alert alert-light border rounded-4 mb-2">No generation history stored yet.</div>
                                                @endforelse

                                                @foreach($modalControlHistory->take(3) as $adminEntry)
                                                    <div class="po-history-line border-primary-subtle">
                                                        <div class="fw-bold text-primary"><i class="bi bi-shield-check me-1"></i>{{ ucfirst(str_replace('_', ' ', $adminEntry['action'] ?? 'control')) }}</div>
                                                        <div class="small text-muted">{{ $adminEntry['changed_by_name'] ?? 'Admin' }} - {{ $adminEntry['changed_at'] ?? '-' }}</div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer justify-content-between">
                                <form method="POST" action="{{ route('admin.po-generate-control.destroy', $modalPo) }}" onsubmit="return confirm('Delete PO {{ $modalPo->po_no }}? This will remove only the generated PO number and move source rows back to Pending PO.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger rounded-pill fw-bold"><i class="bi bi-trash me-1"></i>Delete PO</button>
                                </form>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary rounded-pill fw-bold" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" form="poAccessForm{{ $modalPo->id }}" class="btn btn-primary rounded-pill fw-bold px-4"><i class="bi bi-check2-circle me-1"></i>Save Control</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

            @if($bookingPos->hasPages())
                @php
                    $generatedCurrent = $bookingPos->currentPage();
                    $generatedLast = $bookingPos->lastPage();
                    $generatedStart = max(1, $generatedCurrent - 2);
                    $generatedEnd = min($generatedLast, $generatedCurrent + 2);
                @endphp
                <div class="po-pager border-top">
                    <div class="po-pager-summary">
                        Showing <strong>{{ $bookingPos->firstItem() }}</strong> to <strong>{{ $bookingPos->lastItem() }}</strong> of <strong>{{ $bookingPos->total() }}</strong> generated PO results
                    </div>
                    <div class="po-pager-actions">
                        <a class="po-pager-btn {{ $bookingPos->onFirstPage() ? 'disabled' : '' }}" href="{{ $bookingPos->previousPageUrl() ?: '#' }}" aria-label="Previous generated page"><i class="bi bi-chevron-left"></i></a>
                        @if($generatedStart > 1)
                            <a class="po-pager-btn" href="{{ $bookingPos->url(1) }}">1</a>
                            @if($generatedStart > 2)<span class="po-pager-dot">...</span>@endif
                        @endif
                        @for($pageNumber = $generatedStart; $pageNumber <= $generatedEnd; $pageNumber++)
                            <a class="po-pager-btn {{ $pageNumber === $generatedCurrent ? 'active' : '' }}" href="{{ $bookingPos->url($pageNumber) }}">{{ $pageNumber }}</a>
                        @endfor
                        @if($generatedEnd < $generatedLast)
                            @if($generatedEnd < $generatedLast - 1)<span class="po-pager-dot">...</span>@endif
                            <a class="po-pager-btn" href="{{ $bookingPos->url($generatedLast) }}">{{ $generatedLast }}</a>
                        @endif
                        <a class="po-pager-btn {{ $bookingPos->hasMorePages() ? '' : 'disabled' }}" href="{{ $bookingPos->nextPageUrl() ?: '#' }}" aria-label="Next generated page"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
            @else
                <div class="po-pager border-top"><div class="po-pager-summary">Showing <strong>{{ $bookingPos->count() }}</strong> generated PO results</div></div>
            @endif
        @else
            <div class="po-empty-state text-center">
                <div>
                    <span class="po-control-icon mb-3"><i class="bi bi-inbox"></i></span>
                    <div class="fw-bold text-slate-900">No PO found</div>
                    <div class="small text-muted">Change filters or generate a PO first.</div>
                </div>
            </div>
        @endif
    </div>
    @endif
</div>
@endsection
