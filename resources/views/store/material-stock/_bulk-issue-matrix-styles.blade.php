{{-- Styles for the issue matrix, its column filter and the Indent
     Authorization header.

     Split out of _bulk-issue-form-styles rather than appended to it: that file
     already covers the PO picker, the item picker and the history table, and
     the matrix is a large enough block of its own to be worth finding on its
     own. Included from there, so both shells still pull in one stylesheet. --}}

    /* ======================================================================
       ISSUE MATRIX
       ======================================================================
       The selected items render as one dense grid rather than a stack of
       cards: an issue is typed against 40-60 BOM lines at a time, and a card
       each put four numbers on four separate screens. The four colours below
       are the four quantity kinds — they are the only thing distinguishing
       four otherwise identical columns, so the headers carry the same tint. */
    .bi-mx-bar,
    .bi-mx-card {
        --bi-bulk: #059669;
        --bi-sample: #2563EB;
        --bi-liability: #D97706;
        --bi-dead: #DC2626;
    }

    /* Toolbar above the matrix: search, hidden-row count, column filter. */
    .bi-mx-bar { display: flex; align-items: center; gap: .5rem; margin-bottom: .6rem; flex-wrap: wrap; }
    .bi-mx-search { position: relative; flex: 1 1 260px; min-width: 190px; }
    .bi-mx-search > i {
        position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
        color: #6B7280; font-size: .85rem; pointer-events: none; z-index: 2;
    }
    .bi-mx-search .form-control {
        padding: 5px 12px 5px 32px; font-size: .82rem; border-radius: 4px;
        border-color: #E5E7EB; background: #fff;
    }
    .bi-mx-search .form-control:focus { background: #fff; border-color: var(--gx-secondary-600, #2563EB); box-shadow: 0 0 0 3px rgba(37, 99, 235, .1); }
    .bi-mx-showing { font-size: .75rem; font-weight: 600; color: #B45309; white-space: nowrap; }
    /* Word, not glyph. Everything on this screen that carries a label keeps it
       — see the note in the Filters dialog footer. The height is pinned to the
       search box beside it so the bar reads as one row rather than two
       controls that happen to be adjacent. */
    .bi-mx-filterbtn {
        display: inline-flex; align-items: center; justify-content: center;
        height: 30px; padding: 0 14px; font-size: .82rem; font-weight: 500;
        border-radius: 4px; white-space: nowrap;
        border: 1px solid #E5E7EB; background: #fff; color: #111827;
    }
    .bi-mx-filterbtn:hover { background: #F6F9FE; border-color: #CBD5E1; }
    /* A filter that is on must say so — otherwise a short matrix reads as a
       short PO rather than a filtered one. */
    .bi-mx-filterbtn.is-on {
        border-color: var(--gx-secondary-600, #2563EB);
        background: var(--gx-secondary-bg, #DBEAFE);
        color: var(--gx-secondary-700, #1D4ED8);
    }

    /* The grid scrolls inside its own box in both directions, so the page
       itself never grows a second horizontal scrollbar.

       The height is tied to the window rather than to a flat 52vh, so that
       however many rows are loaded the sticky Indent Authorization block below
       still fits on the same screen. The 320px is this page's chrome — the
       sticky bottom measures ~209px, the rest is the section header and the
       bar above the grid. (The reference subtracts 290px; it has no "Select
       Purchase Order" card above its grid, so the figure differs but the rule
       is the same one.) max() keeps a usable grid on a short laptop. */
    .bi-mx-card {
        border: 1px solid #E5E7EB; border-radius: 6px; background: #fff;
        overflow: auto; max-height: max(220px, calc(100vh - 320px));
        box-shadow: 0 1px 2px rgba(0, 0, 0, .01);
        scrollbar-width: thin; scrollbar-color: #CBD5E1 #F1F1F1;
    }
    /* Thin (6px) rather than the app's usual 10px: on a grid that scrolls both
       ways, two 10px bars eat a column's worth of width. */
    .bi-mx-card::-webkit-scrollbar { width: 6px; height: 6px; }
    .bi-mx-card::-webkit-scrollbar-track { background: #F1F1F1; }
    .bi-mx-card::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
    .bi-mx-card::-webkit-scrollbar-thumb:hover { background: #94A3B8; }

    .bi-mx-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1750px; }
    /* Sticky, so column identity survives a scroll through 60 rows.
       Sentence case in near-black rather than small grey caps: at 19 columns
       the header row is the only thing telling the four near-identical
       quantity cells apart, so it has to be the most legible row on screen. */
    .bi-mx-table thead th {
        position: sticky; top: 0; z-index: 3;
        background: #F9FAFB; text-align: left; white-space: nowrap;
        padding: .375rem .5rem; font-size: .8rem; font-weight: 600;
        color: #111827; border-bottom: 2px solid #E5E7EB;
    }
    .bi-mx-h-bulk { color: var(--bi-bulk) !important; }
    .bi-mx-h-sample { color: var(--bi-sample) !important; }
    .bi-mx-h-liability { color: var(--bi-liability) !important; }
    .bi-mx-h-dead { color: var(--bi-dead) !important; }

    .bi-mx-table tbody td {
        padding: .25rem .5rem; font-size: .82rem; color: #1F2937;
        border-bottom: 1px solid #E5E7EB; white-space: nowrap; vertical-align: middle;
    }
    .bi-mx-table tbody tr:hover td { background: #F3F4F6; }
    /* Scoped through tbody so they outweigh the base cell rule above — a bare
       .bi-mx-art would lose its colour to `.bi-mx-table tbody td`. */
    .bi-mx-table tbody td.bi-mx-strong { font-weight: 600; color: var(--gx-primary, #0F172A); }
    .bi-mx-table tbody td.bi-mx-style { font-weight: 600; }
    .bi-mx-table tbody td.bi-mx-art { color: #DB2777; font-weight: 500; }
    .bi-mx-table tbody td.bi-mx-avail { font-variant-numeric: tabular-nums; font-weight: 600; color: #047857; }
    /* Descriptions are the one field long enough to need wrapping; capped so
       one long description cannot push the quantity columns off screen. */
    .bi-mx-table tbody td.bi-mx-desc {
        white-space: normal; max-width: 280px; min-width: 180px;
        line-height: 1.3; font-size: .78rem;
    }

    /* Quantity inputs. Colour is the label here — the column header carries
       the words, so the four cells are told apart by tint. */
    .bi-mx-qty { padding: .2rem .35rem !important; text-align: center; }
    .bi-qty-input {
        width: 75px; height: 24px; padding: .1rem .25rem;
        text-align: center; font-size: .82rem; font-weight: 600;
        font-variant-numeric: tabular-nums;
        border: 1px solid #CBD5E1; border-radius: 4px; background: #fff;
        outline: none; transition: border-color .15s ease, box-shadow .15s ease;
    }
    .bi-qty-input::placeholder { font-weight: 400; color: #CBD5E1; }
    .bi-qty-bulk { color: var(--bi-bulk); border-color: #A7F3D0; }
    .bi-qty-sample { color: var(--bi-sample); border-color: #BFDBFE; }
    .bi-qty-liability { color: var(--bi-liability); border-color: #FDE68A; }
    .bi-qty-dead { color: var(--bi-dead); border-color: #FECACA; }
    .bi-qty-bulk:focus { border-color: var(--bi-bulk); box-shadow: 0 0 0 3px rgba(5, 150, 105, .15); }
    .bi-qty-sample:focus { border-color: var(--bi-sample); box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); }
    .bi-qty-liability:focus { border-color: var(--bi-liability); box-shadow: 0 0 0 3px rgba(217, 119, 6, .15); }
    .bi-qty-dead:focus { border-color: var(--bi-dead); box-shadow: 0 0 0 3px rgba(220, 38, 38, .15); }

    /* Running total + the n/4 fill badge, and the over-limit message that
       appears under them when the row exceeds its balance. */
    .bi-mx-table tbody td.bi-mx-total { text-align: right; white-space: nowrap; }
    .bi-item-total-num { font-weight: 700; font-variant-numeric: tabular-nums; }
    .bi-mx-filled {
        display: inline-block; margin-left: .35rem; padding: 0 .3rem;
        border-radius: 5px; font-size: .65rem; font-weight: 700;
        background: #F1F5F9; color: #64748B;
    }
    .bi-mx-filled.bg-primary-subtle { background: var(--gx-secondary-bg, #DBEAFE); color: var(--gx-secondary-700, #1D4ED8); }
    .bi-mx-table tbody td.bi-mx-total { min-width: 118px; }
    .bi-mx-over { display: flex; align-items: center; justify-content: flex-end; gap: .4rem; margin-top: .15rem; }
    /* The sentence is written by checkOver() and read out by screen readers,
       but on a row it would wrap to one word per line and triple the row
       height. The banner under the matrix already names every offending item
       and its figures, so on screen the row states the same thing in the space
       it has: a red tint, a red total, and the one-click way out. */
    .bi-mx-over .bi-item-error {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
    }
    .bi-mx-over .btn { --bs-btn-padding-y: 0; --bs-btn-padding-x: .35rem; --bs-btn-font-size: .68rem; white-space: nowrap; }

    /* An over-limit row is tinted end to end: on a 19-column grid a red badge
       in one cell is easy to scroll straight past. */
    .bi-mx-table tbody tr.is-over td { background: #FEF2F2; }
    .bi-mx-table tbody tr.is-over:hover td { background: #FEE2E2; }
    .bi-mx-table tbody tr.is-over .bi-mx-total { color: var(--bi-dead); }
    /* Hidden by the search or the column filter. Display-only: the row keeps
       its inputs and still submits, which the footer count states outright. */
    .bi-mx-hidden { display: none; }

    /* Include / exclude column. Narrow and sticky-free: it is the first thing
       read on a row, so it sits at the left edge where the eye already is. */
    .bi-mx-table th.bi-mx-check,
    .bi-mx-table td.bi-mx-check { width: 34px; padding-left: .55rem; padding-right: .2rem; text-align: center; }
    .bi-mx-check .form-check-input { margin: 0; cursor: pointer; }
    .bi-mx-check .form-check-input:disabled { cursor: not-allowed; }
    /* Editing corrects exactly one row, so there is nothing to include or leave
       out — the column goes rather than showing a lone dead checkbox. */
    .bi-edit-mode .bi-mx-check { display: none; }

    /* A row left out of the issue. Its inputs are disabled, so what is typed in
       them is kept but not submitted — the row is dimmed rather than removed,
       because getting it back is one click and nothing was thrown away.

       Deliberately different from .bi-mx-hidden above: hidden-by-filter rows
       still save what is in them, excluded rows do not, and two states that
       opposite must never look alike. */
    .bi-mx-table tbody tr.bi-mx-excluded td { opacity: .45; background: #FCFCFD; }
    .bi-mx-table tbody tr.bi-mx-excluded:hover td { opacity: .7; background: #F3F4F6; }
    .bi-mx-table tbody tr.bi-mx-excluded .bi-mx-check { opacity: 1; }

    /* Nothing on hand. The server refuses these outright, so they are shown for
       reference and can never be ticked in. */
    .bi-mx-table tbody tr.bi-mx-nostock td.bi-mx-avail { color: var(--bi-dead); font-weight: 700; }

    /* Column filter modal — one labelled box per identity column. */
    .bi-fx-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: .6rem; }
    .bi-fx-card { border: 1px solid #E9EDF3; border-radius: 10px; background: #FBFCFE; padding: .5rem .6rem; }
    .bi-fx-card.bi-fx-wide { grid-column: span 2; }
    /* One label style across the screen: this, .bi-sum-label on the PO summary
       and .bi-indent-grid .form-label are deliberately identical. */
    .bi-fx-card label {
        display: block; margin-bottom: .2rem;
        font-size: .65rem; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: #64748B;
    }
    .bi-fx-select {
        width: 100%; height: 30px; padding: 0 .4rem; font-size: .78rem;
        border: 1px solid #CBD5E1; border-radius: 6px; background: #fff; color: #1F2937;
    }
    .bi-fx-select:focus { outline: none; border-color: var(--gx-secondary-600, #2563EB); box-shadow: 0 0 0 3px rgba(37, 99, 235, .1); }
    @media (max-width: 575.98px) { .bi-fx-card.bi-fx-wide { grid-column: span 1; } }

    /* The dialog's two halves. They work differently — the top one asks the
       server which booking, the bottom one hides rows already on screen — so
       they are separated rather than run together as one wall of dropdowns. */
    .bi-fx-sect + .bi-fx-sect { margin-top: 1rem; padding-top: .9rem; border-top: 1px solid #E9EDF3; }
    .bi-fx-sect-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .55rem; }
    .bi-fx-sect-title {
        font-size: .72rem; font-weight: 700; letter-spacing: .05em;
        text-transform: uppercase; color: var(--gx-primary, #0F172A);
    }
    .bi-fx-req, .bi-fx-opt {
        font-size: .62rem; font-weight: 700; letter-spacing: .03em;
        padding: 1px 6px; border-radius: 5px; text-transform: uppercase;
    }
    .bi-fx-req { background: var(--gx-secondary-bg, #DBEAFE); color: var(--gx-secondary-700, #1D4ED8); }
    .bi-fx-opt { background: #F1F5F9; color: #64748B; text-transform: none; letter-spacing: 0; font-weight: 600; }

    /* The matching-bookings list, in flow inside the dialog. Capped so a long
       list cannot push the footer buttons off the bottom. */
    .bi-po-results { max-height: 40vh; overflow-y: auto; scrollbar-width: thin; }
    .bi-po-results::-webkit-scrollbar { width: 6px; }
    .bi-po-results::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
    .bi-po-hint {
        padding: .5rem .75rem; border-bottom: 1px solid #E9EDF3;
        font-size: .75rem; font-weight: 600; color: #64748B;
    }

    /* --- TomSelect, wearing this dialog's clothes -------------------------
       The library ships a Bootstrap 5 theme, which gets the shape right but
       not the density: these boxes sit eleven-to-a-grid and have to match the
       32px .bi-fx-select they replaced, not a full-height form control. */
    .bi-fx-card .ts-wrapper { margin: 0; }
    .bi-fx-card .ts-control {
        min-height: 30px; padding: 2px .4rem; font-size: .78rem;
        border: 1px solid #CBD5E1; border-radius: 6px; background: #fff; color: #1F2937;
    }
    .bi-fx-card .ts-wrapper.focus .ts-control {
        border-color: var(--gx-secondary-600, #2563EB);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
    }
    .bi-fx-card .ts-control input { font-size: .78rem; }
    .bi-fx-card .ts-control > .item { line-height: 1.5; }
    .bi-fx-dropdown, .bi-fx-card .ts-dropdown { font-size: .78rem; }
    .bi-fx-card .ts-dropdown .option { padding: .3rem .5rem; }
    .bi-fx-card .ts-dropdown .active { background: var(--gx-secondary-bg, #DBEAFE); color: var(--gx-secondary-700, #1D4ED8); }
    /* A field whose values are still being fetched. The control stays usable —
       typing into it just has nothing to match yet. */
    .bi-fx-card .ts-wrapper.bi-fx-loading .ts-control::after {
        content: ''; position: absolute; right: 26px; top: 50%; width: 11px; height: 11px;
        margin-top: -6px; border: 2px solid #CBD5E1; border-top-color: var(--gx-secondary-600, #2563EB);
        border-radius: 50%; animation: bi-fx-spin .6s linear infinite;
    }
    @keyframes bi-fx-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) {
        .bi-fx-card .ts-wrapper.bi-fx-loading .ts-control::after { animation: none; }
    }

    /* The loaded PO, above the grid. One line rather than the four-column card
       it was: it is settled context now, not the outcome of a step, and the
       matrix below needs the vertical room. The tint, the label and the value
       type all still come from #biSummaryGrid in the shared styles — only the
       layout is restated here. */
    .bi-po-summary {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        padding: .55rem .75rem; margin-bottom: .7rem;
    }
    .bi-sum-facts {
        display: flex; align-items: center; gap: 1.4rem; flex-wrap: wrap;
        flex: 1 1 auto; min-width: 0;
    }
    /* Same caption as .bi-fx-card label and .bi-indent-grid .form-label. The
       shared styles partial sets these through #biSummaryGrid, so matching it
       takes the ID too rather than an !important. */
    #biSummaryGrid.bi-po-summary .bi-sum-label {
        font-size: .65rem; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: #64748B; margin-bottom: .2rem;
    }
    #biSummaryGrid.bi-po-summary .bi-sum-value { font-size: .82rem; line-height: 1.3; }

    /* Cancel and Save sit side by side, so they are the same height. Without
       this they differ by 3px — .btn-link and .btn-primary pick up different
       padding once neither is an icon square. */
    .bi-bar-actions .btn { height: 38px; display: inline-flex; align-items: center; }

    /* Indent Authorization header — six compact fields on one line. */
    .bi-indent-card { padding: .7rem .9rem !important; }
    .bi-indent-title {
        display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
        font-size: .8125rem; font-weight: 700; color: var(--gx-primary, #0F172A); margin-bottom: .5rem;
    }
    .bi-indent-note { font-size: .7rem; font-weight: 500; color: #94A3B8; }
    /* Same small-caps label as the filter dialog's fields and the PO summary's
       facts. Three blocks on one screen were each captioning their inputs a
       different way, which read as three screens stitched together. */
    .bi-indent-grid .form-label {
        font-size: .65rem; font-weight: 700; letter-spacing: .04em;
        text-transform: uppercase; color: #64748B; margin-bottom: .2rem;
    }
    /* The required marker stays sentence-case red rather than becoming a
       shouted asterisk in the run of capitals. */
    .bi-indent-grid .form-label .text-danger { letter-spacing: 0; }
    .bi-indent-grid .form-control,
    .bi-indent-grid .form-select { height: 32px; font-size: .78rem; padding: .2rem .5rem; }
    .bi-indent-grid textarea.form-control { height: 32px; min-height: 32px; resize: vertical; }
    .bi-indent-grid .bi-remarks-count { font-size: .6rem; }

    /* Inside the slide-in panel there is no room for the PO-level identity
       columns — they repeat the chip already above the matrix — so the panel
       shows the material line and its quantities only. Same markup, and the
       full-page shell keeps every column. */
    .bi-offcanvas .bi-mx-po,
    .bi-offcanvas .bi-mx-desc { display: none; }
    .bi-offcanvas .bi-mx-table { min-width: 0; }
    .bi-offcanvas .bi-mx-card { max-height: 40vh; }
