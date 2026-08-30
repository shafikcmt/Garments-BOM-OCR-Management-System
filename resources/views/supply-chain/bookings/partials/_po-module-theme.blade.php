{{--
    PO Generation module theme — the single token layer for every screen in
    this module: Pending Preview, Generated PO List, the Booking Format Preview
    modal chrome, the Generated PO view, and Admin PO Generate Control (index
    and detail).

    WHY THIS FILE EXISTS. The module used to carry three unrelated palettes: the
    list pages were indigo (#4338ca), the printed sheet is Humana blue
    (#006C9D), and the rest of the app is --gx-*. Add eight different border
    radii and three shadow recipes across screens that sit one click apart, and
    the result reads as unfinished no matter how much polish any single page
    got. One token set, defined once, included by all five.

    THE PALETTE IS THE DOCUMENT'S OWN. --po-blue and --po-tint are lifted
    verbatim from the booking sheet's header and info-table. This module exists
    to produce that sheet, so its chrome is coloured by it rather than by a
    generic dashboard indigo that relates to nothing.

    WHAT THIS FILE MUST NEVER DO. Not one selector here may match inside
    .booking-format-preview-box. That box is the official Humana Apparels paper
    format, styled entirely by preview.blade.php, and it has to stay
    pixel-identical to what the vendor receives. Every class below is prefixed
    .po- and none of them are used inside the sheet.

    Include from @section('styles'), before the page's own <style> block.
--}}
@once
<style>
    /* --- Tokens ---------------------------------------------------------
     * Derived from the global --gx-* set where an equivalent already exists
     * (accent, warning, danger, shadow), so this module stays part of the app
     * rather than becoming a second design system.
     */
    :root {
        /* Quoted from the booking sheet. Do not "improve" these — they are the
           document's identity, and the chrome matching them is the point. */
        --po-blue: #006C9D;
        --po-blue-deep: #004B70;
        --po-tint: #E0F2FE;

        --po-ink: #0B2B3C;
        --po-muted: #5B7383;
        --po-line: #D7E3ED;
        --po-line-soft: #EEF3F8;
        --po-paper: #F6F9FC;
        --po-surface: #FFFFFF;

        /* Semantic states keep the meanings the module already used. */
        --po-ok: var(--gx-accent-700, #047857);
        --po-ok-bg: #ECFDF5;
        --po-ok-border: var(--gx-accent-border, #A7F3D0);
        --po-warn: var(--gx-warning-700, #B45309);
        --po-warn-bg: #FFF7ED;
        --po-warn-border: #FED7AA;
        --po-info: #1D4ED8;
        --po-info-bg: #EFF6FF;
        --po-info-border: #BFDBFE;
        --po-danger: var(--gx-danger-700, #B91C1C);
        --po-danger-bg: #FEF2F2;
        --po-danger-border: var(--gx-danger-border, #FECACA);

        /* THREE radii, not eight. Surfaces, controls, pills. */
        --po-r-surface: 14px;
        --po-r-control: 10px;
        --po-r-pill: 999px;

        /* One shadow recipe, at two depths. */
        --po-shadow: 0 14px 34px rgba(11, 43, 60, .06);
        --po-shadow-sm: 0 6px 16px rgba(11, 43, 60, .05);

        /* The spacing rhythm. Every hero/filter/table gap on every screen in
           this module is one of these two, and nothing else. */
        --po-gap: 20px;
        --po-gap-sm: 12px;
    }

    /* --- Type scale ------------------------------------------------------
     * Four steps, no new webfont. tokens.css notes that factory-floor browsers
     * are old and offline-prone, so a CDN font would be a hard dependency on a
     * network that is not always there. The personality comes from the scale,
     * the tracking and tabular figures instead.
     */
    .po-micro {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--po-muted);
        line-height: 1.2;
    }
    .po-title {
        font-size: 15px;
        font-weight: 800;
        letter-spacing: -.01em;
        color: var(--po-ink);
        margin: 0;
    }
    /* Every figure in this module. Quantities in a column have to line up
       digit-for-digit, which proportional numerals will not do. */
    .po-figure {
        font-size: 26px;
        font-weight: 900;
        letter-spacing: -.03em;
        line-height: 1;
        color: var(--po-ink);
        font-variant-numeric: tabular-nums;
        font-feature-settings: "tnum" 1;
    }

    /* --- Signature: the docket rail --------------------------------------
     * A 3px bar down the left edge of a KPI card or a section head, echoing the
     * ruled stub of the paper docket this module produces. It is not
     * decoration: the colour says which state the block is about — blue for the
     * pending queue, emerald for what has already been generated.
     */
    .po-rail { position: relative; overflow: hidden; }
    .po-rail::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: var(--po-rail-color, var(--po-blue));
    }
    .po-rail-ok { --po-rail-color: var(--gx-accent, #10B981); }
    .po-rail-warn { --po-rail-color: var(--gx-warning, #F59E0B); }

    /* --- KPI cards -------------------------------------------------------- */
    .po-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: var(--po-gap-sm);
    }
    .po-kpi {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px 14px 19px;
        background: var(--po-surface);
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-surface);
        box-shadow: var(--po-shadow-sm);
    }
    .po-kpi-icon {
        width: 40px;
        height: 40px;
        flex: 0 0 40px;
        border-radius: var(--po-r-control);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        background: var(--po-tint);
        color: var(--po-blue-deep);
    }
    .po-kpi-ok .po-kpi-icon { background: var(--po-ok-bg); color: var(--po-ok); }
    .po-kpi-warn .po-kpi-icon { background: var(--po-warn-bg); color: var(--po-warn); }
    .po-kpi-body { min-width: 0; }
    .po-kpi-sub { font-size: 11px; color: var(--po-muted); line-height: 1.25; margin-top: 3px; }

    /* --- Panels ----------------------------------------------------------- */
    .po-panel {
        background: var(--po-surface);
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-surface);
        box-shadow: var(--po-shadow);
    }
    .po-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--po-gap-sm);
        flex-wrap: wrap;
        padding: 14px 18px;
        border-bottom: 1px solid var(--po-line-soft);
    }
    .po-panel-head-title { display: flex; align-items: center; gap: 10px; }
    .po-panel-icon {
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
    .po-panel-body { padding: 18px; }

    /* --- Page header ------------------------------------------------------
     * Deliberately NOT a dark gradient hero. That is the default every
     * dashboard gets, it fought the white sheet below it, and it forced the
     * action buttons onto a separate bar to stay readable. A quiet paper-toned
     * header with a rail reads as the top of a document, which is what it is.
     */
    .po-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: var(--po-gap-sm);
        flex-wrap: wrap;
        padding: 16px 20px;
        background: linear-gradient(180deg, #FFFFFF 0%, var(--po-paper) 100%);
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-surface);
        box-shadow: var(--po-shadow-sm);
    }
    .po-head-title {
        font-size: clamp(1.05rem, 1.5vw, 1.3rem);
        font-weight: 900;
        letter-spacing: -.02em;
        color: var(--po-ink);
        margin: 2px 0 0;
    }
    .po-head-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        border-radius: var(--po-r-control);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--po-tint);
        color: var(--po-blue-deep);
        font-size: 19px;
    }

    /* --- Pills ------------------------------------------------------------ */
    .po-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 11px;
        border-radius: var(--po-r-pill);
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
        border: 1px solid var(--po-line);
        background: var(--po-paper);
        color: var(--po-ink);
    }
    .po-pill-blue { color: var(--po-blue-deep); background: var(--po-tint); border-color: #BAE0F2; }
    .po-pill-ok { color: var(--po-ok); background: var(--po-ok-bg); border-color: var(--po-ok-border); }
    .po-pill-warn { color: var(--po-warn); background: var(--po-warn-bg); border-color: var(--po-warn-border); }
    .po-pill-info { color: var(--po-info); background: var(--po-info-bg); border-color: var(--po-info-border); }
    .po-pill-danger { color: var(--po-danger); background: var(--po-danger-bg); border-color: var(--po-danger-border); }

    /* --- Tables -----------------------------------------------------------
     * The header band is the sheet's own #E0F2FE tint, so the list and the
     * document it produces finally rhyme.
     *
     * Hairline dividers, NOT zebra striping. Zebra + hover + a tinted header is
     * three competing row signals in one table; the divider keeps the hover
     * state legible as the only thing that moves.
     */
    .po-table { border-color: var(--po-line-soft) !important; margin-bottom: 0; }
    .po-table thead th {
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
    .po-table tbody td {
        vertical-align: middle;
        font-size: 13px;
        padding: 12px;
        line-height: 1.35;
        color: var(--po-ink);
        border-top: 1px solid var(--po-line-soft);
        border-bottom: 0;
    }
    .po-table tbody tr:first-child td { border-top: 0; }
    .po-table tbody tr { transition: background .14s ease; }
    .po-table tbody tr:hover { background: #F2F8FC; }
    /* Numeric columns line up on the decimal. */
    .po-table .po-num {
        text-align: right;
        font-variant-numeric: tabular-nums;
        font-feature-settings: "tnum" 1;
    }

    /* --- Controls ---------------------------------------------------------- */
    .po-field-label {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--po-muted);
        margin-bottom: 5px;
    }
    .po-form .form-control,
    .po-form .form-select {
        min-height: 40px;
        border-radius: var(--po-r-control);
        border-color: var(--po-line);
        color: var(--po-ink);
        font-size: 13px;
        font-weight: 600;
    }
    .po-form .form-control:focus,
    .po-form .form-select:focus {
        border-color: var(--po-blue);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    /* One primary action per form, and it is the only filled button. */
    .po-btn-primary {
        background: var(--po-blue);
        border: 1px solid var(--po-blue);
        color: #fff;
        font-weight: 800;
        border-radius: var(--po-r-control);
        min-height: 40px;
        padding: 0 18px;
    }
    .po-btn-primary:hover,
    .po-btn-primary:focus { background: var(--po-blue-deep); border-color: var(--po-blue-deep); color: #fff; }
    .po-btn-quiet {
        background: var(--po-surface);
        border: 1px solid var(--po-line);
        color: var(--po-ink);
        font-weight: 700;
        border-radius: var(--po-r-control);
        min-height: 40px;
        padding: 0 16px;
    }
    .po-btn-quiet:hover { background: var(--po-paper); border-color: #BFD3E1; color: var(--po-ink); }

    /* --- Segmented view switch --------------------------------------------- */
    .po-switch {
        display: inline-flex;
        gap: 4px;
        padding: 4px;
        border-radius: var(--po-r-control);
        border: 1px solid var(--po-line);
        background: var(--po-surface);
        box-shadow: var(--po-shadow-sm);
    }
    .po-switch-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 34px;
        padding: 0 14px;
        border-radius: 7px;
        font-size: 13px;
        font-weight: 800;
        color: var(--po-muted);
        text-decoration: none;
        border: 0;
        transition: .16s ease;
    }
    .po-switch-btn:hover { color: var(--po-blue-deep); background: var(--po-tint); }
    .po-switch-btn.is-active { color: #fff; background: var(--po-blue); }

    /* --- Bootstrap primary, inside this module only -------------------------
     * .btn-primary is bound app-wide to --gx-secondary (#3B82F6). Left alone,
     * every Bootstrap-styled button in this module would be a different blue
     * from the document blue everything else here uses. Scoped to .po-scope so
     * the rest of the app is untouched.
     */
    .po-scope .btn-primary {
        background: var(--po-blue);
        border-color: var(--po-blue);
    }
    .po-scope .btn-primary:hover,
    .po-scope .btn-primary:focus,
    .po-scope .btn-primary:active {
        background: var(--po-blue-deep);
        border-color: var(--po-blue-deep);
    }
    .po-scope .btn-outline-primary {
        color: var(--po-blue);
        border-color: var(--po-blue);
    }
    .po-scope .btn-outline-primary:hover {
        background: var(--po-blue);
        border-color: var(--po-blue);
        color: #fff;
    }
    .po-scope .text-primary { color: var(--po-blue) !important; }

    /* --- Accessibility floor ------------------------------------------------ */
    .po-switch-btn:focus-visible,
    .po-btn-primary:focus-visible,
    .po-btn-quiet:focus-visible {
        outline: 2px solid var(--po-blue);
        outline-offset: 2px;
    }
    @media (prefers-reduced-motion: reduce) {
        .po-table tbody tr,
        .po-switch-btn { transition: none; }
    }

    @media (max-width: 767.98px) {
        .po-table thead th,
        .po-table tbody td { padding: 10px 8px; }
        .po-head { padding: 14px; }
    }
</style>
@endonce
