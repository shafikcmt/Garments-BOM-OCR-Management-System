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

    /* --- Wizard shell ----------------------------------------------------- */
    [x-cloak] { display: none !important; }

    /* Panel chrome. The body carries a soft canvas so the white section cards
       inside it read as raised rather than as one flat wall of fields. */
    #biPanel .offcanvas-header { padding: 1.05rem 1.5rem; }
    #biPanel .offcanvas-title { font-size: 1.0625rem; font-weight: 700; letter-spacing: -.01em; color: var(--gx-primary, #0F172A); }
    #biPanel .offcanvas-body { padding: 1.25rem 1.5rem 0; background: #F8FAFC; }
    #biPanel .form-label { font-size: .78rem; font-weight: 600; color: #334155; margin-bottom: .3rem; }
    #biPanel .form-control, #biPanel .form-select { border-radius: 10px; font-size: .875rem; }
    #biPanel .form-control:focus, #biPanel .form-select:focus {
        border-color: var(--gx-secondary-600, #2563EB); box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
    }
    #biPanel .form-text { font-size: .72rem; color: #94A3B8; }

    /* Section card: one visual group per concern (search, indent, quantities). */
    .bi-card {
        background: #fff; border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 14px;
        padding: 1rem 1.1rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }
    .bi-sec { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: .9rem; }
    .bi-sec-title { display: flex; align-items: center; gap: .45rem; font-size: .9375rem; font-weight: 700; letter-spacing: -.01em; color: var(--gx-primary, #0F172A); margin: 0; }
    .bi-sec-title i { color: var(--gx-secondary-600, #2563EB); font-size: .95rem; }
    .bi-sec-sub { font-size: .78rem; line-height: 1.45; color: var(--gx-text-muted, #64748B); margin: .2rem 0 0; }

    /* Stepper: upcoming = outlined grey, current = ringed blue, done = solid
       blue with a check. The connector fills as the user advances. */
    .bi-wz {
        display: flex; align-items: flex-start; list-style: none; margin: 0 0 1rem; padding: .95rem .4rem;
        background: #fff; border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 14px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }
    .bi-wz-step { flex: 1 1 0; min-width: 0; position: relative; }
    .bi-wz-step + .bi-wz-step::before {
        content: ''; position: absolute; top: 16px; right: 50%; left: -50%; height: 2px;
        background: var(--bs-border-color, #E2E8F0); border-radius: 2px; transition: background-color .2s ease-in-out;
    }
    .bi-wz-step.is-done::before, .bi-wz-step.is-current::before { background: var(--gx-secondary-600, #2563EB); }
    .bi-wz-btn {
        position: relative; z-index: 1; width: 100%; display: flex; flex-direction: column; align-items: center;
        gap: .45rem; border: 0; background: transparent; padding: 0 .35rem; text-align: center;
    }
    .bi-wz-dot {
        width: 32px; height: 32px; flex: none; border-radius: 50%; display: inline-flex; align-items: center;
        justify-content: center; font-size: .8125rem; font-weight: 700; background: #fff; color: #94A3B8;
        border: 2px solid var(--bs-border-color, #E2E8F0); box-shadow: 0 0 0 4px #fff;
        transition: background-color .2s ease-in-out, color .2s ease-in-out, border-color .2s ease-in-out, box-shadow .2s ease-in-out;
    }
    .bi-wz-btn:hover .bi-wz-dot { border-color: #CBD5E1; }
    .bi-wz-step.is-current .bi-wz-dot {
        border-color: var(--gx-secondary-600, #2563EB); color: var(--gx-secondary-700, #1D4ED8);
        box-shadow: 0 0 0 4px #fff, 0 0 0 7px rgba(37, 99, 235, .14);
    }
    .bi-wz-step.is-done .bi-wz-dot { background: var(--gx-secondary-600, #2563EB); border-color: var(--gx-secondary-600, #2563EB); color: #fff; }
    .bi-wz-label { display: block; min-width: 0; width: 100%; }
    .bi-wz-caption { display: block; font-size: .625rem; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #94A3B8; }
    .bi-wz-title { display: block; font-size: .78rem; font-weight: 600; color: var(--gx-text-muted, #64748B); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bi-wz-step.is-done .bi-wz-title { color: #334155; }
    .bi-wz-step.is-current .bi-wz-caption { color: var(--gx-secondary-600, #2563EB); }
    .bi-wz-step.is-current .bi-wz-title { color: var(--gx-primary, #0F172A); font-weight: 700; }

    /* Draft notice: icon tile + copy + separated actions. */
    .bi-notice {
        display: flex; align-items: flex-start; gap: .75rem; flex-wrap: wrap; background: #fff;
        border: 1px solid var(--gx-secondary-border, #BFDBFE); border-radius: 14px; padding: .85rem .95rem;
        margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
    }
    .bi-notice-icon {
        flex: none; width: 34px; height: 34px; border-radius: 10px; background: #EFF6FF;
        color: var(--gx-secondary-600, #2563EB); display: inline-flex; align-items: center; justify-content: center; font-size: 1rem;
    }
    .bi-notice-body { flex: 1 1 11rem; min-width: 0; }
    .bi-notice-title { font-size: .8125rem; font-weight: 700; color: var(--gx-primary, #0F172A); }
    .bi-notice-text { font-size: .75rem; line-height: 1.45; color: var(--gx-text-muted, #64748B); margin: .1rem 0 0; }
    .bi-notice-actions { display: flex; align-items: center; gap: .4rem; margin-left: auto; flex: none; }
    .bi-btn-xs { border-radius: 9px; font-weight: 600; font-size: .78rem; padding: .35rem .8rem; }

    /* Compact labelled link-button for the two secondary actions that sit inside
       other content — "Change" on the PO chip, "Remove" on an item card. Small
       and quiet so it does not compete with the card's own data, but never a
       bare icon: the label is what makes the action legible without a hover. */
    .bi-btn-inline {
        display: inline-flex; align-items: center; font-size: .75rem; font-weight: 600;
        line-height: 1.2; white-space: nowrap;
    }
    .bi-btn-inline i { font-size: .7rem; }

    /* Empty state for the item list. */
    .bi-empty { border: 1px dashed #CBD5E1; border-radius: 12px; background: #F8FAFC; padding: 1.35rem 1rem; text-align: center; }
    .bi-empty-icon {
        width: 44px; height: 44px; border-radius: 12px; background: #fff; border: 1px solid var(--bs-border-color, #E2E8F0);
        color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; margin-bottom: .55rem;
    }
    .bi-empty-title { font-size: .8125rem; font-weight: 600; color: #334155; }
    .bi-empty-text { font-size: .75rem; color: #94A3B8; margin: .15rem 0 0; }

    /* Remarks: counter parks inside the field, bottom-right. */
    .bi-remarks { position: relative; }
    .bi-remarks textarea.form-control { border-radius: 12px; padding: .7rem .85rem 1.9rem; resize: vertical; }
    .bi-remarks textarea.form-control::placeholder { color: #94A3B8; }
    .bi-remarks-count {
        position: absolute; right: .55rem; bottom: .45rem; pointer-events: none; font-size: .6875rem;
        font-weight: 600; color: #94A3B8; background: #fff; padding: 0 .25rem; border-radius: 6px;
    }
    .bi-remarks-count.is-warn { color: #B45309; }

    /* Fade + slide between steps. */
    .bi-step-enter { transition: opacity .15s ease-in-out, transform .15s ease-in-out; }
    .bi-step-start { opacity: 0; transform: translateX(12px); }
    .bi-step-end { opacity: 1; transform: none; }

    /* Sticky action bar: full-bleed inside the panel, secondary actions on the
       left of the group, one dominant primary on the right. */
    .bi-wizard-bar {
        position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
        background: #fff; border-top: 1px solid var(--bs-border-color, #E2E8F0); margin: .25rem -1.5rem 0;
        padding: .85rem 1.5rem calc(.85rem + env(safe-area-inset-bottom, 0px));
        box-shadow: 0 -8px 20px -14px rgba(15, 23, 42, .5);
    }
    .bi-bar-meta { min-width: 0; margin-right: auto; }
    .bi-bar-step { font-size: .75rem; font-weight: 600; color: #94A3B8; }
    .bi-bar-chip {
        display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; font-weight: 600;
        color: var(--gx-secondary-700, #1D4ED8); background: #EFF6FF; border: 1px solid var(--gx-secondary-border, #BFDBFE);
        border-radius: 999px; padding: .2rem .65rem;
    }
    .bi-bar-hint { display: flex; align-items: center; gap: .3rem; font-size: .7rem; color: #94A3B8; margin: .3rem 0 0; }
    .bi-bar-actions { display: flex; align-items: center; gap: .5rem; margin-left: auto; }
    .bi-bar-actions .btn { border-radius: 10px; font-weight: 600; font-size: .8125rem; padding: .5rem .95rem; }
    .bi-bar-actions .btn-primary { padding-inline: 1.35rem; box-shadow: 0 8px 16px -8px rgba(37, 99, 235, .65); }

    @media (max-width: 575.98px) {
        #biPanel .offcanvas-body { padding-inline: 1rem; }
        .bi-wizard-bar { margin-inline: -1rem; padding-inline: 1rem; }
        .bi-bar-actions { width: 100%; }
        .bi-bar-actions .btn-primary { flex: 1 1 auto; }
        .bi-wz-caption { display: none; }
    }

    @keyframes biShake { 0%,100% { transform: none; } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
    .bi-shake { animation: biShake .25s ease-in-out; }

    /* Toasts sit above the offcanvas (z 1045) and its backdrop. */
    .bi-toasts { position: fixed; top: 1rem; right: 1rem; z-index: 1090; display: flex; flex-direction: column; gap: .5rem; max-width: min(360px, calc(100vw - 2rem)); }
    .bi-toast { display: flex; align-items: center; gap: .6rem; background: #fff; border: 1px solid; border-left-width: 4px; border-radius: 10px; padding: .6rem .75rem; font-size: .8125rem; }

    @media (prefers-reduced-motion: reduce) {
        .bi-step-enter, .bi-wz-dot, .bi-wz-step + .bi-wz-step::before { transition: none; }
        .bi-shake { animation: none; }
    }

    /* A wide slide-over, not a narrow drawer. Each item row carries a long
       material name plus four quantity fields, which the 400px default crushed.

       Set through Bootstrap's own custom property, not a bare width. Bootstrap
       5.3 ships the rule as the compound selector `.offcanvas.offcanvas-end`
       (specificity 0,2,0), so a plain `.bi-offcanvas { width }` (0,1,0) loses
       no matter how late it is declared — that is exactly why the earlier
       override never rendered. Redefining the variable the winning rule already
       reads sidesteps the fight entirely, with no !important.

       clamp() keeps it readable on a laptop and stops it swallowing an ultra-
       wide screen; 1120px lands next to the modal-xl used by the item picker,
       so the two surfaces read as the same size of thing. */
    #biPanel.bi-offcanvas { --bs-offcanvas-width: clamp(720px, 62vw, 1120px); }

    /* Below lg the slide-over stops being a side panel and takes the screen —
       a 720px drawer on a tablet leaves no page behind it to slide over. */
    @media (max-width: 991.98px) {
        #biPanel.bi-offcanvas { --bs-offcanvas-width: 100%; }
    }

    .bi-search-spin { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); }

    /* Spinner and clear share one slot inside the search field, so neither
       changes the input-group's width when it appears. */
    .bi-pick-status { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); z-index: 5; display: flex; align-items: center; }
    /* Type selector rides inside the search group; it sizes to its content so
       the box the user types into keeps the remaining width. */
    .bi-filter-type { flex: 0 0 auto; width: auto; border-right: 0; background-color: #F8FAFC; font-weight: 500; }
    #biSearchWrap .form-control { padding-right: 2.25rem; }
    #biSearchWrap .btn-close { font-size: .7rem; opacity: .5; }
    #biSearchWrap .btn-close:hover { opacity: 1; }

    /* Item picker modal — mirrors Receiving's stepper and pick table. */
    .bi-steps { display: flex; align-items: center; gap: .75rem; list-style: none; padding: 0; margin: 0; }
    .bi-step { display: flex; align-items: center; gap: .5rem; color: #94A3B8; font-size: .875rem; }
    .bi-step + .bi-step::before { content: ''; width: 2.5rem; height: 1px; background: var(--bs-border-color, #E2E8F0); margin-right: .25rem; }
    .bi-step-dot {
        width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
        font-size: .8125rem; font-weight: 700; background: #fff; color: #94A3B8; border: 2px solid var(--bs-border-color, #E2E8F0);
    }
    .bi-step.is-current { color: var(--gx-primary, #0F172A); font-weight: 600; }
    .bi-step.is-current .bi-step-dot {
        border-color: var(--gx-secondary-600, #2563EB); color: var(--gx-secondary-700, #1D4ED8);
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .14);
    }
    .bi-step.is-done { color: #334155; }
    .bi-step.is-done .bi-step-dot { background: var(--gx-secondary-600, #2563EB); border-color: var(--gx-secondary-600, #2563EB); color: #fff; }

    /* modal-xl only reaches 1140px at >=1200px viewport, and drops to 800/500px
       below that — which read as "squeezed" beside the 620px slide-in panel. An
       explicit fluid width keeps the picker wide at every breakpoint. */
    #biItemsModal .modal-dialog { max-width: min(1180px, calc(100vw - 2rem)); }
    #biItemsModal .modal-header, #biItemsModal .modal-body { padding: 1.25rem 1.5rem; }
    #biItemsModal .modal-footer { padding: 1rem 1.5rem; }

    .bi-pick { max-height: 52vh; border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px; }
    /* Identity columns read on one line; only Material is allowed to wrap. */
    .bi-pick td, .bi-pick th { white-space: nowrap; }
    .bi-pick td:nth-child(2) { white-space: normal; min-width: 220px; }

    /* Out of stock: visible for reference, never selectable. */
    .bi-pick tbody tr.is-empty { cursor: not-allowed; background: transparent; }
    .bi-pick tbody tr.is-empty:hover { background: transparent; }
    .bi-pick tbody tr.is-empty .bi-cell-primary,
    .bi-pick tbody tr.is-empty .bi-cell-sub { color: #94A3B8; }
    .bi-pick thead th {
        background: #F1F5F9; font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em;
        font-weight: 600; color: var(--gx-text-muted, #64748B); border-bottom: 1px solid var(--bs-border-color, #E2E8F0); white-space: nowrap;
    }
    /* Whole row is the hit target, so the checkbox is an affordance not the only way in. */
    .bi-pick tbody tr.bi-row { cursor: pointer; transition: background-color .15s ease; }
    .bi-pick tbody tr.bi-row:hover { background: #F1F5F9; }
    .bi-pick tbody tr.bi-row.is-checked { background: var(--gx-secondary-bg, #DBEAFE); }
    /* Already added: visible for reference, but not selectable again. */
    .bi-pick tbody tr.is-added { cursor: default; color: #94A3B8; }
    .bi-pick tbody tr.is-added:hover { background: transparent; }
    .bi-group-row td { background: #F1F5F9; font-weight: 600; font-size: .8125rem; color: var(--gx-text-muted, #64748B); border-top: 1px solid var(--bs-border-color, #E2E8F0); }
    .bi-cell-primary { color: var(--gx-primary, #0F172A); line-height: 1.35; }
    .bi-cell-sub { font-size: .75rem; color: var(--gx-text-muted, #64748B); line-height: 1.35; }
    .bi-pick tbody tr.is-added .bi-cell-primary { color: #94A3B8; }

    .bi-modal-footer { background: #F1F5F9; border-top: 1px solid var(--bs-border-color, #E2E8F0); gap: .5rem; }
    .bi-selcount { display: inline-flex; align-items: center; gap: .45rem; font-size: .8125rem; color: var(--gx-text-muted, #64748B); }
    .bi-selcount-badge { min-width: 1.5rem; padding: .1rem .4rem; border-radius: 6px; background: var(--bs-border-color, #E2E8F0); color: var(--gx-primary, #0F172A); font-weight: 600; text-align: center; }
    .bi-selcount.is-active .bi-selcount-badge { background: var(--gx-secondary-600, #2563EB); color: #fff; }

    /* One selected item = one card carrying its identity and its four fields.
       Over-limit is an error, not a warning: the save is blocked until fixed. */
    .bi-item-card {
        border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px; padding: .8rem;
        background: #fff; transition: border-color .15s ease, box-shadow .15s ease;
    }
    .bi-item-card:hover { border-color: #CBD5E1; box-shadow: 0 2px 8px -4px rgba(15, 23, 42, .18); }
    .bi-item-card.is-over { border-color: var(--gx-danger, #EF4444); background: #FEF2F2; }
    .bi-item-card.is-over .bi-qty { border-color: var(--gx-danger, #EF4444); }
    .bi-item-error { font-size: .78rem; color: var(--gx-danger-700, #B91C1C); font-weight: 500; }
    .bi-item-head { font-size: .8125rem; font-weight: 600; color: var(--gx-primary, #0F172A); line-height: 1.35; overflow-wrap: anywhere; }
    .bi-item-meta { font-size: .75rem; color: var(--gx-text-muted, #64748B); line-height: 1.35; overflow-wrap: anywhere; }
    /* The identity column must be allowed to shrink, or a long material name
       pushes the badges and the remove button off the card. */
    .bi-item-card .min-w-0 { min-width: 0; }

    /* Wide viewport: the panel is roomy enough for the four quantity fields to
       sit on one line, and for the cards to breathe. */
    @media (min-width: 1200px) {
        .bi-card { padding: 1.15rem 1.35rem; }
        .bi-item-card { padding: .95rem 1.05rem; }
        .bi-item-head { font-size: .875rem; }
        .bi-qty-card { padding: .55rem .7rem; }
        .bi-qty-grid .bi-qty-card .form-label { font-size: .75rem; }
    }

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
                @if($hasBookingPos && $canCreate)
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
@if($hasBookingPos && ($canCreate || $canEdit))
<div class="offcanvas offcanvas-end bi-offcanvas" tabindex="-1" id="biPanel" aria-labelledby="biPanelTitle">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="biPanelTitle">New Bulk Issue</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" x-data="bulkIssueWizard" @keydown="onKeydown($event)">
        {{-- Step indicator. Clickable, but goTo() refuses to skip a step whose
             requirement is not met yet. --}}
        <ol class="bi-wz" aria-label="Bulk issue steps">
            <template x-for="n in 3" :key="n">
                <li class="bi-wz-step"
                    :class="{ 'is-current': step === n, 'is-done': step > n }">
                    <button type="button" class="bi-wz-btn" @click="goTo(n)"
                            :aria-current="step === n ? 'step' : null"
                            :title="'Go to step ' + n + ': ' + stepTitle(n)">
                        <span class="bi-wz-dot">
                            <i class="bi bi-check-lg" x-show="step > n" aria-hidden="true"></i>
                            <span x-show="step <= n" x-text="n"></span>
                        </span>
                        <span class="bi-wz-label">
                            <span class="bi-wz-caption">Step <span x-text="n"></span></span>
                            <span class="bi-wz-title" x-text="stepTitle(n)"></span>
                        </span>
                    </button>
                </li>
            </template>
        </ol>

        {{-- Draft restore. Offered rather than applied, since the panel may well
             be a fresh entry that should not inherit yesterday's typing. --}}
        <div class="bi-notice" x-show="draftAvailable" x-cloak x-transition.opacity role="status">
            <span class="bi-notice-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
            <div class="bi-notice-body">
                <div class="bi-notice-title">Unsaved draft found</div>
                <p class="bi-notice-text">Details from an earlier entry are still saved on this device.</p>
            </div>
            <div class="bi-notice-actions">
                <button type="button" class="btn btn-primary bi-btn-xs" @click="restoreDraft()">
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore
                </button>
                <button type="button" class="btn btn-outline-secondary bi-btn-xs" @click="discardDraft()">Discard</button>
            </div>
        </div>

        <form method="POST" id="biForm" action="{{ route('store.material.bulk-issues.store') }}">
            @csrf
            <input type="hidden" name="_method" id="biMethod" value="">
            <input type="hidden" name="booking_po_id" id="biPoId" value="" required>

            {{-- STEP 1 — find the paperwork. Store knows an issue by the PO it
                 was booked under, the vendor's PI, or the invoice it arrived
                 against; all three resolve to the same booking record.
                 x-show, not x-if: the inputs must stay in the form so a submit
                 from step 3 still carries what was entered here. --}}
            <div data-bi-step="1" x-show="step === 1" x-transition:enter="bi-step-enter"
                 x-transition:enter-start="bi-step-start" x-transition:enter-end="bi-step-end">

            <div class="bi-card">
            <div class="bi-sec">
                <div>
                    <h6 class="bi-sec-title"><i class="bi bi-receipt" aria-hidden="true"></i>Select Purchase Order</h6>
                    <p class="bi-sec-sub">Find the booking by its PO, the vendor's PI, or the invoice it arrived against.</p>
                </div>
            </div>

            {{-- One control, not two: the type selector sits inside the search
                 field so there is a single label, a single magnifier and a
                 single place to type. --}}
            <label class="form-label" for="biPoSearch">Find PO / PI / Invoice <span class="text-danger">*</span></label>
            <div class="bi-search mb-3" id="biSearchWrap">
                <div class="input-group">
                    <select class="form-select bi-filter-type" id="biFilterType" aria-label="Search by">
                        {{-- "Garments PO" is the buyer's own garment-level PO from
                             the BOM (12458787). The material PO this system
                             generates (HB26FA0004) is a separate identifier and is
                             no longer offered here by request — the po_no group
                             itself is untouched and still serves the rest of the
                             app, so restoring the option is a one-line change. --}}
                        <option value="garments_po" selected>Garments PO</option>
                        <option value="pi_number">PI Number</option>
                        <option value="invoice_no">Invoice No</option>
                    </select>
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="biPoSearch" autocomplete="off"
                           placeholder="Click or type to browse…"
                           role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="biPoList">
                    <span class="bi-pick-status">
                        <span class="spinner-border spinner-border-sm text-primary d-none" id="biPoSpin" role="status" aria-hidden="true"></span>
                        <button type="button" class="btn-close d-none" id="biPoClear" aria-label="Clear search"></button>
                    </span>
                </div>
                <div id="biPoPanel" class="bi-search-panel d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                    <div class="small text-muted px-3 py-2 border-bottom" id="biPoHint"></div>
                    <div class="list-group list-group-flush" id="biPoList" role="listbox"></div>
                </div>
            </div>

            {{-- Loading / failure state for the PO lookup. Without this a failed
                 or slow fetch just left the summary card reading "—" with no
                 explanation of why. --}}
            <div id="biPoLoading" class="d-none d-flex align-items-center gap-2 text-muted small mb-3">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <span>Loading PO details…</span>
            </div>
            <div id="biPoError" class="alert alert-warning py-2 px-3 small d-none mb-3" role="alert"></div>

            {{-- Step 2 — the selection locks into a chip with the PO-level
                 summary beside it, and opens the item picker. --}}
            <div id="biSelectedRow" class="d-none">
                <div id="biSummaryGrid" class="p-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <span class="bi-chip-sel"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span id="biSelectedText">—</span>
                            {{-- Labelled, not a bare ✕: on a chip that already
                                 shows a PO number, a lone ✕ reads as "delete
                                 this PO" rather than "pick a different one". --}}
                            <button type="button" class="btn btn-sm btn-link p-0 ms-2 text-decoration-none bi-btn-inline" id="biClearPo" title="Pick a different PO, PI or Invoice"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Change</button>
                        </span>
                        <span class="badge bg-success-subtle text-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Selected</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">Buyer</div><div class="bi-sum-value" data-sum="buyer_name">—</div></div>
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">Season</div><div class="bi-sum-value" data-sum="season_name">—</div></div>
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">PO Number</div><div class="bi-sum-value" data-sum="po_no">—</div></div>
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">Styles / Items</div><div class="bi-sum-value" id="biSumCounts">—</div></div>
                    </div>
                </div>
            </div>
            </div>{{-- /card --}}
            </div>{{-- /step 1 --}}

            {{-- STEP 2 — one quantity block per selected item. Deliberately ahead
                 of the indent header: this is the step the stock balance can
                 block, and there is no point typing indent details for an issue
                 the ledger will not allow. The four fields and their colour
                 coding are unchanged; only the number of blocks varies with how
                 many items were picked. --}}
            <div data-bi-step="2" x-show="step === 2" x-cloak x-transition:enter="bi-step-enter"
                 x-transition:enter-start="bi-step-start" x-transition:enter-end="bi-step-end">

            <div class="bi-card">
            <div class="bi-sec">
                <div>
                    <h6 class="bi-sec-title"><i class="bi bi-rulers" aria-hidden="true"></i>Issue Quantities</h6>
                    <p class="bi-sec-sub">Choose the item(s) to issue against this PO, then split each one into Bulk / Sample / Liability / Dead.</p>
                </div>
                <button type="button" class="btn btn-primary btn-sm bi-btn-xs" id="biPickBtn" data-bs-toggle="modal" data-bs-target="#biItemsModal">
                    <i class="bi bi-list-check me-1" aria-hidden="true"></i>Select Items
                </button>
            </div>

            <div id="biItemRows" class="d-flex flex-column gap-2"></div>
            <div id="biNoItems" class="bi-empty">
                <div class="bi-empty-icon"><i class="bi bi-list-check" aria-hidden="true"></i></div>
                <div class="bi-empty-title">No items selected yet</div>
                <p class="bi-empty-text">Use <span class="fw-semibold">Select Items</span> to pick the material lines to issue.</p>
            </div>
            <div class="d-flex justify-content-end mt-3 d-none" id="biAddMoreWrap">
                <button type="button" class="btn btn-sm btn-outline-primary bi-btn-xs" data-bs-toggle="modal" data-bs-target="#biItemsModal">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add More Items
                </button>
            </div>
            <div class="alert alert-warning py-2 px-3 small d-none mt-3 mb-0" id="biOverWarn"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><span id="biOverText"></span></div>
            </div>{{-- /card --}}

            <div class="bi-card">
                <label class="form-label" for="biRemarks">Remarks</label>
                <div class="bi-remarks">
                    <textarea name="remarks" id="biRemarks" rows="3" class="form-control" maxlength="1000"
                              placeholder="Any note for this issue — gate pass, receiver, or reason."
                              @input="syncRemarks(); queueDraft()"></textarea>
                    <span class="bi-remarks-count" :class="remarksLength > remarksMax - 50 ? 'is-warn' : ''" aria-hidden="true">
                        <span x-text="remarksLength"></span>/<span x-text="remarksMax"></span>
                    </span>
                </div>
            </div>
            </div>{{-- /step 2 --}}

            {{-- STEP 3 — indent header. Shared by every item in the submission,
                 and reached only once the quantities clear the stock balance. --}}
            <div data-bi-step="3" x-show="step === 3" x-cloak x-transition:enter="bi-step-enter"
                 x-transition:enter-start="bi-step-start" x-transition:enter-end="bi-step-end">

            <div class="bi-card">
            <div class="bi-sec">
                <div>
                    <h6 class="bi-sec-title"><i class="bi bi-clipboard-check" aria-hidden="true"></i>Indent Details</h6>
                    <p class="bi-sec-sub">These details apply to every item in this issue.</p>
                </div>
            </div>

            @if($requisitions->isNotEmpty())
                <div class="mb-3">
                    <label class="form-label">Fulfil Requisition <span class="text-muted small fw-normal">(optional)</span></label>
                    <select name="material_requisition_id" id="biReq" class="form-select">
                        <option value="">None</option>
                        @foreach($requisitions as $req)
                            <option value="{{ $req->id }}">#{{ $req->id }} · {{ $req->po_no }} · {{ $req->material_description }} ({{ ucfirst($req->status) }})</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="row g-3">
                <div class="col-12 col-sm-6 col-xl-3">
                    <label class="form-label">Indent Section</label>
                    <select name="indent_section" id="biSection" class="form-select" @change="queueDraft()">
                        <option value="">Select…</option>
                        @foreach($sections as $section)<option value="{{ $section }}">{{ $section }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="biPerson">Indent Person</label><input name="indent_person" id="biPerson" class="form-control" maxlength="100" placeholder="Name of requester" @input="queueDraft()"></div>
                <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="biReqNo">Requisition No</label><input name="requisition_number" id="biReqNo" class="form-control" maxlength="100" placeholder="Optional reference" @input="queueDraft()"></div>
                <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="biIssueDate">Issue Date <span class="text-danger">*</span></label><input type="date" name="issue_date" id="biIssueDate" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required></div>
                <div class="col-12 col-xl-6">
                    <label class="form-label" for="biIssueNo">Issue No</label>
                    {{-- Pre-filled with the generated number. bi-suggested renders it
                         lighter/italic until the user types, so it reads as a
                         suggestion rather than a value they entered. --}}
                    <input name="issue_no" id="biIssueNo" class="form-control bi-suggested" autocomplete="off" @input="queueDraft()">
                    <div class="form-text"><i class="bi bi-magic me-1" aria-hidden="true"></i>Auto-suggested · editable</div>
                </div>
            </div>
            </div>{{-- /card --}}
            </div>{{-- /step 3 --}}

            {{-- Sticky wizard nav. Save only ever appears on the last step, and
                 stays out of reach while an item is over its stock balance. --}}
            <div class="bi-wizard-bar">
                <div class="bi-bar-meta">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="bi-bar-step">Step <span x-text="step"></span> of 3 · <span x-text="stepTitle(step)"></span></span>
                        <span class="bi-bar-chip" x-show="itemCount > 0" x-cloak>
                            <i class="bi bi-check2-square" aria-hidden="true"></i>
                            <span x-text="itemCount"></span> item(s) selected
                        </span>
                    </div>
                    <p class="bi-bar-hint mb-0" x-show="step === 2" x-cloak>
                        <i class="bi bi-lightbulb" aria-hidden="true"></i>Enter at least one of the four quantities per item.
                    </p>
                </div>

                <div class="bi-bar-actions">
                    <button type="button" class="btn btn-link text-decoration-none text-secondary" data-bs-dismiss="offcanvas">Cancel</button>

                    <button type="button" class="btn btn-outline-secondary" @click="prev()" x-show="step > 1" x-cloak>
                        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
                    </button>

                    <button type="button" class="btn btn-primary" @click="next()" x-show="step < 3">
                        Next<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                    </button>

                    {{-- "Confirm" for a new issue; the JS swaps it to "Update"
                         when an existing one is being corrected. --}}
                    <button type="submit" class="btn btn-primary" x-show="step === 3" x-cloak>
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i><span id="biSaveLabel">Confirm</span>
                    </button>
                </div>
            </div>
        </form>

        {{-- Toasts. Fixed to the viewport so they clear the panel and are not
             clipped by its scroll container. --}}
        <div class="bi-toasts" aria-live="polite" aria-atomic="true">
            <template x-for="t in toasts" :key="t.id">
                <div class="bi-toast shadow-sm" :class="toastClass(t.type)" x-transition.opacity role="status">
                    <i class="bi" :class="toastIcon(t.type)" aria-hidden="true"></i>
                    <span class="flex-grow-1" x-text="t.message"></span>
                    <button type="button" class="btn-close btn-close-sm" @click="dismiss(t.id)" aria-label="Dismiss"></button>
                </div>
            </template>
        </div>
    </div>
</div>

{{-- Two-level item picker, same shape as Receiving's: Style first, then the
     item(s) under each style. A style can carry one item or several, which a
     flat list made hard to read. A single-style PO still shows the style step —
     pre-ticked — because issuing against the wrong style is not recoverable. --}}
<div class="modal fade" id="biItemsModal" tabindex="-1" aria-labelledby="biItemsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--gx-radius);">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="biItemsModalLabel">Select Items to Issue</h5>
                    <div class="small text-muted" id="biModalPo">—</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <ol class="bi-steps mb-4" id="biSteps">
                    <li class="bi-step is-current" id="biCrumb1"><span class="bi-step-dot">1</span><span>Choose Style</span></li>
                    <li class="bi-step" id="biCrumb2"><span class="bi-step-dot">2</span><span>Choose Items</span></li>
                </ol>

                <div id="biModalLoading" class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
                </div>
                <div id="biModalError" class="alert alert-warning d-none mb-0"></div>

                {{-- Level 1: styles --}}
                <div id="biStep1" class="d-none">
                    <div class="bi-pick table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:42px;"><input type="checkbox" class="form-check-input" id="biStyleAll" title="Select all styles" aria-label="Select all styles"></th>
                                    <th>Style Number</th>
                                    <th style="width:110px;" class="text-end">Items</th>
                                    <th style="width:150px;" class="text-end">Available Stock</th>
                                </tr>
                            </thead>
                            <tbody id="biStyleBody"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Level 2: items within the chosen styles. Available stock is
                     what Bulk Issuing cares about — it takes stock out, so the
                     ledger's running balance replaces Receiving's ordered qty. --}}
                <div id="biStep2" class="d-none">
                    <div class="bi-pick table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:42px;"><input type="checkbox" class="form-check-input" id="biItemAll" title="Select all available items" aria-label="Select all available items"></th>
                                    <th>Material</th>
                                    <th>Art. No / SAP Code</th>
                                    <th>Colour / Size</th>
                                    <th style="width:70px;">Unit</th>
                                    <th style="width:120px;" class="text-end">Available</th>
                                </tr>
                            </thead>
                            <tbody id="biItemBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer bi-modal-footer">
                <span class="bi-selcount me-auto" id="biSelCountWrap">
                    <span class="bi-selcount-badge" id="biPickCount">0</span>
                    <span id="biPickCountLabel">selected</span>
                </span>
                <button type="button" class="btn btn-outline-secondary d-none" id="biBackBtn"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                {{-- Back / Next / Confirm, the same three words the outer wizard
                     uses, so one vocabulary covers the whole multi-step flow. --}}
                <button type="button" class="btn btn-primary" id="biNextBtn">Next<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-primary d-none" id="biAddSelected"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Confirm Selection</button>
            </div>
        </div>
    </div>
</div>
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
