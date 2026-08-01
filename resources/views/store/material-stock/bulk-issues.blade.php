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

    /* --- Full Table view --------------------------------------------------
       The 22-column register. Breaks out of the page container to use the full
       viewport width; anything past that scrolls the PAGE sideways rather than
       an inner box, which is what lets the header row stay stuck while reading. */
    .bi-fullbleed { margin-inline: calc(50% - 50vw); border-radius: 0; border-inline: 0; }
    .bi-viewseg .bi-tab { gap: .35rem; }
    .bi-viewseg .bi-tab i { font-size: .8125rem; }

    .bi-ft-bar {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        padding: .75rem 1rem; border-bottom: 1px solid #EFF2F7; background: #FBFCFE;
    }
    .bi-ft-count { font-size: .8125rem; font-weight: 600; color: #475569; font-variant-numeric: tabular-nums; }
    .bi-ft-chip {
        display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; font-weight: 600;
        color: var(--gx-secondary-700, #1D4ED8); background: #EFF6FF; border: 1px solid #DBEAFE;
        border-radius: 999px; padding: .15rem .6rem;
    }
    .bi-ft-chip.is-sel { color: #047857; background: #ECFDF5; border-color: #A7F3D0; }
    .bi-ft-hint { font-size: .7rem; color: #94A3B8; }
    .bi-ft-bar .btn { border-radius: 9px; font-weight: 600; font-size: .8125rem; }

    .bi-fulltable { width: max-content; min-width: 100%; border-collapse: separate; border-spacing: 0; font-size: .8125rem; }
    /* Sticky against the page scroll — there is deliberately no overflow
       wrapper, since a scroll container would anchor this to itself instead. */
    .bi-fulltable thead th {
        position: sticky; top: 0; z-index: 20; vertical-align: bottom; text-align: left;
        background: #FEF9E7; border-bottom: 2px solid #F1D592; border-right: 1px solid #F3E4BE;
        padding: .5rem .6rem; font-size: .6875rem; font-weight: 700; letter-spacing: .02em;
        color: #6B5417; white-space: nowrap;
    }
    .bi-fulltable tbody td {
        padding: .45rem .6rem; border-bottom: 1px solid #F1F5F9; border-right: 1px solid #F5F7FA;
        vertical-align: top; color: #1E293B; white-space: nowrap;
    }
    .bi-fulltable tbody tr:hover td { background: #FAFBFD; }
    .bi-fulltable tbody tr.is-picked td { background: #F5F9FF; }
    .bi-ft-num, .bi-fulltable td.bi-ft-num { text-align: right; font-variant-numeric: tabular-nums; }
    /* Long free text is capped and told in full on hover, so one description
       cannot set the width of the whole register. */
    .bi-ft-wide { max-width: 22rem; }
    .bi-fulltable td.bi-ft-wide span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bi-ft-blank { color: #CBD5E1; }
    .bi-ft-check { width: 38px; text-align: center; }
    .bi-fulltable td.bi-ft-check { white-space: nowrap; }

    .bi-ft-head { display: flex; align-items: center; gap: .35rem; }
    .bi-ft-label { flex: 1 1 auto; }
    .bi-ft-fbtn {
        flex: none; border: 1px solid transparent; background: transparent; border-radius: 6px;
        width: 20px; height: 20px; line-height: 1; padding: 0; color: #A0894A; font-size: .7rem;
        transition: background-color .15s ease, color .15s ease, border-color .15s ease;
    }
    .bi-ft-fbtn:hover { background: #fff; border-color: #E7D3A1; color: #6B5417; }
    .bi-ft-fbtn.is-on { background: var(--gx-secondary-600, #2563EB); border-color: var(--gx-secondary-600, #2563EB); color: #fff; }

    /* Column dropdown: Excel's order — the two sorts, a search, then Select All
       over the value list. */
    .bi-ft-menu {
        position: absolute; top: 100%; left: 0; z-index: 60; width: 250px; margin-top: .25rem;
        background: #fff; border: 1px solid #E2E8F0; border-radius: 12px; padding: .35rem;
        box-shadow: 0 12px 32px -12px rgba(15, 23, 42, .4); white-space: normal;
        font-weight: 500; letter-spacing: normal; color: #334155; text-transform: none;
    }
    .bi-ft-mitem {
        display: flex; align-items: center; gap: .5rem; width: 100%; border: 0; background: transparent;
        padding: .4rem .5rem; border-radius: 8px; font-size: .8125rem; font-weight: 500; color: #334155; text-align: left;
    }
    .bi-ft-mitem:hover { background: #F1F5F9; }
    .bi-ft-mitem i { color: #64748B; }
    .bi-ft-msep { height: 1px; background: #EFF2F7; margin: .3rem .25rem; }
    .bi-ft-msearch { position: relative; padding: .15rem .25rem .35rem; }
    .bi-ft-msearch i { position: absolute; left: .6rem; top: .55rem; font-size: .75rem; color: #94A3B8; }
    .bi-ft-msearch .form-control { padding-left: 1.75rem; border-radius: 8px; border-color: #E2E8F0; font-size: .8125rem; }
    .bi-ft-mall, .bi-ft-mopt {
        display: flex; align-items: center; gap: .5rem; padding: .3rem .5rem; border-radius: 7px;
        font-size: .8125rem; font-weight: 500; cursor: pointer; margin: 0;
    }
    .bi-ft-mall { font-weight: 600; border-bottom: 1px solid #EFF2F7; border-radius: 7px 7px 0 0; }
    .bi-ft-mall:hover, .bi-ft-mopt:hover { background: #F8FAFC; }
    .bi-ft-mall span, .bi-ft-mopt span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    /* The value list is the one place a scroll box is right: it can hold
       hundreds of values and must not push the menu off screen. */
    .bi-ft-mlist { max-height: 190px; overflow-y: auto; }
    .bi-ft-mempty { padding: .6rem .5rem; font-size: .75rem; color: #94A3B8; text-align: center; }
    .bi-ft-mfoot {
        display: flex; align-items: center; justify-content: space-between; gap: .5rem;
        border-top: 1px solid #EFF2F7; margin-top: .3rem; padding: .4rem .35rem .15rem;
    }

    /* --- Item picker column filters ---------------------------------------
       Same dropdown component as the Full Table, inside the Select Items modal.
       One difference: the picker's table sits in a horizontally scrolling box,
       which would clip an absolutely positioned menu — so those are pinned to
       the viewport and placed by the picker JS. */
    #biItemsModal [data-bi-pcol] { position: relative; vertical-align: bottom; white-space: nowrap; }
    #biItemsModal .bi-ft-menu { position: fixed; top: auto; left: auto; z-index: 1090; }
    #biItemsModal .bi-ft-head { gap: .3rem; }
    #biItemsModal .bi-ft-fbtn { color: #94A3B8; }
    #biItemsModal .bi-ft-fbtn:hover { background: #fff; border-color: #E2E8F0; color: #334155; }
    /* Nine columns will not fit the modal at every width; the picker scrolls
       sideways rather than crushing the material description. */
    .bi-pick-wide table { min-width: 1020px; }
    .bi-pick-wide td.bi-ft-wide { max-width: 16rem; }
    .bi-pick-wide td.bi-ft-wide .bi-cell-sub { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    /* Print: the register only, no page chrome. */
    @media print {
        .app-hero-card, .bi-toolbar, .bi-ft-bar, .bi-tablefoot, .bi-bulkbar,
        .bi-ft-check, nav, .breadcrumb, footer { display: none !important; }
        .bi-fullbleed { margin-inline: 0; }
        .bi-fulltable { font-size: 8pt; width: 100%; }
        .bi-fulltable thead th { background: #FEF9E7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .bi-fulltable td.bi-ft-wide span { white-space: normal; }
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

    {{-- View mode wraps the whole surface: the Full Table needs the card to
         drop its width limit, and both tables live inside the swappable
         partial, which reads `mode` from this scope. --}}
    <div x-data="{
            mode: (localStorage.getItem('bulkIssueView') === 'full' ? 'full' : 'summary'),
            setMode(m) { this.mode = m; try { localStorage.setItem('bulkIssueView', m); } catch (e) { /* private mode */ } }
         }">
    <div class="bi-surface" :class="{ 'bi-fullbleed': mode === 'full' }">
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

                {{-- View mode. Summary is the readable, mobile-friendly list;
                     Full Table is the 22-column register with column filters.
                     The choice is remembered per browser. --}}
                <div class="bi-seg bi-viewseg ms-auto" role="group" aria-label="View mode">
                    <button type="button" class="bi-tab" :class="{ 'active': mode === 'summary' }"
                            @click="setMode('summary')" :aria-pressed="mode === 'summary'">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>Summary
                    </button>
                    <button type="button" class="bi-tab" :class="{ 'active': mode === 'full' }"
                            @click="setMode('full')" :aria-pressed="mode === 'full'">
                        <i class="bi bi-table" aria-hidden="true"></i>Full Table
                    </button>
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
         d-none, so the markup keeps its ids and data-bi-action hooks.

         Summary only: the Full Table carries its own selection and its own
         export buttons, so two bars would be two different selections. --}}
    <div class="bi-bulkbar d-none py-2 px-3 d-flex flex-wrap align-items-center gap-2" id="biBulkBar" role="region" aria-label="Actions for selected rows" x-show="mode === 'summary'">
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
    </div>{{-- /view mode --}}
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

@include("store.material-stock._bulk-issue-item-picker")
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
