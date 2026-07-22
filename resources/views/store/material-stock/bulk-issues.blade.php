@extends('layouts.app')

@section('title', 'Material Stock — Bulk Issue')

@section('styles')
<style>
    /* Filter tabs. */
    .bi-tabs { border-bottom: 1px solid var(--bs-border-color, #E2E8F0); gap: .25rem; }
    .bi-tab {
        border: 0; background: transparent; padding: .6rem .9rem; font-weight: 600; font-size: .9rem;
        color: var(--gx-text-muted, #64748B); border-bottom: 2px solid transparent; border-radius: 0;
        transition: color .15s ease, border-color .15s ease;
    }
    .bi-tab:hover { color: var(--gx-primary, #0F172A); }
    .bi-tab.active { color: var(--gx-secondary-700, #1D4ED8); border-bottom-color: var(--gx-secondary, #2563EB); }
    .bi-tab .badge { font-weight: 600; }

    /* Searchable PO picker (offcanvas + inline reuse). */
    .bi-search { position: relative; }
    .bi-search-panel { z-index: 1080; max-height: 300px; overflow-y: auto; }
    .bi-opt { cursor: pointer; border-left: 2px solid transparent; transition: background-color .15s ease, border-color .15s ease; }
    .bi-opt:hover { background: var(--gx-bg, #F8FAFC); }
    .bi-opt.active { background: var(--gx-secondary-bg, #DBEAFE); border-left-color: var(--gx-secondary, #3B82F6); }
    .bi-opt-primary { font-weight: 500; color: var(--gx-primary, #0F172A); line-height: 1.35; }
    .bi-opt-meta { font-size: .75rem; color: var(--gx-text-muted, #64748B); line-height: 1.35; }
    .bi-chip-sel { display: inline-flex; align-items: center; gap: .5rem; background: var(--gx-secondary-bg, #DBEAFE); color: var(--gx-secondary-700, #1D4ED8); border-radius: 8px; padding: .35rem .5rem .35rem .75rem; font-weight: 500; max-width: 100%; }

    /* Read-only summary grid. */
    #biSummaryGrid { background: #F1F5F9; border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px; }
    #biSummaryGrid .bi-sum-label { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: #94A3B8; margin-bottom: .1rem; }
    #biSummaryGrid .bi-sum-value { font-weight: 600; color: var(--gx-primary, #0F172A); line-height: 1.3; overflow-wrap: anywhere; }

    /* Colour-coded quantity cards. Compact padding + a tight label so all four
       fit without the form feeling stretched. */
    .bi-qty-card { border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 10px; padding: .5rem .6rem; }
    .bi-qty-card.bulk { border-color: #A7F3D0; } .bi-qty-card.sample { border-color: #BFDBFE; }
    .bi-qty-card.liability { border-color: #FDE68A; } .bi-qty-card.dead { border-color: #FECACA; }
    .bi-qty-grid .bi-qty-card .form-label { font-size: .78rem; margin-bottom: .25rem; }

    /* Auto-suggested value: reads as a suggestion until the user edits it. */
    .bi-suggested { font-style: italic; color: var(--gx-text-muted, #64748B); }

    /* History table: row hover + department badge. */
    .bi-history-table tbody tr { transition: background-color .15s ease; }
    .bi-history-table tbody tr:hover { background: var(--gx-bg, #F8FAFC); }
    .bi-history-table tbody tr:has(.bi-row-check:checked) { background: var(--gx-secondary-bg, #DBEAFE); }
    .bi-section-badge { font-weight: 600; letter-spacing: .01em; }

    /* Sticky bulk-action bar. Docks to the bottom of the viewport while the
       selection lasts, so the actions stay reachable on a long list. */
    .bi-bulkbar {
        position: sticky; bottom: 1rem; z-index: 30; border: 1px solid var(--gx-secondary-border, #BFDBFE);
        background: #fff; border-radius: 12px; box-shadow: 0 8px 24px -8px rgba(15,23,42,.25);
    }

    /* Skeleton loader. */
    .bi-skel-row { height: 44px; border-radius: 8px; background: linear-gradient(90deg,#eef2f7 25%,#e2e8f0 37%,#eef2f7 63%); background-size: 400% 100%; animation: biShimmer 1.2s ease-in-out infinite; }
    @keyframes biShimmer { 0% { background-position: 100% 0; } 100% { background-position: 0 0; } }

    .bi-offcanvas { width: 460px; max-width: 100%; }
    .bi-search-spin { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); }

    /* Mobile: history table becomes a stacked card list. */
    @media (max-width: 767.98px) {
        .bi-history-table thead { display: none; }
        .bi-history-table, .bi-history-table tbody, .bi-history-table tr, .bi-history-table td { display: block; width: 100%; }
        .bi-history-table tr { border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px; margin-bottom: .75rem; padding: .5rem .25rem; }
        .bi-history-table td { border: 0; display: flex; justify-content: space-between; align-items: center; text-align: right; padding: .35rem .75rem; }
        .bi-history-table td::before { content: attr(data-label); font-size: .7rem; text-transform: uppercase; letter-spacing: .03em; color: #94A3B8; font-weight: 600; text-align: left; }
        .bi-history-table td[data-label="PO / Material"] { flex-direction: column; align-items: flex-start; text-align: left; }
    }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'Buyer / Style Stock'],
        ['label' => 'Bulk Issuing'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Bulk Issuing</h3>
                    <p class="app-hero-copy mb-0">Each issue splits into Bulk / Sample / Liability / Dead.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                @if(!$bookingPos->isEmpty() && $canCreate)
                    <button type="button" class="btn btn-primary" id="biNewBtn"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Bulk Issue</button>
                @endif
                <a href="{{ route('store.material.receivings.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receiving</a>
                <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Closing Stock</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            {{-- Tabs --}}
            @php $tabLabels = ['all' => 'All Issues', 'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month']; @endphp
            <div class="d-flex flex-wrap bi-tabs mb-3" id="biTabs" role="tablist">
                @foreach($tabLabels as $key => $label)
                    <button type="button" class="bi-tab {{ $tab === $key ? 'active' : '' }}" data-bi-tab="{{ $key }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                        {{ $label }}
                        <span class="badge rounded-pill {{ $tab === $key ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary-emphasis' }} ms-1" data-bi-count="{{ $key }}">{{ $counts[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            {{-- Search + chips --}}
            <div class="row g-3 align-items-center mb-2">
                <div class="col-12 col-lg-6">
                    <div class="bi-search">
                        <div class="input-group">
                            <span class="input-group-text bg-body"><i class="bi bi-search" aria-hidden="true"></i></span>
                            <input type="text" class="form-control" id="biSearchInput" value="{{ $q }}" autocomplete="off"
                                   placeholder="Search PO, buyer, style, material…" aria-label="Search bulk issues">
                        </div>
                        <span class="bi-search-spin d-none" id="biSearchSpin"><span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span></span>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="d-flex flex-wrap align-items-center gap-2" id="biChips"></div>
                </div>
            </div>

            {{-- Table (AJAX-swapped) + skeleton --}}
            <div id="biSkeleton" class="d-none">
                <div class="d-flex flex-column gap-2">
                    @for($s = 0; $s < 6; $s++)<div class="bi-skel-row"></div>@endfor
                </div>
            </div>
            <div id="biTableContainer" aria-live="polite">
                @include('store.material-stock._bulk-issues-table')
            </div>

            {{-- Sticky selection bar. Sits after the table so sticky-bottom docks
                 it against the viewport while the list scrolls above it. Hidden
                 until at least one row is selected. --}}
            <div class="bi-bulkbar d-none p-2 px-3 mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2" id="biBulkBar" role="region" aria-label="Actions for selected rows">
                <span class="fw-semibold"><i class="bi bi-check2-square me-1 text-primary" aria-hidden="true"></i>Selected: <span id="biSelCount">0</span> item(s)</span>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-success" data-bi-action="excel"><i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Export Excel</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bi-action="pdf"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Export PDF</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bi-action="print"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print Selected</button>
                    @if($canDelete)
                        <button type="button" class="btn btn-sm btn-danger" data-bi-action="delete"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Selected</button>
                    @endif
                    <button type="button" class="btn btn-sm btn-link text-decoration-none" data-bi-action="cancel"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Cancel Selection</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Slide-in create / edit panel. Rendered for anyone who can record a new
     issue OR correct an existing one — Management holds edit but not create. --}}
@if(!$bookingPos->isEmpty() && ($canCreate || $canEdit))
<div class="offcanvas offcanvas-end bi-offcanvas" tabindex="-1" id="biPanel" aria-labelledby="biPanelTitle">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="biPanelTitle">New Bulk Issue</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <form method="POST" id="biForm" action="{{ route('store.material.bulk-issues.store') }}">
            @csrf
            <input type="hidden" name="_method" id="biMethod" value="">
            <input type="hidden" name="booking_po_id" id="biPoId" value="" required>

            <label class="form-label fw-semibold">Booking PO / Material <span class="text-danger">*</span></label>
            <div class="bi-search mb-2" id="biSearchWrap">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="biPoSearch" autocomplete="off" placeholder="Click or type to search POs…" role="combobox" aria-expanded="false" aria-controls="biPoList">
                </div>
                <div id="biPoPanel" class="bi-search-panel d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                    <div class="small text-muted px-3 py-2 border-bottom" id="biPoHint"></div>
                    <div class="list-group list-group-flush" id="biPoList" role="listbox"></div>
                </div>
            </div>
            <div id="biSelectedRow" class="mb-3 d-none">
                <span class="bi-chip-sel"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span id="biSelectedText">—</span>
                    <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-decoration-none" id="biClearPo" title="Change selection" aria-label="Change selection"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </span>
            </div>

            {{-- Read-only summary --}}
            <div id="biSummaryGrid" class="p-3 mb-3 d-none">
                <div class="small fw-semibold text-uppercase text-muted mb-2"><i class="bi bi-magic me-1" aria-hidden="true"></i>PO / Material Summary</div>
                <div class="row g-2">
                    <div class="col-6"><div class="bi-sum-label">Buyer</div><div class="bi-sum-value" data-sum="buyer_name">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Season</div><div class="bi-sum-value" data-sum="season_name">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Style No</div><div class="bi-sum-value" data-sum="style_name">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">PO Number</div><div class="bi-sum-value" data-sum="po_no">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Material</div><div class="bi-sum-value" data-sum="material_name">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Description</div><div class="bi-sum-value" data-sum="material_description">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Art. No</div><div class="bi-sum-value" data-sum="art_no">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">SAP Code</div><div class="bi-sum-value" data-sum="sap_code">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">GMTS Color</div><div class="bi-sum-value" data-sum="gmts_color_name">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Material Color</div><div class="bi-sum-value" data-sum="material_color">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Size</div><div class="bi-sum-value" data-sum="size">—</div></div>
                    <div class="col-6"><div class="bi-sum-label">Unit</div><div class="bi-sum-value" data-sum="uom">—</div></div>
                </div>
                <div class="mt-2 small"><span class="badge bg-success-subtle text-success"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Available (Running): <span id="biRunning" class="fw-bold">0</span></span></div>
            </div>

            @if($requisitions->isNotEmpty())
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fulfil Requisition <span class="text-muted small">(optional)</span></label>
                    <select name="material_requisition_id" id="biReq" class="form-select">
                        <option value="">None</option>
                        @foreach($requisitions as $req)
                            <option value="{{ $req->id }}">#{{ $req->id }} · {{ $req->po_no }} · {{ $req->material_description }} ({{ ucfirst($req->status) }})</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <h6 class="text-uppercase text-muted fw-semibold small mb-2"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Indent Info</h6>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Indent Section</label>
                    <select name="indent_section" id="biSection" class="form-select">
                        <option value="">Select…</option>
                        @foreach($sections as $section)<option value="{{ $section }}">{{ $section }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6"><label class="form-label fw-semibold">Indent Person</label><input name="indent_person" id="biPerson" class="form-control" maxlength="100"></div>
                <div class="col-6"><label class="form-label fw-semibold">Requisition No</label><input name="requisition_number" id="biReqNo" class="form-control" maxlength="100"></div>
                <div class="col-6"><label class="form-label fw-semibold">Issue Date <span class="text-danger">*</span></label><input type="date" name="issue_date" id="biIssueDate" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Issue No</label>
                    {{-- Pre-filled with the generated number. bi-suggested renders it
                         lighter/italic until the user types, so it reads as a
                         suggestion rather than a value they entered. --}}
                    <input name="issue_no" id="biIssueNo" class="form-control bi-suggested" autocomplete="off">
                    <div class="form-text"><i class="bi bi-magic me-1" aria-hidden="true"></i>Auto-suggested · editable</div>
                </div>
            </div>

            <h6 class="text-uppercase text-muted fw-semibold small mb-2"><i class="bi bi-rulers me-1" aria-hidden="true"></i>Issue Quantities</h6>
            <div class="row g-2 mb-3 bi-qty-grid">
                <div class="col-6"><div class="bi-qty-card bulk"><label class="form-label fw-semibold text-success" for="biBulk">🟢 Bulk</label><input type="number" step="0.0001" min="0" name="bulk_qty" id="biBulk" placeholder="0" class="form-control form-control-sm bi-qty"></div></div>
                <div class="col-6"><div class="bi-qty-card sample"><label class="form-label fw-semibold text-primary" for="biSample">🔵 Sample</label><input type="number" step="0.0001" min="0" name="sample_qty" id="biSample" placeholder="0" class="form-control form-control-sm bi-qty"></div></div>
                <div class="col-6"><div class="bi-qty-card liability"><label class="form-label fw-semibold text-warning" for="biLiability">🟠 Liability</label><input type="number" step="0.0001" min="0" name="liability_qty" id="biLiability" placeholder="0" class="form-control form-control-sm bi-qty"></div></div>
                <div class="col-6"><div class="bi-qty-card dead"><label class="form-label fw-semibold text-danger" for="biDead">🔴 Dead</label><input type="number" step="0.0001" min="0" name="dead_qty" id="biDead" placeholder="0" class="form-control form-control-sm bi-qty"></div></div>
            </div>
            <div class="alert alert-warning py-2 px-3 small d-none" id="biOverWarn"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><span id="biOverText"></span></div>

            <div class="mb-3"><label class="form-label fw-semibold">Remarks</label><textarea name="remarks" id="biRemarks" rows="2" class="form-control" maxlength="1000"></textarea></div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1" aria-hidden="true"></i><span id="biSaveLabel">Save</span></button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cancel</button>
            </div>
            <div class="form-text mt-2"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Enter at least one of the four quantities.</div>
        </form>
    </div>
</div>
@endif

{{-- Hidden POST form used to stream selection exports/deletes. --}}
<form id="biBulkForm" method="POST" class="d-none">@csrf<div id="biBulkIds"></div></form>

<script type="application/json" id="bi-config">
    @php
        $poOptions = $bookingPos->map(fn ($po) => [
            'id' => $po->id,
            'po_no' => (string) $po->po_no,
            'style' => (string) $po->style_name,
            'buyer' => (string) $po->buyer_name,
            'material' => (string) ($po->item_name ?: $po->description),
            'art_no' => (string) $po->supplier_article,
            'available' => (float) ($prefill[$po->id]['running'] ?? 0),
        ])->values();
    @endphp
    {!! json_encode([
        'options' => $poOptions,
        'prefill' => $prefill,
        'state' => ['tab' => $tab, 'q' => $q, 'sort' => $sort, 'dir' => $dir, 'perPage' => $perPage],
        // Mirrors the server-side gate so the JS never fires an action the user
        // is not allowed to take. The controller re-checks regardless.
        'can' => ['create' => $canCreate, 'edit' => $canEdit, 'delete' => $canDelete],
        'routes' => [
            'index' => route('store.material.bulk-issues.index'),
            'store' => route('store.material.bulk-issues.store'),
            'poDetails' => route('store.material.bulk-issues.po-details', ['bookingPo' => '__ID__']),
            'show' => route('store.material.bulk-issues.show', ['materialBulkIssue' => '__ID__']),
            'update' => route('store.material.bulk-issues.update', ['materialBulkIssue' => '__ID__']),
            'bulkDestroy' => route('store.material.bulk-issues.bulk-destroy'),
            'exportExcel' => route('store.material.bulk-issues.export.excel'),
            'exportPdf' => route('store.material.bulk-issues.export.pdf'),
        ],
        'csrf' => csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endsection
