{{-- Styles for the New/Edit Bulk Issue form and its item picker.

     A partial because two shells render that form: the index page's slide-in
     panel and the full-page create/edit route. Both pull this in, so the form
     cannot look like two different forms. --}}
    /* Searchable PO picker (offcanvas + inline reuse). */
    .bi-search { position: relative; }
    /* `.bi-search` is a layout wrapper here, but bootstrap-icons also ships a
       glyph under that exact name and paints it through [class*=" bi-"]::before —
       which left a stray magnifier floating above both search fields. Suppressed
       for the wrappers only; the real <i class="bi bi-search"> icons still carry
       the .bi base class and are untouched. */
    .bi-search:not(.bi)::before { content: none !important; }
    .bi-search-panel { z-index: 1080; max-height: 340px; overflow-y: auto; padding-bottom: .25rem; }
    /* Thin scrollbar, matching the one a11y.css already gives the app's other
       scroll areas — same thumb colour, same pill radius. */
    .bi-search-panel::-webkit-scrollbar { width: 10px; }
    .bi-search-panel::-webkit-scrollbar-track { background: transparent; }
    .bi-search-panel::-webkit-scrollbar-thumb {
        background: #CBD5E1; border-radius: var(--gx-radius-pill, 999px);
        border: 3px solid transparent; background-clip: content-box;
    }
    .bi-search-panel::-webkit-scrollbar-thumb:hover { background: #94A3B8; background-clip: content-box; }
    .bi-search-panel { scrollbar-width: thin; scrollbar-color: #CBD5E1 transparent; }

    /* The list's own header: how many values, and under which field. Sticky so
       it still says what is being looked at after a scroll. */
    #biPoHint {
        position: sticky; top: 0; z-index: 2; background: #FBFCFE;
        font-size: .6875rem !important; font-weight: 600; letter-spacing: .04em; text-transform: uppercase;
        color: #64748B !important;
    }

    /* One row: avatar, value, count. */
    .bi-opt { cursor: pointer; border-left: 2px solid transparent; transition: background-color .15s ease, border-color .15s ease; }
    .bi-opt-row {
        display: flex; align-items: center; gap: .7rem; padding: .6rem .85rem;
        border-bottom: 1px solid #F4F6FA;
    }
    .bi-opt-row:last-child { border-bottom: 0; }
    .bi-opt:hover { background: #F6F9FE; }
    .bi-opt.active {
        background: var(--gx-secondary-bg, #DBEAFE); border-left-color: var(--gx-secondary-600, #2563EB);
        box-shadow: inset 0 0 0 1px rgba(37, 99, 235, .25);
    }
    .bi-opt.active .bi-opt-primary { color: var(--gx-secondary-700, #1D4ED8); }

    .bi-opt-avatar {
        flex: none; width: 30px; height: 30px; border-radius: 10px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .8125rem; font-weight: 700; letter-spacing: -.01em;
    }
    .bi-opt-body { min-width: 0; flex: 1 1 auto; }
    .bi-opt-primary {
        font-weight: 600; font-size: .8125rem; letter-spacing: -.01em; color: var(--gx-primary, #0F172A);
        line-height: 1.35; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .bi-opt-meta {
        font-size: .72rem; color: #94A3B8; line-height: 1.4; margin-top: .1rem;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* Count sits at the end of the row as a quiet pill; a value carrying more
       than one booking gets the tinted one. */
    .bi-opt-count {
        flex: none; display: inline-flex; align-items: baseline; gap: .25rem;
        font-size: .75rem; font-weight: 700; font-variant-numeric: tabular-nums;
        color: #64748B; background: #F1F5F9; border: 1px solid #E9EDF3;
        border-radius: 999px; padding: .12rem .5rem;
    }
    .bi-opt-count.is-many {
        color: var(--gx-secondary-700, #1D4ED8); background: #EFF6FF; border-color: #DBEAFE;
    }
    .bi-opt-count-unit { font-size: .65rem; font-weight: 600; opacity: .75; }

    /* Empty state: an explanation and a way forward, not a bare sentence. */
    .bi-opt-empty { padding: 1.5rem 1rem; text-align: center; }
    .bi-opt-empty-icon {
        width: 38px; height: 38px; border-radius: 12px; background: #F8FAFC; border: 1px solid #E9EDF3;
        color: #94A3B8; display: inline-flex; align-items: center; justify-content: center;
        font-size: 1rem; margin-bottom: .5rem;
    }
    .bi-opt-empty-title { font-size: .8125rem; font-weight: 600; color: #334155; }
    .bi-opt-empty-text { font-size: .75rem; line-height: 1.5; color: #94A3B8; margin: .15rem auto 0; max-width: 20rem; }

    /* Step 1's answer, carried above step 2's list. Reads as "you are inside
       this value" rather than as another filter box. */
    .bi-pickchip {
        display: inline-flex; align-items: center; gap: .4rem; max-width: 100%;
        background: #EFF6FF; border: 1px solid #DBEAFE; border-radius: 999px;
        padding: .2rem .3rem .2rem .7rem; font-size: .78rem;
    }
    .bi-pickchip-label { font-weight: 600; color: #64748B; flex: none; }
    .bi-pickchip-label::after { content: ':'; }
    .bi-pickchip-value {
        font-weight: 700; color: var(--gx-secondary-700, #1D4ED8);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .bi-pickchip-x {
        flex: none; border: 0; background: #fff; color: #64748B; border-radius: 999px;
        width: 18px; height: 18px; line-height: 1; font-size: .85rem; padding: 0;
        transition: background-color .15s ease, color .15s ease;
    }
    .bi-pickchip-x:hover { background: var(--gx-secondary-600, #2563EB); color: #fff; }
    /* The chosen PO, stated once at the head of its summary card. Tinted, not
       filled: the card underneath is already the emphasis. */
    .bi-chip-sel {
        display: inline-flex; align-items: center; gap: .5rem; max-width: 100%;
        background: #fff; color: var(--gx-primary, #0F172A); border: 1px solid #DCE7FB;
        border-radius: 10px; padding: .38rem .6rem .38rem .7rem;
        font-size: .875rem; font-weight: 600; letter-spacing: -.01em;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
    }
    .bi-chip-sel > .bi-check-circle-fill { color: var(--gx-secondary-600, #2563EB); font-size: .9rem; }
    .bi-chip-sel .bi-btn-inline { color: #64748B; }
    .bi-chip-sel .bi-btn-inline:hover { color: var(--gx-secondary-700, #1D4ED8); }

    /* Read-only summary grid. */
    /* Read-only summary: the faintest possible tint, so it reads as settled
       context rather than as another set of fields to fill in. */
    #biSummaryGrid {
        background: linear-gradient(180deg, #FBFDFF 0%, #F7FAFE 100%);
        border: 1px solid #E7EEF9; border-radius: 14px;
    }
    #biSummaryGrid .bi-sum-label {
        font-size: .625rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 600;
        color: #9AA7B8; margin-bottom: .15rem;
    }
    #biSummaryGrid .bi-sum-value {
        font-size: .875rem; font-weight: 600; letter-spacing: -.01em; color: var(--gx-primary, #0F172A);
        line-height: 1.35; overflow-wrap: anywhere;
    }
    /* Hairline rules between the four facts on a wide panel — cheaper than
       boxing each one, and it keeps the row reading left to right. */
    @media (min-width: 1200px) {
        #biSummaryGrid .row > [class*="col-"] + [class*="col-"] { box-shadow: inset 1px 0 0 #E7EEF9; }
    }

    /* Colour-coded quantity cards. Compact padding + a tight label so all four
       fit without the form feeling stretched. The border keeps the colour coding; a small
       solid dot repeats it on the label so the four are told apart at a glance
       without relying on hue alone. */
    .bi-qty-card {
        border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 10px; padding: .5rem .6rem;
        background: #fff; height: 100%; transition: border-color .15s ease, box-shadow .15s ease;
    }
    .bi-qty-card.bulk { border-color: #A7F3D0; } .bi-qty-card.sample { border-color: #BFDBFE; }
    .bi-qty-card.liability { border-color: #FDE68A; } .bi-qty-card.dead { border-color: #FECACA; }
    .bi-qty-card:focus-within { box-shadow: 0 0 0 3px rgba(37, 99, 235, .1); }
    .bi-qty-grid .bi-qty-card .form-label {
        display: flex; align-items: center; gap: .35rem; font-size: .75rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: .03em; margin-bottom: .3rem; white-space: nowrap;
    }
    .bi-qty-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; display: inline-block; }
    .bi-qty-card.bulk .bi-qty-dot { background: #10B981; }
    .bi-qty-card.sample .bi-qty-dot { background: #3B82F6; }
    .bi-qty-card.liability .bi-qty-dot { background: #F59E0B; }
    .bi-qty-card.dead .bi-qty-dot { background: #EF4444; }
    /* Figures line up column to column, so four cards read as one row of numbers. */
    .bi-qty-card .bi-qty { font-variant-numeric: tabular-nums; text-align: right; border-radius: 8px; }

    /* Live total against the item's balance — the same numbers the block already
       computes, stated before the user reaches the error. */
    .bi-item-total {
        display: flex; align-items: center; gap: .35rem; font-size: .75rem; font-weight: 600;
        color: var(--gx-text-muted, #64748B); font-variant-numeric: tabular-nums; margin-top: .5rem;
    }
    .bi-item-total .bi-item-total-num { color: var(--gx-primary, #0F172A); }
    .bi-item-card.is-over .bi-item-total .bi-item-total-num { color: var(--gx-danger-700, #B91C1C); }

    /* Auto-suggested value: reads as a suggestion until the user edits it. */
    .bi-suggested { font-style: italic; color: var(--gx-text-muted, #64748B); }


    /* --- Wizard shell ----------------------------------------------------- */
    [x-cloak] { display: none !important; }

    /* Panel chrome. The body carries a soft canvas so the white section cards
       inside it read as raised rather than as one flat wall of fields. */
    #biPanel .offcanvas-header { padding: 1.05rem 1.5rem; }
    #biPanel .offcanvas-title { font-size: 1.0625rem; font-weight: 700; letter-spacing: -.01em; color: var(--gx-primary, #0F172A); }
    #biPanel .offcanvas-body { padding: 1.25rem 1.5rem 0; background: #F8FAFC; }
    #biPanel .form-label { font-size: .78rem; font-weight: 600; color: #334155; margin-bottom: .3rem; }
    /* One field treatment across the panel: hairline border, room to breathe,
       and a focus ring that grows in rather than snapping on. */
    #biPanel .form-control, #biPanel .form-select {
        border: 1px solid #E2E8F0; border-radius: 10px; font-size: .875rem; color: var(--gx-primary, #0F172A);
        background-color: #fff;
        transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease;
    }
    /* Full-size fields only. The four quantity inputs are form-control-sm and
       must stay compact, or the 4-up grid on an item card grows a row taller. */
    #biPanel .form-control:not(.form-control-sm), #biPanel .form-select:not(.form-select-sm) {
        padding: .5rem .75rem; min-height: 40px;
    }
    #biPanel .form-control::placeholder { color: #A9B4C4; }
    #biPanel .form-control:hover:not(:focus), #biPanel .form-select:hover:not(:focus) { border-color: #CBD5E1; }
    #biPanel .form-control:focus, #biPanel .form-select:focus {
        border-color: var(--gx-secondary-600, #2563EB); box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
        outline: 0;
    }
    #biPanel .form-select { padding-right: 2.25rem; background-position: right .75rem center; }
    #biPanel .form-control[readonly], #biPanel .form-control:disabled { background-color: #F8FAFC; color: #64748B; }
    /* The search control is one unit: the type selector, the magnifier and the
       field share a single border and a single focus ring. */
    #biPanel .bi-search .input-group > .form-select,
    #biPanel .bi-search .input-group > .form-control { border-radius: 0; }
    #biPanel .bi-search .input-group > :first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
    #biPanel .bi-search .input-group > .bi-pick-status { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
    #biPanel .bi-search .input-group-text {
        background: #fff; border: 1px solid #E2E8F0; border-inline: 0; color: #94A3B8; min-height: 40px;
    }
    #biPanel .bi-search:focus-within .input-group-text { border-color: var(--gx-secondary-600, #2563EB); }
    #biPanel .bi-filter-type { max-width: 12.5rem; font-weight: 600; color: #334155; background-color: #FBFCFE; }
    #biPanel .form-text { font-size: .72rem; color: #94A3B8; }

    /* Section card: one visual group per concern (search, indent, quantities). */
    .bi-card {
        background: #fff; border: 1px solid #E9EDF3; border-radius: 16px;
        padding: 1.15rem 1.25rem; margin-bottom: 1rem;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 12px 30px -24px rgba(15, 23, 42, .45);
    }
    .bi-sec { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: .9rem; }
    .bi-sec-title { display: flex; align-items: center; gap: .45rem; font-size: .9375rem; font-weight: 700; letter-spacing: -.01em; color: var(--gx-primary, #0F172A); margin: 0; }
    .bi-sec-title i { color: var(--gx-secondary-600, #2563EB); font-size: .95rem; }
    .bi-sec-sub { font-size: .78rem; line-height: 1.45; color: var(--gx-text-muted, #64748B); margin: .2rem 0 0; }

    /* Section number: a visual guide only — every section is on the same page
       and none of them is a navigation target. */
    /* Section badge: a raised hairline chip carrying a two-digit ordinal, not a
       filled swatch. Colour here would compete with the quantity types, which
       are the only thing on this panel that colour should mean something for. */
    .bi-sec-num {
        flex: none; display: inline-flex; align-items: center; justify-content: center;
        min-width: 26px; height: 22px; padding: 0 .4rem; border-radius: 7px;
        font-size: .6875rem; font-weight: 700; letter-spacing: .02em; font-variant-numeric: tabular-nums;
        background: #fff; color: #334155; border: 1px solid #E2E8F0;
        box-shadow: 0 1px 1px rgba(15, 23, 42, .04);
        transition: color .18s ease, border-color .18s ease, box-shadow .18s ease;
    }
    /* The section the user can act on now reads as the live one. */
    .bi-sect:not(.is-locked) .bi-sec-num {
        color: var(--gx-secondary-700, #1D4ED8); border-color: #CFE0FD;
        box-shadow: 0 1px 2px rgba(37, 99, 235, .12), 0 0 0 3px rgba(37, 99, 235, .06);
    }
    .bi-sec-title { gap: .55rem; }

    /* A section whose dependency is not met yet: visible, so the user can see
       what is coming, but not usable. `inert` on the same node keeps it out of
       the tab order — the grey alone would not stop a keyboard user. */
    .bi-locked { opacity: .55; filter: saturate(.6); pointer-events: none; user-select: none; }
    .bi-lock-note {
        display: flex; align-items: center; gap: .4rem; font-size: .75rem; font-weight: 600; color: #94A3B8;
        background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 10px; padding: .45rem .7rem; margin-bottom: .9rem;
    }
    .bi-lock-note i { color: #94A3B8; }
    .bi-sect.is-locked .bi-sec-num { background: #F1F5F9; color: #94A3B8; border-color: #E2E8F0; }

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

    /* Empty state: an invitation, not a warning. A soft dash, a raised icon
       tile, and the action itself inside the box so there is one obvious thing
       to do rather than a sentence pointing at a button elsewhere. */
    .bi-empty {
        border: 1px dashed #DDE4EE; border-radius: 14px; padding: 2rem 1.25rem; text-align: center;
        background:
            radial-gradient(120% 90% at 50% 0%, #FFFFFF 0%, #FBFCFE 55%, #F8FAFC 100%);
    }
    .bi-empty-icon {
        width: 48px; height: 48px; border-radius: 14px; background: #fff; border: 1px solid #E7EAF0;
        color: #8A97A8; display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem;
        margin-bottom: .7rem; box-shadow: 0 1px 2px rgba(15, 23, 42, .05), 0 8px 18px -12px rgba(15, 23, 42, .35);
    }
    .bi-empty-title { font-size: .875rem; font-weight: 600; letter-spacing: -.01em; color: #1E293B; }
    .bi-empty-text { font-size: .8125rem; line-height: 1.5; color: #94A3B8; margin: .2rem auto 0; max-width: 26rem; }
    .bi-empty .btn { margin-top: .9rem; border-radius: 10px; font-weight: 600; font-size: .8125rem; padding: .5rem 1.1rem; }

    /* Remarks: counter parks inside the field, bottom-right. */
    .bi-remarks { position: relative; }
    .bi-remarks textarea.form-control { border-radius: 12px; padding: .7rem .85rem 1.9rem; resize: vertical; line-height: 1.5; }
    .bi-remarks textarea.form-control::placeholder { color: #94A3B8; }
    .bi-remarks-count {
        position: absolute; right: .55rem; bottom: .45rem; pointer-events: none; font-size: .6875rem;
        font-weight: 600; color: #94A3B8; background: #fff; padding: 0 .25rem; border-radius: 6px;
    }
    .bi-remarks-count.is-warn { color: #B45309; }

    /* Sticky action bar: full-bleed inside the panel, secondary actions on the
       left of the group, one dominant primary on the right. */
    /* Frosted, so the last section stays faintly readable under it and the bar
       reads as floating over the form rather than as its final row. */
    .bi-wizard-bar {
        position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
        background: rgba(255, 255, 255, .82);
        -webkit-backdrop-filter: saturate(180%) blur(14px); backdrop-filter: saturate(180%) blur(14px);
        border-top: 1px solid rgba(226, 232, 240, .9); margin: .25rem -1.5rem 0;
        padding: .85rem 1.5rem calc(.85rem + env(safe-area-inset-bottom, 0px));
        box-shadow: 0 -10px 24px -18px rgba(15, 23, 42, .55);
    }
    @supports not (backdrop-filter: blur(4px)) { .bi-wizard-bar { background: #fff; } }
    .bi-bar-meta { min-width: 0; margin-right: auto; }
    .bi-bar-step { font-size: .8125rem; font-weight: 600; letter-spacing: -.01em; color: #475569; }
    /* Blocking count in the status bar — the one thing standing between the
       user and Save, so it is stated rather than left to a disabled button. */
    .bi-bar-chip.is-error {
        color: #B91C1C; background: #FEF2F2; border-color: #FECACA;
    }
    .bi-bar-chip {
        display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; font-weight: 600;
        color: var(--gx-secondary-700, #1D4ED8); background: #EFF6FF; border: 1px solid var(--gx-secondary-border, #BFDBFE);
        border-radius: 999px; padding: .2rem .65rem;
    }
    .bi-bar-hint { display: flex; align-items: center; gap: .3rem; font-size: .7rem; color: #94A3B8; margin: .3rem 0 0; }
    .bi-bar-actions { display: flex; align-items: center; gap: .5rem; margin-left: auto; }
    .bi-bar-actions .btn {
        border-radius: 10px; font-weight: 600; font-size: .8125rem; padding: .5rem .95rem;
        transition: background-color .18s ease, box-shadow .18s ease, transform .12s ease, color .18s ease;
    }
    .bi-bar-actions .btn-link:hover { color: var(--gx-primary, #0F172A) !important; }
    .bi-bar-actions .btn-primary {
        padding-inline: 1.4rem; border: 0;
        background: linear-gradient(180deg, #3B82F6 0%, #2563EB 100%);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .12), 0 10px 18px -10px rgba(37, 99, 235, .75);
    }
    .bi-bar-actions .btn-primary:hover:not(:disabled) {
        background: linear-gradient(180deg, #2E76F0 0%, #1D4ED8 100%); transform: translateY(-1px);
        box-shadow: 0 1px 2px rgba(15, 23, 42, .12), 0 14px 22px -10px rgba(37, 99, 235, .8);
    }
    .bi-bar-actions .btn-primary:active:not(:disabled) { transform: translateY(0); }
    /* Disabled Save is a state, not a dead button: it keeps its shape and loses
       its lift, and the title attribute says what is missing. */
    .bi-bar-actions .btn-primary:disabled {
        background: #E2E8F0; color: #94A3B8; box-shadow: none; opacity: 1;
    }
    .bi-bar-actions .btn:focus-visible { box-shadow: 0 0 0 4px rgba(37, 99, 235, .18); outline: 0; }

    @media (max-width: 575.98px) {
        #biPanel .offcanvas-body { padding-inline: 1rem; }
        .bi-wizard-bar { margin-inline: -1rem; padding-inline: 1rem; }
        .bi-bar-actions { width: 100%; }
        .bi-bar-actions .btn-primary { flex: 1 1 auto; }
    }

    @keyframes biShake { 0%,100% { transform: none; } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
    .bi-shake { animation: biShake .25s ease-in-out; }

    /* Toasts sit above the offcanvas (z 1045) and its backdrop. */
    .bi-toasts { position: fixed; top: 1rem; right: 1rem; z-index: 1090; display: flex; flex-direction: column; gap: .5rem; max-width: min(360px, calc(100vw - 2rem)); }
    .bi-toast { display: flex; align-items: center; gap: .6rem; background: #fff; border: 1px solid; border-left-width: 4px; border-radius: 10px; padding: .6rem .75rem; font-size: .8125rem; }

    @media (prefers-reduced-motion: reduce) {
        .bi-step, .bi-step-dot, .bi-step + .bi-step::before,
        .bi-pick tbody tr.bi-row, .bi-qty-card, .bi-item-card { transition: none; }
        .bi-shake, .bi-step-in { animation: none; }
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

    /* Item picker modal — mirrors Receiving's stepper and pick table, so the two
       screens read as one system. Dot + caption/title, connector fills on
       progress, tick replaces the number once a step is behind you. */
    .bi-steps {
        display: flex; align-items: center; list-style: none; margin: 0;
        padding: .7rem .9rem; background: var(--gx-bg, #F8FAFC);
        border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px;
    }
    .bi-step { display: flex; align-items: center; gap: .6rem; min-width: 0; color: #94A3B8; transition: color .15s ease; }
    .bi-step + .bi-step { flex: 1 1 auto; }
    .bi-step + .bi-step::before {
        content: ''; flex: 1 1 auto; min-width: 1.5rem; height: 2px; border-radius: 2px;
        background: var(--bs-border-color, #E2E8F0); margin: 0 .85rem; transition: background-color .15s ease;
    }
    .bi-step.is-current::before, .bi-step.is-done::before { background: var(--gx-secondary-600, #2563EB); }
    .bi-step-dot {
        width: 30px; height: 30px; flex: none; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
        font-size: .8125rem; font-weight: 700; background: #fff; color: #94A3B8; border: 2px solid var(--bs-border-color, #E2E8F0);
        transition: background-color .15s ease, color .15s ease, border-color .15s ease, box-shadow .15s ease;
    }
    .bi-step-dot .bi { display: none; font-size: .875rem; }
    .bi-step.is-done .bi-step-dot .bi { display: inline; }
    .bi-step.is-done .bi-step-dot .bi-step-num { display: none; }
    .bi-step-label { display: block; min-width: 0; }
    .bi-step-caption {
        display: block; font-size: .625rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: .06em; color: #94A3B8; line-height: 1.3;
    }
    .bi-step-text {
        display: block; font-size: .8125rem; font-weight: 600; line-height: 1.3;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .bi-step.is-current { color: var(--gx-primary, #0F172A); }
    .bi-step.is-current .bi-step-dot {
        border-color: var(--gx-secondary-600, #2563EB); color: var(--gx-secondary-700, #1D4ED8);
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .14);
    }
    .bi-step.is-current .bi-step-caption { color: var(--gx-secondary-600, #2563EB); }
    .bi-step.is-current .bi-step-text { color: var(--gx-primary, #0F172A); font-weight: 700; }
    .bi-step.is-done { color: #64748B; }
    .bi-step.is-done .bi-step-dot { background: var(--gx-secondary-600, #2563EB); border-color: var(--gx-secondary-600, #2563EB); color: #fff; }
    .bi-step.is-done .bi-step-text { color: #334155; }

    /* Toolbar above the pick table: filter, labelled Select all, live count.
       The filter is a Bootstrap input-group, the same construction the page's
       other search fields use, rather than an icon absolutely positioned over a
       plain input — the group sizes itself, so the magnifier cannot drift out of
       the field when the row's height changes. */
    .bi-pick-bar { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin: 1rem 0 .65rem; }
    .bi-pick-filter { flex: 1 1 16rem; min-width: 0; width: auto; }
    .bi-pick-filter .input-group-text {
        background: #fff; border-right: 0; color: #94A3B8; padding-right: .35rem;
        border-radius: 12px 0 0 12px;
    }
    .bi-pick-filter .form-control { border-left: 0; border-radius: 0 12px 12px 0; font-size: .875rem; }
    /* One ring around the whole group, so the split border still reads as a
       single field when it takes focus. */
    .bi-pick-filter .form-control:focus { box-shadow: none; border-color: var(--bs-border-color, #E2E8F0); }
    .bi-pick-filter:focus-within .input-group-text,
    .bi-pick-filter:focus-within .form-control { border-color: var(--gx-secondary-600, #2563EB); }
    .bi-pick-filter:focus-within { border-radius: 12px; box-shadow: 0 0 0 4px rgba(37, 99, 235, .12); }
    /* Text label, never an icon: the project-wide rule in components.css turns
       any .btn carrying a leading <i class="bi"> into a 38px icon-only square
       and hides its <span>, which is the opposite of what a shortcut button
       needs. Leaving the icon off keeps the count visible. */
    .bi-selectall { flex: none; border-radius: 12px; font-weight: 600; font-size: .8125rem; padding: .45rem .85rem; white-space: nowrap; }
    .bi-pick-clear { flex: none; font-size: .78rem; font-weight: 600; text-decoration: none; padding: .45rem .35rem; }
    .bi-showing { flex: none; font-size: .75rem; color: #94A3B8; font-variant-numeric: tabular-nums; }
    /* Filtered out, not removed — the ticks on those rows survive. */
    .bi-pick tr.is-filtered { display: none; }
    .bi-nomatch {
        border: 1px dashed #CBD5E1; border-radius: 12px; background: var(--gx-bg, #F8FAFC);
        padding: 1.75rem 1rem; text-align: center; color: var(--gx-text-muted, #64748B); font-size: .8125rem;
    }

    @keyframes biStepIn { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: none; } }
    .bi-step-in { animation: biStepIn .18s cubic-bezier(.4, 0, .2, 1) both; }

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
    /* Tint plus a left accent, so a ticked row stays legible on a washed-out
       factory monitor where the tint alone is easy to miss. */
    .bi-pick tbody tr.bi-row.is-checked { background: var(--gx-secondary-bg, #DBEAFE); }
    .bi-pick tbody tr.bi-row.is-checked td:first-child { box-shadow: inset 3px 0 0 var(--gx-secondary-600, #2563EB); }
    .bi-pick tbody tr.bi-row.is-checked .bi-cell-primary { font-weight: 600; }
    /* Compact rows: this is a scan-and-tick surface. */
    .bi-pick td { padding-top: .5rem; padding-bottom: .5rem; }
    .bi-pick td:first-child, .bi-pick th:first-child { padding-left: .85rem; }
    .bi-pick td:last-child, .bi-pick th:last-child { padding-right: .85rem; }
    .bi-pick .form-check-input { width: 1.05rem; height: 1.05rem; margin-top: 0; }
    /* Already added: visible for reference, but not selectable again. */
    .bi-pick tbody tr.is-added { cursor: default; color: #94A3B8; }
    .bi-pick tbody tr.is-added:hover { background: transparent; }
    .bi-group-row td { background: #F1F5F9; font-weight: 600; font-size: .8125rem; color: var(--gx-text-muted, #64748B); border-top: 1px solid var(--bs-border-color, #E2E8F0); }
    .bi-cell-primary { color: var(--gx-primary, #0F172A); line-height: 1.35; }
    .bi-cell-sub { font-size: .75rem; color: var(--gx-text-muted, #64748B); line-height: 1.35; }
    .bi-pick tbody tr.is-added .bi-cell-primary { color: #94A3B8; }

    /* Sticky action bar. modal-dialog-scrollable already parks the footer under
       the scrolling body; these give it the weight to read as one. */
    .bi-modal-footer {
        background: #fff; border-top: 1px solid var(--bs-border-color, #E2E8F0); gap: .5rem;
        box-shadow: 0 -8px 20px -14px rgba(15, 23, 42, .5);
    }
    .bi-modal-footer .btn { border-radius: 10px; font-weight: 600; font-size: .8125rem; padding: .5rem .95rem; }
    .bi-modal-footer .btn-primary { padding-inline: 1.35rem; box-shadow: 0 8px 16px -8px rgba(37, 99, 235, .65); }
    .bi-modal-footer .btn-primary:disabled { box-shadow: none; }
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
    /* Style, called out on a selected item's card. The one field on it that a
       correction cannot undo, so it is stated rather than listed. */
    .bi-item-style {
        display: inline-flex; align-items: center; gap: .3rem; margin: .15rem 0;
        font-size: .75rem; font-weight: 700; color: #4338CA; background: #EEF2FF;
        border: 1px solid #DDE3FE; border-radius: 7px; padding: .1rem .4rem;
    }
    .bi-item-style i { font-size: .65rem; opacity: .8; }
    /* Same treatment in the picker's leading column, so the two read as one. */
    .bi-style-tag { font-size: .75rem; font-weight: 700; color: #4338CA; white-space: nowrap; }
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

    /* Listing search: one tall, quiet field — the icon inside it rather than in
       a bordered add-on, which is what makes it read as a single control. */
    #biSearchInput, .bi-search .input-group-text {
        border-color: #E9EDF3; background: #FBFCFE; height: 42px;
    }
    #biSearchInput { font-size: .875rem; border-left: 0; padding-left: 0; }
    #biSearchInput::placeholder { color: #A9B4C4; }
    .bi-search .input-group-text { border-right: 0; color: #94A3B8; }
    .bi-search:focus-within .input-group-text,
    #biSearchInput:focus { background: #fff; border-color: var(--gx-secondary-600, #2563EB); box-shadow: none; }
    .bi-search:focus-within { border-radius: 10px; box-shadow: 0 0 0 4px rgba(37, 99, 235, .1); }
    .bi-search .input-group > :first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
    .bi-search .input-group > :last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }

    /* Mobile: the floating bar spans the width rather than shrinking to a
       cramped puck, and the history table becomes a stacked card list. */
    @media (max-width: 767.98px) {
        .bi-bulkbar {
            left: .75rem; right: .75rem; bottom: .75rem; transform: none; width: auto; max-width: none;
            animation-name: biBarInFlat;
        }
        @keyframes biBarInFlat { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        .bi-history-table thead { display: none; }
        .bi-history-table, .bi-history-table tbody, .bi-history-table tr, .bi-history-table td { display: block; width: 100%; }
        .bi-history-table tr { border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px; margin-bottom: .75rem; padding: .5rem .25rem; }
        .bi-history-table td { border: 0; display: flex; justify-content: space-between; align-items: center; text-align: right; padding: .35rem .75rem; }
        .bi-history-table td::before { content: attr(data-label); font-size: .7rem; text-transform: uppercase; letter-spacing: .03em; color: #94A3B8; font-weight: 600; text-align: left; }
        .bi-history-table td[data-label="PO / Material"] { flex-direction: column; align-items: flex-start; text-align: left; }
    }
