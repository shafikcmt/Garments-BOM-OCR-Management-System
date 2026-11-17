@extends('layouts.app')

@section('title', 'Booking Preview / Booking Generate')

@section('styles')
@include('supply-chain.bookings.partials._po-module-theme')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
    /* The legacy --booking-* names are kept as ALIASES onto the module tokens
       rather than deleted. Several hundred lines below still read them, and
       re-pointing the six variables recolours all of it coherently in one
       place — which is the whole reason the indigo could drift from the
       document blue in the first place. */
    :root {
        --booking-primary: var(--po-blue);
        --booking-primary-dark: var(--po-blue-deep);
        --booking-primary-soft: var(--po-tint);
        --booking-ink: var(--po-ink);
        --booking-muted: var(--po-muted);
        --booking-border: var(--po-line);
        --booking-card-shadow: var(--po-shadow);
    }
    .booking-page {
        min-height: calc(100vh - 110px);
        /* Paper, not a coloured dashboard ground. The sheet this module
           produces is white; the desk it sits on should be quiet. */
        background: linear-gradient(180deg, #FFFFFF 0%, var(--po-paper) 220px, var(--po-paper) 100%);
    }
    /* Full width, matching how the rest of the app lays out: components.css
       sets `.content .container-fluid { max-width: none }`, and this module's
       1480px cap was the reason a wide monitor showed ~790px of empty gutter
       beside a table that had columns to spare. */
    .booking-shell { max-width: none; margin: 0 auto; }
    /* The dark indigo hero and the badge-style stat pills are gone — replaced
       by .po-head and .po-kpi in the theme partial, so this page's header and
       KPI cards are the same objects the Admin and detail screens use. */
    .booking-hero-copy { max-width: 680px; }
    /* Radius collapsed from 24px to the module's one surface value, and the
       glassmorphism dropped: a blurred translucent card over a white page was
       doing nothing except making this card differ from every other card in
       the app. */
    .booking-card {
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-surface);
        box-shadow: var(--po-shadow);
        overflow: visible;
        background: var(--po-surface);
    }
    .booking-card .card-header {
        background: transparent;
        border-bottom: 1px solid var(--po-line-soft);
        border-radius: var(--po-r-surface) var(--po-r-surface) 0 0;
    }
    .booking-section-title { display: flex; align-items: center; gap: 10px; color: var(--po-ink); font-size: 15px; letter-spacing: -.01em; }
    .booking-title-icon {
        width: 32px;
        height: 32px;
        flex: 0 0 32px;
        border-radius: var(--po-r-control);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--po-tint);
        color: var(--po-blue-deep);
        font-size: 15px;
    }
    .booking-filter label {
        font-size: 10px;
        font-weight: 800;
        color: var(--po-muted);
        text-transform: uppercase;
        letter-spacing: .09em;
        margin-bottom: 5px;
    }
    /* Every control in the module is 40px tall on one radius, so a select, an
       input and a button sitting in the same filter row line up exactly. */
    .booking-filter .form-control,
    .booking-filter .form-select {
        min-height: 40px;
        border-radius: var(--po-r-control);
        border-color: var(--po-line);
        color: var(--po-ink);
        box-shadow: none;
        font-weight: 600;
        font-size: 13px;
        background-color: var(--po-surface);
    }
    .booking-filter .form-control::placeholder { color: #94a3b8; font-weight: 500; }
    .booking-filter .form-control:focus,
    .booking-filter .form-select:focus {
        border-color: var(--po-blue);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    .booking-search-field { position: relative; }
    .booking-search-field .form-control { padding-left: 46px; }
    /* Anchor the icon to the input (last child) and centre it on the 38px
       field: bottom = (38 - 18) / 2 = 10px. line-height:1 keeps the glyph box
       from adding stray vertical space. Bottom-anchoring stays centred even if
       the label wraps to two lines on small screens. */
    .booking-search-icon {
        position: absolute;
        left: 16px;
        bottom: 10px;
        line-height: 1;
        color: #64748b;
        font-size: 18px;
        pointer-events: none;
    }
    /* Primary is a flat, solid block of the document blue. The gradient +
       lift-on-hover it replaced is the stock "SaaS CTA" treatment, and it made
       Search compete with Generate PO for attention. One filled button per
       area, everything else quiet. */
    .booking-primary-btn,
    .btn-generate {
        border: 1px solid var(--po-blue);
        border-radius: var(--po-r-control);
        background: var(--po-blue);
        color: #fff;
        font-weight: 800;
        min-height: 40px;
        box-shadow: none;
    }
    .booking-primary-btn:hover,
    .btn-generate:hover { color: #fff; background: var(--po-blue-deep); border-color: var(--po-blue-deep); }
    .booking-primary-btn:focus-visible,
    .btn-generate:focus-visible { outline: 2px solid var(--po-blue); outline-offset: 2px; }
    .btn-soft-reset {
        border-radius: var(--po-r-control);
        min-height: 40px;
        border-color: var(--po-line);
        color: var(--po-ink);
        font-weight: 700;
        background: var(--po-surface);
    }
    .btn-soft-reset:hover { background: var(--po-paper); border-color: #BFD3E1; color: var(--po-ink); }
    /* Searchable dropdowns (Tom Select) — matched to the booking filter input style */
    .booking-filter .ts-wrapper { font-weight: 600; }
    .booking-filter .ts-control {
        min-height: 40px;
        border-radius: var(--po-r-control);
        border-color: var(--po-line);
        color: var(--po-ink);
        background-color: var(--po-surface);
        box-shadow: none;
        padding: 5px 12px;
    }
    .booking-filter .ts-control input::placeholder { color: #94a3b8; font-weight: 500; }
    .booking-filter .ts-wrapper.focus .ts-control,
    .booking-filter .ts-wrapper.dropdown-active .ts-control {
        border-color: var(--po-blue);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    /* Lift the filter card above the table card below it, so its open dropdowns
       (IHOD, Vendor, etc.) paint over the table instead of being clipped by it.
       This z-index is what does the work — the cards no longer use
       backdrop-filter, so neither creates a stacking context of its own. */
    .booking-filter-card { position: relative; z-index: 30; }
    .booking-filter .ts-dropdown {
        z-index: 40;
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-control);
        box-shadow: 0 18px 44px rgba(11, 43, 60, .14);
        overflow: hidden;
        margin-top: 6px;
    }
    .booking-filter .ts-dropdown .ts-dropdown-content { padding: 4px; }
    .booking-filter .ts-dropdown .option {
        border-radius: 8px;
        padding: 8px 12px;
        font-weight: 600;
        color: var(--booking-ink);
    }
    .booking-filter .ts-dropdown .option.active { background: var(--booking-primary-soft); color: var(--booking-primary); }
    .booking-filter .ts-dropdown .no-results { padding: 8px 12px; color: var(--booking-muted); font-weight: 600; }
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
    /* The header band is the booking sheet's own #E0F2FE tint, so the list and
       the document it produces read as one system.

       Zebra striping removed on purpose: with a tinted header AND a hover
       state, three row signals competed and the hover — the only one that
       means anything — was the hardest to see. Hairline dividers instead. */
    .booking-table {
        --bs-table-hover-bg: transparent;
        border-color: var(--po-line-soft) !important;
    }
    .booking-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: var(--po-tint) !important;
        color: var(--po-blue-deep) !important;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .09em;
        text-transform: uppercase;
        white-space: nowrap;
        vertical-align: middle;
        padding: 12px;
        border-bottom: 1px solid var(--po-line) !important;
    }
    .booking-table tbody td {
        vertical-align: middle;
        font-size: 13px;
        padding: 12px;
        line-height: 1.35;
        color: var(--po-ink);
        border-top: 1px solid var(--po-line-soft);
        border-bottom: 0;
    }
    .booking-table tbody tr:first-child td { border-top: 0; }
    .booking-table tbody tr { transition: background .14s ease; }
    .booking-table tbody tr:hover { background: #F2F8FC; }
    .booking-table .po-num { text-align: right; font-variant-numeric: tabular-nums; font-feature-settings: "tnum" 1; }
    @media (prefers-reduced-motion: reduce) { .booking-table tbody tr { transition: none; } }
    .item-cell .small { line-height: 1.25; }
    .action-cell .btn { border-radius: var(--po-r-control); padding: 7px 14px; }
    .preview-outline-btn {
        border: 1px solid var(--po-blue);
        color: var(--po-blue);
        background: var(--po-surface);
        font-weight: 800;
        box-shadow: none;
    }
    .preview-outline-btn:hover { background: var(--po-blue); color: #fff; border-color: var(--po-blue); }
    .preview-outline-btn:hover { background: var(--po-tint); color: var(--po-blue-deep); border-color: #BAE0F2; }
    .booking-action-wrap { white-space: nowrap; }
    /* Auto width, NOT the old fixed 38px square. This button was styled as an
       icon-only kebab; once it gained its visible "Actions" label the fixed
       width clipped the text. It sizes to its content now. */
    .booking-kebab-btn {
        height: 34px;
        padding: 0 12px !important;
        border-radius: var(--po-r-control) !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--po-surface);
        color: var(--po-ink);
        border: 1px solid var(--po-line);
        font-size: 12px;
        font-weight: 800;
        box-shadow: none;
        white-space: nowrap;
    }
    .booking-kebab-btn:hover,
    .booking-kebab-btn:focus { background: var(--po-tint); color: var(--po-blue-deep); border-color: #BAE0F2; }
    .booking-action-dropdown {
        min-width: 196px;
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-control);
        padding: 6px;
        box-shadow: 0 22px 45px rgba(11, 43, 60, .16);
        z-index: 1080;
    }
    .booking-action-dropdown .dropdown-item {
        border-radius: 7px;
        padding: 9px 10px;
        font-weight: 700;
        color: var(--po-ink);
        font-size: 13px;
    }
    .booking-action-dropdown .dropdown-item:hover { background: var(--po-tint); color: var(--po-blue-deep); }
    .po-pill,
    .po-mini-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 11px;
        border-radius: var(--po-r-pill);
        background: var(--po-tint);
        color: var(--po-blue-deep);
        border: 1px solid #BAE0F2;
        font-weight: 900;
        font-size: 11px;
        font-variant-numeric: tabular-nums;
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
    .booking-pagination a:hover { background: var(--po-tint); border-color: #BAE0F2; color: var(--po-blue-deep); }
    .booking-pagination .active { background: var(--po-blue) !important; border-color: var(--po-blue) !important; color: #fff !important; box-shadow: none; }
    .booking-pagination .disabled { opacity: .48; cursor: not-allowed; background: #f8fafc !important; }
    /* Wide enough for the 198mm sheet at the 1.35 default (~1010px) plus the
       modal's own padding — below that, .bf-sheet's max-width:100% clamps the
       sheet and squeezes the fixed-mm table columns instead of scaling them.
       Capped rather than full-bleed: the sheet is a fixed width, so extra
       modal width past ~1400px only adds grey margin around the document. */
    .booking-preview-modal .modal-dialog { max-width: min(1400px, 96vw); }
    /* Modal chrome, deliberately designed rather than left at Bootstrap's
       default: the header is the module's paper tone, the zoom cluster is a
       single grouped control instead of three loose buttons, and the body
       ground is a neutral grey so the white sheet reads as paper on a desk. */
    .booking-preview-modal .modal-content {
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-surface);
        box-shadow: 0 30px 90px rgba(11, 43, 60, .28);
        overflow: hidden;
    }
    .booking-preview-modal .modal-header {
        background: linear-gradient(180deg, #FFFFFF 0%, var(--po-paper) 100%);
        border-bottom: 1px solid var(--po-line);
        gap: 10px;
        padding: 14px 18px;
    }
    .booking-preview-modal .modal-title { font-size: 15px; font-weight: 800; letter-spacing: -.01em; color: var(--po-ink); }
    .booking-preview-title-wrap { flex: 1 1 auto; min-width: 250px; }
    /* The three zoom controls read as one instrument, not three stray buttons. */
    .booking-preview-header-tools {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        flex-wrap: nowrap;
        margin-left: auto;
        padding: 3px;
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-control);
        background: var(--po-surface);
    }
    .booking-preview-zoom-btn {
        height: 30px;
        padding: 0 10px;
        border: 0;
        border-radius: 7px;
        color: var(--po-muted);
        font-size: 12px;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: transparent;
    }
    .booking-preview-zoom-btn:hover:not(:disabled) { background: var(--po-tint); color: var(--po-blue-deep); }
    .booking-preview-zoom-btn:disabled { opacity: .4; }
    .booking-preview-zoom-value {
        min-width: 52px;
        text-align: center;
        font-size: 12px;
        font-weight: 900;
        color: var(--po-blue-deep);
        font-variant-numeric: tabular-nums;
    }
    .booking-preview-inner { max-height: 86vh; overflow: auto; background: #E7EDF2; padding: 14px !important; scroll-behavior: smooth; }
    .booking-preview-steps { display: inline-flex; align-items: center; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
    .booking-preview-steps .bp-step {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .01em;
        color: #64748b;
        background: var(--po-tint);
        border: 1px solid #e2e8f0;
    }
    .booking-preview-steps .bp-step.is-active { color: #fff; background: var(--po-blue); border-color: transparent; }
    .booking-preview-steps .bp-step-arrow { color: #94a3b8; font-size: 13px; }
    .booking-confirm-overlay {
        position: absolute;
        inset: 0;
        z-index: 30;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 23, 42, .42);
        backdrop-filter: blur(3px);
        border-radius: 20px;
    }
    .booking-confirm-overlay[hidden] { display: none; }
    .booking-confirm-card {
        width: 100%;
        max-width: 420px;
        background: #fff;
        border-radius: 18px;
        padding: 24px 22px 20px;
        text-align: center;
        box-shadow: 0 30px 70px rgba(15, 23, 42, .3);
        animation: bookingConfirmPop .18s ease-out;
    }
    @keyframes bookingConfirmPop { from { transform: translateY(8px) scale(.98); opacity: 0; } to { transform: none; opacity: 1; } }
    .booking-confirm-icon {
        width: 54px;
        height: 54px;
        margin: 0 auto 12px;
        border-radius: 16px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--po-tint);
        color: var(--po-blue-deep);
        font-size: 26px;
    }
    .booking-confirm-title { font-weight: 900; color: #0f172a; margin-bottom: 8px; font-size: 1.15rem; }
    .booking-confirm-message { color: #475569; font-size: 13.5px; line-height: 1.5; margin-bottom: 18px; }
    .booking-confirm-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .booking-confirm-actions .btn { min-width: 120px; }
    @media (max-width: 575px) {
        .booking-confirm-actions { flex-direction: column-reverse; }
        .booking-confirm-actions .btn { width: 100%; }
    }
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

    .booking-view-switch {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px;
        padding: 4px;
        border-radius: var(--po-r-control);
        border: 1px solid var(--po-line);
        background: var(--po-surface);
        box-shadow: var(--po-shadow-sm);
    }
    .booking-view-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 34px;
        padding: 0 14px;
        border-radius: 7px;
        color: var(--po-muted);
        font-size: 13px;
        font-weight: 800;
        letter-spacing: -.01em;
        border: 1px solid transparent;
        transition: .16s ease;
    }
    .booking-view-btn:hover {
        color: var(--po-blue-deep);
        background: var(--po-tint);
    }
    .booking-view-btn.is-active {
        color: #fff;
        border-color: var(--po-blue);
        background: var(--po-blue);
    }
    .booking-view-btn:focus-visible { outline: 2px solid var(--po-blue); outline-offset: 2px; }
    @media (prefers-reduced-motion: reduce) { .booking-view-btn { transition: none; } }
    .booking-tab-panel.is-hidden { display: none; }


    .po-control-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }
    .po-control-card {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
        padding: 15px;
        box-shadow: 0 14px 34px rgba(15,23,42,.055);
    }
    .po-control-label { display:block; color:#64748b; font-size:12px; font-weight:800; }
    .po-control-value { display:block; color:#0f172a; font-size:1.55rem; line-height:1; font-weight:950; letter-spacing:-.045em; margin-top:5px; }
    .po-control-icon {
        width: 38px;
        height: 38px;
        border-radius:var(--gx-radius);
        display:inline-flex;
        align-items:center;
        justify-content:center;
        background:var(--po-tint);
        color:var(--po-blue-deep);
        font-size:18px;
    }
    .po-control-filter-card {
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        background: #fff;
        padding: 14px;
        box-shadow: 0 12px 28px rgba(15,23,42,.045);
    }
    .po-control-search { position: relative; }
    .po-control-search i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; pointer-events:none; }
    .po-control-search .form-control { padding-left:42px; min-height:44px; }
    .po-status-stack { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
    /* One pill geometry across the module; the colours keep the meanings they
       already carried — green generated, amber re-generated, blue completed. */
    .po-status-pill, .po-change-pill {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:5px 11px;
        border-radius:var(--po-r-pill);
        font-size:11px;
        font-weight:900;
        line-height:1;
        border:1px solid transparent;
        white-space:nowrap;
    }
    .po-status-generated { color:var(--po-ok); background:var(--po-ok-bg); border-color:var(--po-ok-border); }
    .po-status-regenerated { color:var(--po-warn); background:var(--po-warn-bg); border-color:var(--po-warn-border); }
    .po-status-completed { color:var(--po-info); background:var(--po-info-bg); border-color:var(--po-info-border); }
    .po-change-clean { color:var(--po-muted); background:var(--po-paper); border-color:var(--po-line); }
    .po-change-warning { color:var(--po-danger); background:var(--po-danger-bg); border-color:var(--po-danger-border); }
    .po-change-card {
        border:1px solid #e2e8f0;
        border-radius:var(--gx-radius);
        background:#fff;
        padding:10px;
        margin-top:8px;
        min-width:320px;
    }
    .po-change-table { margin-bottom:0; }
    .po-change-table th { color:#475569; font-size:10px; text-transform:uppercase; letter-spacing:.05em; }
    .po-change-table td { font-size:11px; color:#334155; padding:6px 8px; vertical-align:top; }
    .po-history-list { display:flex; flex-direction:column; gap:6px; }
    .po-history-entry { border:1px solid #e2e8f0; border-radius:12px; padding:8px 10px; background:#f8fafc; }
    .po-history-entry .title { color:#0f172a; font-size:12px; font-weight:900; text-transform:capitalize; }
    .po-history-entry .meta { color:#64748b; font-size:11px; }
    .po-control-empty { min-height:180px; display:flex; align-items:center; justify-content:center; text-align:center; color:#64748b; }
    .generated-po-card .card-header { background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); }
    /* All four actions on ONE line. nowrap is the point: with wrap enabled the
       fourth button orphaned onto a second row, which made that row taller than
       its neighbours and the whole table look ragged. The column is sized for
       the full set below, so nothing has to wrap. */
    .generated-po-actions { display:flex; justify-content:center; gap:6px; flex-wrap:nowrap; }
    .generated-po-action {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:5px;
        min-height:30px;
        padding:5px 10px;
        border-radius:var(--po-r-pill);
        border:1px solid var(--po-line);
        background:var(--po-surface);
        color:var(--po-ink);
        text-decoration:none;
        font-size:11px;
        font-weight:800;
        white-space:nowrap;
        box-shadow:none;
    }
    .generated-po-action:hover { color:var(--po-blue-deep); border-color:#BAE0F2; background:var(--po-tint); }
    .generated-po-action:focus-visible { outline:2px solid var(--po-blue); outline-offset:2px; }

    /* IHOD From/To need no styling of their own — they are plain selects and
       pick up the shared .booking-filter .form-select rules, which is the whole
       point of using selects rather than bespoke date inputs. */
    @media (max-width: 1199px) { .po-control-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575px) { .po-control-grid { grid-template-columns: 1fr; } }

</style>
@endsection

@section('content')
@php
    $selectedBuyer = request('buyer');
    $selectedSeason = request('season');
    $selectedSapCode = request('sap_code');
    $selectedVendor = request('vendor');
    $selectedIhodFrom = request('ihod_from');
    $selectedIhodTo = request('ihod_to');
    $selectedKeyword = request('keyword');
    $generatedPoCount = $generatedPos->count();
@endphp

<div class="booking-page po-scope p-2 p-md-3"><div class="booking-shell">
    <div class="po-head mb-3">
        <div class="d-flex align-items-center gap-3 booking-hero-copy">
            <span class="po-head-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
            <div>
                <div class="po-micro">Supply Chain</div>
                <h4 class="po-head-title">PO Generation</h4>
            </div>
        </div>
    </div>

    {{-- KPI cards. The docket rail on the left edge says which state each
         figure counts — blue for the pending queue, emerald for what is already
         generated — so the two are distinguishable before the label is read. --}}
    <div class="po-kpi-grid mb-3">
        <div class="po-kpi po-rail">
            <span class="po-kpi-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
            <div class="po-kpi-body">
                <div class="po-micro">Pending Preview</div>
                <div class="po-figure" id="pendingTotal">{{ $pendingRows->total() }}</div>
                <div class="po-kpi-sub">Orders awaiting a PO</div>
            </div>
        </div>
        <div class="po-kpi po-kpi-ok po-rail po-rail-ok">
            <span class="po-kpi-icon"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></span>
            <div class="po-kpi-body">
                <div class="po-micro">Generated PO List</div>
                <div class="po-figure" id="recentTotal">{{ $generatedPoCount }}</div>
                <div class="po-kpi-sub">POs issued to vendors</div>
            </div>
        </div>
    </div>

    <div id="bookingAjaxAlert" class="alert d-none" role="alert"></div>

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <div class="booking-view-switch" id="bookingViewSwitch">
            <a href="#pending-generated-po" class="booking-view-btn is-active" data-booking-tab="pending-generated-po"><i class="bi bi-list-check" aria-hidden="true"></i>Pending Preview</a>
            <a href="#recent-generated-po" class="booking-view-btn" data-booking-tab="recent-generated-po"><i class="bi bi-file-earmark-check" aria-hidden="true"></i>Generated PO</a>
        </div>
    </div>

    <div class="card booking-card booking-filter-card mb-4">
        <div class="card-header py-3">
            <h6 class="mb-0 fw-bold booking-section-title"><span class="booking-title-icon"><i class="bi bi-funnel" aria-hidden="true"></i></span>Filter pending booking data</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('supply_chain.bookings.index') }}" class="booking-filter" id="bookingFilterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Buyer</label>
                        <select name="buyer" class="form-select booking-auto-filter">
                            <option value="">All Buyer</option>
                            @foreach($filterOptions['buyers'] as $buyerOption)
                                <option value="{{ $buyerOption['value'] }}" @selected($selectedBuyer === $buyerOption['value'])>{{ $buyerOption['label'] }}</option>
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
                        <label class="form-label">SAP Code</label>
                        <select name="sap_code" class="form-select booking-auto-filter">
                            <option value="">All SAP Code</option>
                            @foreach($filterOptions['sap_codes'] as $sapCode)
                                <option value="{{ $sapCode }}" @selected($selectedSapCode === $sapCode)>{{ $sapCode }}</option>
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
                    {{-- IHOD is the contract shipment DATE and filters as a From/To
                         window, but the two ends are pickers of the dates that
                         ACTUALLY EXIST in the data — the same list the old single
                         dropdown offered — not a blank calendar. Staff choosing a
                         shipment window do not know the dates in advance, and a
                         free calendar lets them pick a day no order ships on.

                         Plain <select>s so the Tom Select initialiser below turns
                         them into searchable dropdowns exactly like Buyer, Season,
                         SAP Code and Vendor. Values stay ISO YYYY-MM-DD, so
                         applyDateRangeCellFilter() is unchanged. --}}
                    <div class="col-md-2">
                        <label class="form-label" for="ihodFrom">IHOD from</label>
                        <select id="ihodFrom" name="ihod_from" class="form-select booking-auto-filter" autocomplete="off">
                            <option value="">Any date</option>
                            @foreach($filterOptions['ihods'] as $ihod)
                                <option value="{{ $ihod }}" @selected($selectedIhodFrom === $ihod)>{{ $ihod }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="ihodTo">IHOD to</label>
                        <select id="ihodTo" name="ihod_to" class="form-select booking-auto-filter" autocomplete="off">
                            <option value="">Any date</option>
                            @foreach($filterOptions['ihods'] as $ihod)
                                <option value="{{ $ihod }}" @selected($selectedIhodTo === $ihod)>{{ $ihod }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- 2 + 2 + 4 + 1 + 3 = 12. IHOD went from one column to two, and rows per
                         page took one more, so the keyword field gives up two to keep the
                         row on one line. --}}
                    <div class="col-md-4 booking-search-field">
                        <label class="form-label">Quick keyword search</label>
                        <i class="bi bi-search booking-search-icon" aria-hidden="true"></i>
                        <input type="text" name="keyword" value="{{ $selectedKeyword }}" class="form-control booking-auto-filter-text" placeholder="Search buyer / season / vendor / item / style / PO keyword...">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label" for="perPage">Rows</label>
                        <select id="perPage" name="per_page" class="form-select booking-auto-filter" autocomplete="off">
                            @foreach([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) request('per_page', 25) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-grid d-md-flex gap-2">
                        <button type="submit" class="btn booking-primary-btn flex-fill" title="Apply filter"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
                        <button type="button" class="btn btn-soft-reset" id="bookingFilterReset"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <section id="pending-generated-po" class="booking-tab-panel" data-booking-panel="pending-generated-po">
    <div class="card booking-card mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0 fw-bold booking-section-title"><span class="booking-title-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span>Pending PO Generate Orders</h6>
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
                <button type="button" class="btn booking-primary-btn btn-sm" id="bulkPreviewBtn"><i class="bi bi-eye me-1" aria-hidden="true"></i>Preview Selected</button>
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
                            <th class="po-num">Qty</th>
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
    </section>

    <div class="modal fade booking-preview-modal" id="bookingPreviewPanel" tabindex="-1" aria-labelledby="bookingPreviewTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header bg-white border-bottom">
                    <div class="booking-preview-title-wrap">
                        <h6 class="modal-title fw-bold mb-0" id="bookingPreviewTitle"><i class="bi bi-file-earmark-richtext me-2 text-primary" aria-hidden="true"></i>Booking Format Preview</h6>
                        <small class="text-muted d-block">Review the booking format carefully, then generate PO.</small>
                        <div class="booking-preview-steps" aria-label="Booking steps">
                            <span class="bp-step is-active"><i class="bi bi-eye" aria-hidden="true"></i>Preview</span>
                            <i class="bi bi-arrow-right bp-step-arrow" aria-hidden="true"></i>
                            <span class="bp-step"><i class="bi bi-check2-circle" aria-hidden="true"></i>Generate PO</span>
                        </div>
                    </div>
                    <div class="booking-preview-header-tools no-print" aria-label="Booking preview zoom controls">
                        <button type="button" class="btn booking-preview-zoom-btn" id="bookingPreviewZoomOut" aria-label="Zoom out"><i class="bi bi-zoom-out me-1" aria-hidden="true"></i>Zoom Out</button>
                        {{-- Matches the default in the script below; overwritten on first paint. --}}
                        <span class="booking-preview-zoom-value" id="bookingPreviewZoomValue">135%</span>
                        <button type="button" class="btn booking-preview-zoom-btn" id="bookingPreviewZoomIn" aria-label="Zoom in"><i class="bi bi-zoom-in me-1" aria-hidden="true"></i>Zoom In</button>
                        <button type="button" class="btn booking-preview-zoom-btn" id="bookingPreviewZoomReset" aria-label="Reset zoom"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</button>
                    </div>
                    <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body booking-preview-inner p-2" id="bookingPreviewContent"></div>

                <div class="booking-confirm-overlay" id="bookingGenerateConfirm" hidden>
                    <div class="booking-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="bookingConfirmTitle" aria-describedby="bookingConfirmMessage">
                        <div class="booking-confirm-icon"><i class="bi bi-patch-question" aria-hidden="true"></i></div>
                        <h5 class="booking-confirm-title" id="bookingConfirmTitle">Generate PO?</h5>
                        <p class="booking-confirm-message" id="bookingConfirmMessage">After generating PO, the order will be completed automatically. Please confirm that all booking details are correct.</p>
                        <div class="booking-confirm-actions">
                            <button type="button" class="btn btn-soft-reset" id="bookingConfirmCancel">Cancel</button>
                            <button type="button" class="btn booking-primary-btn px-4" id="bookingConfirmAccept">Yes, Generate PO</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section id="recent-generated-po" class="booking-tab-panel is-hidden" data-booking-panel="recent-generated-po">
    <div class="card booking-card generated-po-card">
        <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="mb-0 fw-bold booking-section-title"><span class="booking-title-icon"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></span>Generated PO List</h6>
                <small class="text-muted">Supply-chain users can only view, print and download generated PO. Admin controls re-generate and change history separately.</small>
            </div>
            <span class="badge rounded-pill text-bg-primary px-3 py-2">{{ $generatedPoCount }} PO</span>
        </div>
        <div class="card-body border-bottom">
            <div class="row g-3 align-items-end">
                <div class="col-lg-8">
                    <label class="form-label mb-1 fw-bold small text-muted">Search generated PO</label>
                    <div class="po-control-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="text" id="poControlSearch" class="form-control" placeholder="Search PO no, buyer, season, vendor, style or item...">
                    </div>
                </div>
                <div class="col-lg-4 d-grid d-lg-flex justify-content-lg-end">
                    <button type="button" class="btn btn-soft-reset" id="poControlSearchClear"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Clear Search</button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 booking-table generated-po-table" id="poControlTable">
                    <thead>
                        <tr>
                            <th style="width:170px;">PO No</th>
                            <th>Buyer / Season</th>
                            <th>Vendor</th>
                            <th>Style / Item</th>
                            <th class="po-num">Qty</th>
                            <th>Generated</th>
                            {{-- Sized for View / Print / PDF / Excel on one line
                                 (~259px of buttons plus padding). Below this the
                                 fourth button wraps and the row grows taller than
                                 its neighbours. --}}
                            <th class="text-center" style="width:275px;min-width:275px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($generatedPos as $po)
                            @php
                                $poData = $po->booking_data ?: [];
                                $revisionNo = (int) ($po->revision_no ?? 0);
                                $statusTokens = collect(['all', 'generated'])
                                    ->when(($po->status ?? '') === 'completed', fn ($items) => $items->push('completed'))
                                    ->implode(' ');
                                $searchText = strtolower(implode(' ', array_filter([
                                    $po->po_no,
                                    $po->buyer_name,
                                    $po->season_name,
                                    $po->vendor_name,
                                    $po->style_name,
                                    $po->item_name,
                                    $po->qty,
                                    $po->status,
                                ])));
                            @endphp
                            <tr class="po-control-row" data-status="{{ $statusTokens }}" data-search="{{ $searchText }}">
                                <td>
                                    <span class="po-pill"><i class="bi bi-upc-scan" aria-hidden="true"></i>{{ $po->po_no }}</span>
                                    @if($revisionNo > 0)
                                        <span class="revision-mini-pill mt-1"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>R-{{ $revisionNo }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $po->buyer_name ?: '-' }}</div>
                                    <div class="small text-muted">Season: {{ $po->season_name ?: '-' }}</div>
                                </td>
                                <td>{{ $po->vendor_name ?: ($poData['to'] ?? '-') }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $po->style_name ?: ($poData['order_style_no'] ?? '-') }}</div>
                                    <div class="small text-muted">{{ $po->item_name ?: ($poData['item_type'] ?? '-') }}</div>
                                </td>
                                <td class="po-num fw-bold">{{ $po->qty !== null && $po->qty !== '' ? $po->qty : '-' }}</td>
                                <td>
                                    <span class="po-status-pill po-status-generated"><i class="bi bi-check2-circle" aria-hidden="true"></i>Generated</span>
                                    <div class="small text-muted mt-1">{{ optional($po->generated_at)->format('d M Y, h:i A') ?: '-' }}</div>
                                </td>
                                <td class="text-center">
                                    <div class="generated-po-actions">
                                        <a href="{{ route('supply_chain.bookings.show', $po) }}" class="generated-po-action" title="View"><i class="bi bi-eye" aria-hidden="true"></i><span>View</span></a>
                                        <a href="{{ route('supply_chain.bookings.print', $po) }}" target="_blank" class="generated-po-action" title="Print"><i class="bi bi-printer" aria-hidden="true"></i><span>Print</span></a>
                                        <a href="{{ route('supply_chain.bookings.download', $po) }}" target="_blank" class="generated-po-action" title="Download PDF"><i class="bi bi-filetype-pdf" aria-hidden="true"></i><span>PDF</span></a>
                                        <a href="{{ route('supply_chain.bookings.download_excel', $po) }}" target="_blank" class="generated-po-action" title="Download Excel"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i><span>Excel</span></a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="po-control-empty">
                                        <div>
                                            <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:78px;height:78px;"><i class="bi bi-inbox fs-1 text-slate-300" aria-hidden="true"></i></span>
                                            <div class="fw-bold text-slate-900">No PO generated yet</div>
                                            <div class="small text-muted">Generated PO will appear here for view, print and download.</div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        <tr id="poControlNoMatchRow" style="display:none;">
                            <td colspan="7">
                                <div class="po-control-empty">
                                    <div>
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:78px;height:78px;"><i class="bi bi-search fs-1 text-slate-300" aria-hidden="true"></i></span>
                                        <div class="fw-bold text-slate-900">No matching PO found</div>
                                        <div class="small text-muted">Try another PO number, buyer, vendor, style or item.</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </section>
</div></div>
@endsection

@section('scripts')
@include('supply-chain.bookings.partials._ex-mill-autofill')
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
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
    const bookingTabButtons = document.querySelectorAll('[data-booking-tab]');
    const bookingTabPanels = document.querySelectorAll('[data-booking-panel]');

    function activateBookingTab(tabId, pushHash = false) {
        if (!tabId) tabId = 'pending-generated-po';

        bookingTabButtons.forEach(function (button) {
            button.classList.toggle('is-active', button.dataset.bookingTab === tabId);
        });

        bookingTabPanels.forEach(function (panel) {
            const match = panel.dataset.bookingPanel === tabId;
            panel.classList.toggle('is-hidden', !match);
        });

        if (pushHash) {
            history.replaceState(null, '', '#' + tabId);
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        }
    }

    bookingTabButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            activateBookingTab(this.dataset.bookingTab, true);
        });
    });

    const initialBookingTab = window.location.hash === '#recent-generated-po' ? 'recent-generated-po' : 'pending-generated-po';
    activateBookingTab(initialBookingTab);

    window.addEventListener('hashchange', function () {
        const hash = window.location.hash.replace('#', '');
        if (hash === 'pending-generated-po' || hash === 'recent-generated-po') {
            activateBookingTab(hash);
        }
    });


    const poControlSearch = document.getElementById('poControlSearch');
    const poControlFilter = document.getElementById('poControlFilter');
    const poControlRows = Array.from(document.querySelectorAll('#poControlTable .po-control-row'));
    const poControlNoMatchRow = document.getElementById('poControlNoMatchRow');

    function applyPoControlFilters() {
        const keyword = (poControlSearch?.value || '').toLowerCase().trim();
        const status = (poControlFilter?.value || 'all').toLowerCase();
        let visible = 0;

        poControlRows.forEach(function (row) {
            const searchText = row.dataset.search || '';
            const statusText = row.dataset.status || 'all';
            const matchesSearch = !keyword || searchText.includes(keyword);
            const matchesStatus = status === 'all' || statusText.split(' ').includes(status);
            const shouldShow = matchesSearch && matchesStatus;
            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) visible++;
        });

        if (poControlNoMatchRow) {
            poControlNoMatchRow.style.display = visible === 0 && poControlRows.length > 0 ? '' : 'none';
        }
    }

    poControlSearch?.addEventListener('input', applyPoControlFilters);
    poControlFilter?.addEventListener('change', applyPoControlFilters);
    document.getElementById('poControlSearchClear')?.addEventListener('click', function () {
        if (poControlSearch) poControlSearch.value = '';
        if (poControlFilter) poControlFilter.value = 'all';
        applyPoControlFilters();
    });
    applyPoControlFilters();
    const previewPanel = document.getElementById('bookingPreviewPanel');
    const previewContent = document.getElementById('bookingPreviewContent');
    const zoomOutBtn = document.getElementById('bookingPreviewZoomOut');
    const zoomInBtn = document.getElementById('bookingPreviewZoomIn');
    const zoomResetBtn = document.getElementById('bookingPreviewZoomReset');
    const zoomValue = document.getElementById('bookingPreviewZoomValue');
    const confirmOverlay = document.getElementById('bookingGenerateConfirm');
    const confirmTitle = document.getElementById('bookingConfirmTitle');
    const confirmMessage = document.getElementById('bookingConfirmMessage');
    const confirmAccept = document.getElementById('bookingConfirmAccept');
    const confirmCancel = document.getElementById('bookingConfirmCancel');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const dataUrl = @json(route('supply_chain.bookings.data'));
    const indexUrl = @json(route('supply_chain.bookings.index'));
    const bulkPreviewUrl = @json(route('supply_chain.bookings.bulk_preview'));
    const bulkGenerateUrl = @json(route('supply_chain.bookings.bulk_generate'));
    const batchPreviewUrl = @json(route('supply_chain.bookings.batch_preview'));

    if (!form || !rowsBody) return;

    let filterTimer = null;
    // Starts at the scale users were manually reaching for anyway, and matches
    // the CSS default in the preview partial — the two must agree, or the first
    // paint and the label above it disagree by 35%. Reset returns here, not to
    // 1: 100% is the unreadable paper-size rendering, not a useful home.
    // The ceiling moved up with the floor so there is still room above the
    // default for anyone who wants it larger.
    const bookingPreviewZoomDefault = 1.35;
    let bookingPreviewZoom = bookingPreviewZoomDefault;
    const bookingPreviewZoomMin = 0.7;
    const bookingPreviewZoomMax = 1.8;
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

    function askGenerateConfirm(options) {
        const settings = options || {};
        if (!confirmOverlay) {
            return Promise.resolve(window.confirm(settings.message || 'Generate this PO?'));
        }

        if (confirmTitle) confirmTitle.textContent = settings.title || 'Generate PO?';
        if (confirmMessage) confirmMessage.textContent = settings.message || '';
        if (confirmAccept) confirmAccept.textContent = settings.confirmLabel || 'Yes, Generate PO';

        confirmOverlay.hidden = false;

        return new Promise(function (resolve) {
            function cleanup(result) {
                confirmOverlay.hidden = true;
                confirmAccept?.removeEventListener('click', onAccept);
                confirmCancel?.removeEventListener('click', onCancel);
                confirmOverlay.removeEventListener('click', onBackdrop);
                resolve(result);
            }
            function onAccept() { cleanup(true); }
            function onCancel() { cleanup(false); }
            function onBackdrop(event) { if (event.target === confirmOverlay) cleanup(false); }

            confirmAccept?.addEventListener('click', onAccept);
            confirmCancel?.addEventListener('click', onCancel);
            confirmOverlay.addEventListener('click', onBackdrop);
            confirmAccept?.focus();
        });
    }

    function setGenerateLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn.classList.add('is-loading');
            const label = btn.dataset.loadingLabel || 'Generating PO...';
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + label;
        } else {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            const label = btn.getAttribute('aria-label') || 'Generate PO';
            const icon = btn.dataset.regenerate === '1' ? 'bi-arrow-repeat' : 'bi-check2-circle';
            btn.innerHTML = '<span class="bf-btn-generate-content"><i class="bi ' + icon + ' me-1"></i>' + label + '</span>';
        }
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
        let data = {};
        try {
            data = await response.json();
        } catch (parseError) {
            data = {};
        }
        if (!response.ok || data.success === false) {
            const error = new Error(data.message || 'Request failed.');
            error.friendly = !!data.message;
            throw error;
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
        // Back to the readable default, not to 1.
        applyPreviewZoom(bookingPreviewZoomDefault);
        previewContent?.scrollTo({ top: 0, left: 0, behavior: 'smooth' });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        loadRows();
    });

    // Turn each filter dropdown into a searchable (typeable) dropdown. Falls back to the
    // native <select> if the Tom Select library fails to load — filter logic is unchanged.
    // `select.` matters: .booking-auto-filter is also on the IHOD date inputs so
    // they reload the table on change, and running TomSelect over an
    // <input type="date"> replaces the native date picker with a tag widget.
    if (window.TomSelect) {
        document.querySelectorAll('select.booking-auto-filter').forEach(function (select) {
            const placeholder = (select.options[0] && select.options[0].value === '')
                ? select.options[0].text
                : 'Search...';

            new TomSelect(select, {
                allowEmptyOption: true,
                placeholder: placeholder,
                searchField: ['text'],
                render: {
                    no_results: function () {
                        return '<div class="no-results">No match found</div>';
                    }
                }
            });
        });
    }

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
            if (field.tomselect) {
                field.tomselect.clear(true); // silent: avoid per-field reload
            } else {
                field.value = '';
            }
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
            const data = await postJson(batchPreviewUrl, { rows: ids });
            const readyMessage = ids.length > 1
                ? 'Selected orders combined into one booking preview.'
                : 'Booking preview ready.';
            showAlert(data.message || readyMessage);
            showPreview(data.preview_html);
        } catch (error) {
            showAlert(error.friendly && error.message ? error.message : 'Unable to open preview. Please check selected orders and try again.', 'danger');
        } finally {
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
                ? '<i class="bi bi-eye me-1" aria-hidden="true"></i>Hide Edit'
                : '<i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit Preview';
            return;
        }

        // The chips are positional, so they are rewritten after every add and
        // remove rather than baked in once — otherwise deleting instruction 2
        // leaves the list reading 1, 3, 4.
        function renumberNotes(list) {
            list.querySelectorAll('.booking-preview-note-row').forEach(function (row, i) {
                const chip = row.querySelector('.bp-note-index');
                if (chip) chip.textContent = i + 1;
            });
        }

        // A new line carries its own "save as suggestion" tick — the one thing
        // the removed separate "New Instruction" box could do that an inline row
        // could not. The name index comes from a counter rather than the row
        // position, so removing a row never re-points a tick at another line.
        function buildNoteRow(list) {
            const i = Number(list.dataset.nextIndex || 0);
            list.dataset.nextIndex = i + 1;
            const row = document.createElement('div');
            row.className = 'booking-preview-note-row';
            // Must match the server-rendered row in preview.blade.php:
            // numbered chip, textarea, quiet Remove pill.
            row.innerHTML =
                '<span class="bp-note-index" aria-hidden="true"></span>'
                + '<div class="bp-note-body">'
                + '<textarea name="notes[' + i + ']" rows="2" class="form-control" placeholder="Instruction text"></textarea>'
                + '<label class="bp-note-save"><input type="checkbox" class="form-check-input" name="save_suggestion[' + i + ']" value="1"> Save as suggestion for future bookings</label>'
                + '</div>'
                + '<button type="button" class="bp-remove booking-preview-remove-note"><i class="bi bi-x-lg" aria-hidden="true"></i>Remove</button>';
            return row;
        }

        const addNoteBtn = event.target.closest('.booking-preview-add-note');
        if (addNoteBtn) {
            const panel = addNoteBtn.closest('.booking-preview-edit-panel');
            const list = panel?.querySelector('.booking-preview-notes-list');
            if (list) {
                list.appendChild(buildNoteRow(list));
                renumberNotes(list);
            }
            return;
        }

        const removeNoteBtn = event.target.closest('.booking-preview-remove-note');
        if (removeNoteBtn) {
            const list = removeNoteBtn.closest('.booking-preview-notes-list');
            const row = removeNoteBtn.closest('.booking-preview-note-row');
            if (row && list && list.querySelectorAll('.booking-preview-note-row').length > 1) {
                row.remove();
                renumberNotes(list);
            } else if (row) {
                const textarea = row.querySelector('textarea');
                if (textarea) textarea.value = '';
                const save = row.querySelector('.bp-note-save input');
                if (save) save.checked = false;
            }
            return;
        }

        const generateBtn = event.target.closest('.preview-generate-po-btn');
        if (!generateBtn || generateBtn.classList.contains('is-loading')) return;

        const editForm = generateBtn.closest('.booking-preview-edit-form');
        const editPanel = editForm?.querySelector('.booking-preview-edit-panel');
        const isRegenerate = generateBtn.dataset.regenerate === '1';
        const isBatch = generateBtn.dataset.batch === '1';
        const payload = editForm && (isRegenerate || (editPanel && !editPanel.classList.contains('d-none'))) ? formDataToObject(editForm) : {};

        if (isBatch) {
            payload.rows = (generateBtn.dataset.rows || '')
                .split(',')
                .map(value => parseInt(value, 10))
                .filter(value => !Number.isNaN(value));

            if (!payload.rows.length) {
                showAlert('Please select at least one order.', 'warning');
                return;
            }
        }

        let confirmOptions;
        if (isBatch) {
            confirmOptions = {
                title: 'Generate PO for Selected Orders?',
                message: 'You have selected multiple orders. One PO number will be generated for all selected orders. Do you want to continue?',
                confirmLabel: 'Yes, Generate PO',
            };
        } else if (isRegenerate) {
            confirmOptions = {
                title: 'Re-generate PO?',
                message: 'Re-generating keeps the same PO number and increases the revision count. Please confirm that the latest booking details are correct.',
                confirmLabel: 'Yes, Re-generate PO',
            };
        } else {
            confirmOptions = {
                title: 'Generate PO?',
                message: 'Do you want to generate PO for this order? The order will be completed automatically after PO generation.',
                confirmLabel: 'Yes, Generate PO',
            };
        }

        const confirmed = await askGenerateConfirm(confirmOptions);
        if (!confirmed) return;

        setGenerateLoading(generateBtn, true);
        showLoading(true);
        try {
            const data = await postJson(generateBtn.dataset.url, payload);
            showAlert(data.message || 'PO generated successfully.');
            if (data.preview_html) {
                showPreview(data.preview_html);
            }
            if (isRegenerate) {
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                await loadRows();
            }
        } catch (error) {
            showAlert(error.friendly && error.message ? error.message : 'Unable to generate PO. Please try again or contact admin.', 'danger');
            setGenerateLoading(generateBtn, false);
        } finally {
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
