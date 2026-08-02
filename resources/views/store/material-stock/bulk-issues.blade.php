@extends('layouts.app')

@section('title', 'Material Stock — Bulk Issue')

@section('styles')
<style>
    /* --- Listing chrome ---------------------------------------------------
       One tone system for the whole screen: a hairline border, a very soft
       layered shadow, and colour reserved for the four quantity types. */
    .bi-surface {
        background: #fff; border: 1px solid #E9EDF3; border-radius: 18px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 12px 32px -22px rgba(15, 23, 42, .35);
    }
    .bi-toolbar {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        padding: 1.15rem 1.35rem; border-bottom: 1px solid #EFF2F7;
    }

    /* Segmented filter: one track, the active segment lifted out of it. Reads
       as a single control rather than four separate buttons. */
    .bi-seg {
        display: inline-flex; align-items: center; gap: .15rem; padding: .25rem;
        background: #F1F5F9; border: 1px solid #E9EDF3; border-radius: 12px; max-width: 100%; overflow-x: auto;
        scrollbar-width: none;
    }
    .bi-seg::-webkit-scrollbar { display: none; }
    .bi-tab {
        display: inline-flex; align-items: center; gap: .45rem; white-space: nowrap; border: 0;
        background: transparent; padding: .42rem .8rem; font-weight: 600; font-size: .8125rem;
        letter-spacing: -.01em; color: #64748B; border-radius: 9px;
        transition: background-color .18s ease, color .18s ease, box-shadow .18s ease;
    }
    .bi-tab:hover { color: var(--gx-primary, #0F172A); }
    .bi-tab.active {
        background: #fff; color: var(--gx-primary, #0F172A);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 2px 8px -4px rgba(15, 23, 42, .18);
    }
    /* Counts are metadata, not labels: same size, quieter, and lined up as
       figures so the four segments do not jitter as numbers change. */
    .bi-tab .badge {
        font-weight: 600; font-size: .6875rem; font-variant-numeric: tabular-nums;
        background: #E2E8F0 !important; color: #64748B !important; border-radius: 6px; padding: .15rem .35rem;
    }
    .bi-tab.active .badge { background: #DBEAFE !important; color: #1D4ED8 !important; }

    /* --- History table ----------------------------------------------------
       Airy rows, a quiet header, and a selected row marked by an accent rail
       rather than a wash of colour — the numbers stay the loudest thing. */
    .bi-history-table { --bi-row-pad: .85rem; border-collapse: separate; border-spacing: 0; }
    /* Header row reads as a label strip, not as data: smaller, wider tracking,
       and a tint just off the card so the first data row is the first thing
       with weight. */
    .bi-history-table thead th {
        background: #FBFCFE; border-bottom: 1px solid #EFF2F7;
        padding: .7rem 1rem; font-size: .6875rem; font-weight: 600; letter-spacing: .06em; color: #94A3B8;
    }
    .bi-history-table thead th .btn { font-size: .6875rem; letter-spacing: .06em; }
    .bi-history-table tbody td {
        padding: var(--bi-row-pad) 1rem; border-bottom: 1px solid #F1F5F9; vertical-align: middle;
    }
    .bi-history-table tbody tr { transition: background-color .15s ease, box-shadow .15s ease; }
    .bi-history-table tbody tr:hover { background: #FAFBFD; }
    .bi-history-table tbody tr:last-child td { border-bottom: 0; }
    /* Accent rail on the first cell: states the selection without repainting
       the row, so the quantity colours are not competing with a background. */
    .bi-history-table tbody td:first-child { box-shadow: inset 2px 0 0 transparent; }
    .bi-history-table tbody tr:has(.bi-row-check:checked) { background: #F5F9FF; }
    .bi-history-table tbody tr:has(.bi-row-check:checked) td:first-child { box-shadow: inset 2px 0 0 var(--gx-secondary-600, #2563EB); }
    .bi-history-table .form-check-input { border-color: #CBD5E1; }
    .bi-history-table .form-check-input:focus { box-shadow: 0 0 0 4px rgba(37, 99, 235, .12); }

    .bi-row-title { font-size: .875rem; font-weight: 600; letter-spacing: -.01em; color: var(--gx-primary, #0F172A); line-height: 1.4; }
    .bi-row-meta { font-size: .75rem; color: #94A3B8; line-height: 1.45; margin-top: .1rem; }
    .bi-row-date { font-size: .8125rem; font-weight: 500; color: #475569; font-variant-numeric: tabular-nums; white-space: nowrap; }

    /* Quantity cell: a tinted pill when there is a figure, a muted dash when
       there is not — so a row's shape shows which types it carries. */
    .bi-qtycell { text-align: right; font-variant-numeric: tabular-nums; }
    .bi-qtypill {
        display: inline-block; min-width: 2.75rem; padding: .2rem .5rem; border-radius: 8px;
        font-size: .8125rem; font-weight: 600; line-height: 1.35;
    }
    .bi-qtypill.bulk { background: #ECFDF5; color: #047857; }
    .bi-qtypill.sample { background: #EFF6FF; color: #1D4ED8; }
    .bi-qtypill.liability { background: #FFFBEB; color: #B45309; }
    .bi-qtypill.dead { background: #FFF1F2; color: #BE123C; }
    .bi-qtyzero { color: #CBD5E1; font-size: .8125rem; }

    .bi-section-badge {
        font-weight: 600; letter-spacing: .01em; font-size: .6875rem; border-radius: 7px; padding: .2rem .45rem;
    }
    /* Row actions stay quiet until the row is under the cursor — the list is
       for reading, and eight red buttons down a page is not that. */
    .bi-rowactions { opacity: .35; transition: opacity .15s ease; }
    .bi-history-table tbody tr:hover .bi-rowactions,
    .bi-history-table tbody tr:focus-within .bi-rowactions { opacity: 1; }
    .bi-rowactions .btn { border-radius: 9px; width: 30px; height: 30px; padding: 0; line-height: 1; }

    /* --- Floating bulk-action bar -----------------------------------------
       Detached from the page and centred over it, so it reads as a response to
       the selection rather than another row of the table. Frosted rather than
       solid: the list stays faintly visible underneath, which is what tells the
       user the bar belongs to what they just ticked. */
    .bi-bulkbar {
        position: fixed; left: 50%; bottom: 1.5rem; transform: translateX(-50%);
        z-index: 1030; width: max-content; max-width: min(980px, calc(100vw - 2rem));
        border: 1px solid rgba(226, 232, 240, .9); border-radius: 16px;
        background: rgba(255, 255, 255, .82);
        -webkit-backdrop-filter: saturate(180%) blur(14px); backdrop-filter: saturate(180%) blur(14px);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 20px 45px -20px rgba(15, 23, 42, .45);
        animation: biBarIn .22s cubic-bezier(.4, 0, .2, 1) both;
    }
    @supports not (backdrop-filter: blur(4px)) { .bi-bulkbar { background: #fff; } }
    @keyframes biBarIn { from { opacity: 0; transform: translate(-50%, 12px); } to { opacity: 1; transform: translate(-50%, 0); } }
    .bi-bulkbar-count {
        display: inline-flex; align-items: center; gap: .5rem; font-size: .8125rem; font-weight: 600;
        color: var(--gx-primary, #0F172A); white-space: nowrap;
    }
    .bi-bulkbar-num {
        display: inline-flex; align-items: center; justify-content: center; min-width: 1.5rem; height: 1.5rem;
        padding: 0 .4rem; border-radius: 8px; background: var(--gx-secondary-600, #2563EB); color: #fff;
        font-size: .75rem; font-variant-numeric: tabular-nums;
    }
    .bi-bulkbar-div { width: 1px; height: 22px; background: #E2E8F0; flex: none; }
    .bi-bulkbar .btn { border-radius: 10px; font-weight: 600; font-size: .8125rem; padding: .4rem .8rem; }

    /* The Full Table view has been removed — Summary is the only view. The
       column-filter styles it shared with the Select Items picker (.bi-ft-*)
       moved to _bulk-issue-form-styles, which is the partial both of the
       picker's shells include; this page pulls that in below. */

    /* Print: the list only, no page chrome. */
    @media print {
        .app-hero-card, .bi-toolbar, .bi-tablefoot, .bi-bulkbar,
        nav, .breadcrumb, footer { display: none !important; }
    }

    /* Table footer: pagination sits level with the count, both quiet. */
    .bi-tablefoot { font-variant-numeric: tabular-nums; }
    .bi-tablefoot .pagination { margin-bottom: 0; }
    .bi-tablefoot .page-link { border: 0; border-radius: 9px; font-size: .8125rem; font-weight: 600; color: #64748B; padding: .35rem .7rem; }
    .bi-tablefoot .page-item.active .page-link { background: var(--gx-secondary-600, #2563EB); color: #fff; }
    .bi-tablefoot .page-link:hover { background: #F1F5F9; }
    .bi-tablefoot .form-select-sm { border-color: #E9EDF3; border-radius: 9px; font-size: .8125rem; }

    /* Skeleton loader. */
    .bi-skel-row { height: 52px; border-radius: 10px; background: linear-gradient(90deg,#F1F5F9 25%,#E6EBF2 37%,#F1F5F9 63%); background-size: 400% 100%; animation: biShimmer 1.2s ease-in-out infinite; }
    @keyframes biShimmer { 0% { background-position: 100% 0; } 100% { background-position: 0 0; } }

    @include("store.material-stock._bulk-issue-form-styles")
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
                @if($hasBookingPos && $canCreate)
                    {{-- The full-width page is the primary way in. The slide-in
                         panel is still here behind "Quick entry" while the page
                         is being verified; it is the same form either way. --}}
                    <a href="{{ route('store.material.bulk-issues.create') }}" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Bulk Issue
                    </a>
                    <button type="button" class="btn btn-outline-secondary" id="biNewBtn" title="Open the same form in a side panel">
                        <i class="bi bi-layout-sidebar-inset-reverse me-1" aria-hidden="true"></i>Quick entry
                    </button>
                @endif
                <a href="{{ route('store.material.receivings.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receiving</a>
                <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Closing Stock</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="bi-surface">
        {{-- Toolbar: the period filter and the search sit on one line, so the
             whole way of narrowing the list is in a single place above it. --}}
        @php $tabLabels = ['all' => 'All Issues', 'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month']; @endphp
        <div class="bi-toolbar">
            <div class="bi-seg" id="biTabs" role="tablist" aria-label="Filter by period">
                @foreach($tabLabels as $key => $label)
                    <button type="button" class="bi-tab {{ $tab === $key ? 'active' : '' }}" data-bi-tab="{{ $key }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                        {{ $label }}
                        <span class="badge" data-bi-count="{{ $key }}">{{ $counts[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap" style="flex:1 1 18rem;">
                <div class="bi-search" style="flex:1 1 14rem;max-width:26rem;">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input type="text" class="form-control" id="biSearchInput" value="{{ $q }}" autocomplete="off"
                               placeholder="Search PO, buyer, style, material…" aria-label="Search bulk issues">
                    </div>
                    <span class="bi-search-spin d-none" id="biSearchSpin"><span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span></span>
                </div>
            </div>
        </div>

        {{-- Active filters, as removable tags. Empty (and so invisible) until a
             filter beyond the period tab is on. --}}
        <div class="d-flex flex-wrap align-items-center gap-2 px-4 pt-3" id="biChips"></div>

        <div class="p-2 p-lg-3">
            {{-- Table (AJAX-swapped) + skeleton --}}
            <div id="biSkeleton" class="d-none p-2">
                <div class="d-flex flex-column gap-2">
                    @for($s = 0; $s < 6; $s++)<div class="bi-skel-row"></div>@endfor
                </div>
            </div>
            <div id="biTableContainer" aria-live="polite">
                @include('store.material-stock._bulk-issues-table')
            </div>
        </div>
    </div>

    {{-- Floating selection bar. Fixed to the viewport rather than parked after
         the table, so the actions stay in the same place however far the list is
         scrolled. Hidden until at least one row is ticked — the JS only toggles
         d-none, so the markup keeps its ids and data-bi-action hooks. --}}
    <div class="bi-bulkbar d-none py-2 px-3 d-flex flex-wrap align-items-center gap-2" id="biBulkBar" role="region" aria-label="Actions for selected rows">
        <span class="bi-bulkbar-count">
            <span class="bi-bulkbar-num" id="biSelCount">0</span> selected
        </span>
        <span class="bi-bulkbar-div d-none d-sm-block" aria-hidden="true"></span>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-success" data-bi-action="excel"><i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Excel</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bi-action="pdf"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bi-action="print"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
            @if($canDelete)
                {{-- Divided off: the only action here that cannot be undone. --}}
                <span class="bi-bulkbar-div" aria-hidden="true"></span>
                <button type="button" class="btn btn-sm btn-danger" data-bi-action="delete"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
            @endif
            <button type="button" class="btn btn-sm btn-link text-decoration-none text-secondary" data-bi-action="cancel">Cancel</button>
        </div>
    </div>
</div>

{{-- Slide-in create / edit panel. Rendered for anyone who can record a new
     issue OR correct an existing one — Management holds edit but not create. --}}
@if($hasBookingPos && ($canCreate || $canEdit))
<div class="offcanvas offcanvas-end bi-offcanvas" tabindex="-1" id="biPanel" aria-labelledby="biPanelTitle">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="biPanelTitle">New Bulk Issue</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" x-data="bulkIssueWizard" @keydown="onKeydown($event)">
        @include("store.material-stock._bulk-issue-form")
    </div>
</div>

{{-- The panel renders the same issue matrix as the full-page route, so it needs
     the same one Filters dialog — the PO and the row filters both live in it. --}}
@include("store.material-stock._bulk-issue-filter-modal")
@endif

{{-- Hidden POST form used to stream selection exports/deletes. --}}
<form id="biBulkForm" method="POST" class="d-none">@csrf<div id="biBulkIds"></div></form>

<script type="application/json" id="bi-config">
    {{-- The PO list and its per-row stock are no longer embedded here: the picker
         fetches them on demand from po-search / po-items, so the page no longer
         ships up to a thousand POs it may never use. --}}
    {!! json_encode([
        'state' => ['tab' => $tab, 'q' => $q, 'sort' => $sort, 'dir' => $dir, 'perPage' => $perPage],
        // Mirrors the server-side gate so the JS never fires an action the user
        // is not allowed to take. The controller re-checks regardless.
        'can' => ['create' => $canCreate, 'edit' => $canEdit, 'delete' => $canDelete],
        'routes' => [
            'index' => route('store.material.bulk-issues.index'),
            'store' => route('store.material.bulk-issues.store'),
            'poDetails' => route('store.material.bulk-issues.po-details', ['bookingPo' => '__ID__']),
            'poSearch' => route('store.material.bulk-issues.po-search'),
            'poItems' => route('store.material.bulk-issues.po-items', ['bookingPo' => '__ID__']),
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
