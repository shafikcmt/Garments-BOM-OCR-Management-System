{{--
    Shared look for every General Stock screen — Consumable Stock Report, Items,
    Receiving, Purchase Setup, Issues and Issue Setup.

    These screens were built one at a time, so the same intent had drifted into
    three spellings: card padding was p-3 on one screen and p-4 on the rest,
    table headers came in three different font sizes, and the same card radius
    was repeated as an inline style sixteen times. Defining it once here means a
    future screen inherits the look instead of re-deriving it.

    Presentation only. No screen's markup structure, fields or behaviour depend
    on anything in this file.
--}}
<style>
    /* --- Cards -----------------------------------------------------------
     * Replaces the repeated inline style="border-radius:var(--gx-radius)".
     */
    /* A defined edge and one shallow shadow, rather than a wide soft glow. A
       card should read as a sheet of paper on the page, not as something
       floating above it — the deeper shadow made stacked cards look like they
       sat at different depths.
     *
     * components.css sets border, radius and shadow on `.card:not(.booking-card)`
     * with !important, app-wide. Scoped here to the General Stock section and
     * matched in weight so this wins inside it while every other screen keeps
     * the card it has.
     */
    .gx-stock-scope .card.gx-stock-card {
        border: 1px solid #e5e9f0 !important;
        border-radius: 14px !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04) !important;
        background: #fff;
    }
    .gx-stock-card > .gx-stock-card-body { padding: 1.5rem; }

    /* Card heading + optional inline toolbar (filter form, count badge). */
    .gx-stock-card-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        margin-bottom: 1.25rem;
    }
    .gx-stock-card-head h5,
    .gx-stock-card-head h6 { margin: 0; font-weight: 700; }

    /* Card title, whether it sits in a card-head toolbar or on its own above a
       form. Was drifting between Bootstrap's default h5 weight (500) and the
       700 the toolbar variant sets, so two cards side by side did not match. */
    .gx-stock-card-body > h5,
    .gx-stock-card-head h5 {
        font-size: 1.02rem;
        font-weight: 700;
        letter-spacing: -.01em;
        color: #0f172a;
    }
    /* Secondary block title inside a card, e.g. "Import Items". */
    .gx-stock-card-body > h6 { font-size: .84rem; font-weight: 700; color: #0f172a; }

    /* Separator between two blocks of one card (form above, import below). */
    .gx-stock-scope hr { border-top: 1px solid #e2e8f0; opacity: 1; }

    /* --- Summary tiles ---------------------------------------------------
     * The Stock Report's four counts. Each tile is a link that re-runs the
     * report with its own status filter, so it needs a visible hover and a
     * visible keyboard focus — as a bare card it had neither.
     */
    .gx-stock-tile {
        display: block;
        text-decoration: none;
        border: 1px solid transparent;
        transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
    }
    /* Not scoped under .gx-stock-tile: the body is the tile's PRESENTATION and
       .gx-stock-tile is its link BEHAVIOUR. A read-only figure — the monthly
       report's counts — needs the first without the second, and tying them
       together left those cards with an unstyled body. */
    .gx-stock-tile-body {
        display: flex;
        align-items: center;
        gap: .85rem;
        padding: 1rem 1.15rem;
    }
    .gx-stock-tile-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        border-radius: 13px;
        font-size: 18px;
    }
    /* Label above, number below: the number is what the eye should land on,
       so the label is set small and quiet rather than at body size. */
    .gx-stock-tile-label {
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #64748b;
        line-height: 1.2;
        margin-bottom: .15rem;
    }
    .gx-stock-tile-value {
        font-size: 1.6rem;
        font-weight: 750;
        line-height: 1.05;
        color: #0f172a;
        font-variant-numeric: tabular-nums;
    }
    .gx-stock-tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 2px 4px rgba(15, 23, 42, .05), 0 14px 30px rgba(15, 23, 42, .09);
    }
    .gx-stock-tile:focus-visible {
        outline: 2px solid #2563eb;
        outline-offset: 2px;
    }
    /* Which count the report is currently filtered to. Without this the four
       tiles look identical after a click and nothing says the list below is
       narrowed. */
    .gx-stock-tile.is-active {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
    }

    /* A native <select> does not inherit the 13px that components.css gives
     * .form-control, so it keeps the browser default and renders ~43px against
     * an input's 36px. In a filter row aligned to the bottom, that pushed each
     * select's label 7px above the label beside it. Matching the two lines the
     * row up — measured at 36px for both afterwards.
     */
    .gx-stock-scope .form-select {
        font-size: 13px;
        line-height: 1.5;
        padding-top: .375rem;
        padding-bottom: .375rem;
        min-height: 36px;
    }

    /* --- Filter bar ------------------------------------------------------
     * A full-width filter card above a table.
     */
    .gx-stock-filter { align-items: end; }
    /* TomSelect builds its own wrapper, which sizes slightly differently from
       the plain controls beside it. */
    .gx-stock-filter .ts-wrapper.form-select { min-height: 36px; }
    /* The submit button sat 1.5px short of the fields it lines up with. */
    .gx-stock-filter .btn { min-height: 36px; }
    /* The Filter / Clear pair that closes every filter row. Was written four
       different ways — `d-grid`, `flex-fill`, `flex-grow-1`, and one screen with
       no Clear at all — so the buttons landed at a different width and a
       different offset on each screen. One class now owns the pair: Filter takes
       the room, Clear takes only what its label needs. */
    .gx-stock-filter-actions {
        display: flex;
        align-items: end;
        gap: .5rem;
    }
    .gx-stock-filter-actions .btn { flex: 1 1 auto; justify-content: center; }
    .gx-stock-filter-actions .btn-outline-secondary { flex: 0 0 auto; }

    /* A checkbox has no label above it, so it needs to be pushed down to sit
       on the same baseline as the inputs beside it. Was an `mt-4` guess. */
    .gx-stock-filter .form-check {
        display: flex;
        align-items: center;
        gap: .1rem;
        min-height: 38px;
        margin-bottom: 0;
    }
    .gx-stock-filter .form-check-label {
        font-size: .78rem;
        color: #475569;
        cursor: pointer;
    }

    /* Section heading inside a card (e.g. "Items" above the line grid). */
    .gx-stock-subhead {
        font-size: .82rem;
        font-weight: 750;
        letter-spacing: .01em;
        color: #334155;
        margin-bottom: .15rem;
    }

    /* --- Hero icon -------------------------------------------------------
     * Was the same 5-property inline style on all six screens.
     */
    .gx-stock-hero-icon {
        width: 46px;
        height: 46px;
        border-radius: 15px;
        font-size: 20px;
    }

    /* --- Forms -----------------------------------------------------------
     * The app's components.css already owns .form-control / .form-select.
     * These only add the pieces that differed screen to screen.
     */
    /* The screens carry a mix of `form-label`, `form-label fw-semibold` and
       `form-label fw-semibold small`. Bootstrap sets fw-* with !important, so
       without the same force here two labels on one card render at different
       weights. Declaring the winner once means a leftover utility class on any
       General Stock screen can no longer pull a label out of line. */
    .gx-stock-scope .form-label {
        font-size: .72rem !important;
        font-weight: 700 !important;
        letter-spacing: .015em;
        color: #334155;
        margin-bottom: .35rem;
        line-height: 1.35;
    }
    /* One asterisk style for every required field. */
    .gx-stock-scope .form-label .text-danger { font-weight: 800; margin-left: .1rem; }

    /* Helper text under a field or form — was 4 different inline font sizes. */
    .gx-stock-help {
        font-size: .75rem;
        line-height: 1.6;
        color: #64748b;
        margin-bottom: 0;
    }

    /* A file input renders shorter than a text input at the same padding, so an
       upload row sat visibly above the buttons beside it. */
    .gx-stock-scope input[type="file"].form-control { padding-top: .45rem; padding-bottom: .45rem; }

    /* --- Form controls ---------------------------------------------------
     * One border colour, one focus treatment and one placeholder tone for
     * every field in the section. Bootstrap's default focus is a wide blue
     * glow that spills over neighbouring fields in a dense row; a tight ring
     * marks the field without smearing into the ones beside it.
     */
    .gx-stock-scope .form-control,
    .gx-stock-scope .form-select,
    .gx-stock-scope .ts-wrapper.form-control,
    .gx-stock-scope .ts-wrapper.form-select {
        border-color: #e2e8f0;
        color: #0f172a;
        transition: border-color .12s ease, box-shadow .12s ease;
    }
    .gx-stock-scope .form-control::placeholder { color: #94a3b8; opacity: 1; }

    .gx-stock-scope .form-control:focus,
    .gx-stock-scope .form-select:focus,
    .gx-stock-scope .ts-wrapper.focus,
    .gx-stock-scope .ts-wrapper.dropdown-active {
        border-color: #94a3b8 !important;
        box-shadow: 0 0 0 3px rgba(15, 23, 42, .07) !important;
    }

    /* Keyboard focus on a button gets the same ring, so tabbing through a
       toolbar is visible without the browser's default outline. */
    .gx-stock-scope .btn:focus-visible {
        outline: 0;
        box-shadow: 0 0 0 3px rgba(15, 23, 42, .12);
    }

    /* Auto-filled, not editable: reads as output rather than an empty input.
       Muted rather than emphasised — these are the figures the form fetched,
       not something the requester is being asked for. */
    .gx-stock-readonly {
        background-color: #f8fafc !important;
        border-color: #eef2f7 !important;
        color: #64748b !important;
        font-weight: 500;
        cursor: default;
    }

    /* --- Tables ----------------------------------------------------------
     * Generalises the treatment proven on the Record Issue line grid so every
     * General Stock table scans the same way.
     */
    .gx-stock-table {
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 0;

        /* Carried over from the app-wide table rule in components.css, which
           .gx-stock-table is now listed out of. Everything else that rule gave
           this table was the thing being opted out of; these two are not.
           --bs-table-bg neutralises Bootstrap's own row painting, without which
           a `table-danger` row re-tints itself over the backgrounds set below,
           and the min-width is what makes a wide table scroll inside its
           .table-responsive on a narrow screen instead of crushing its columns
           to fit. */
        --bs-table-bg: transparent;
        --bs-table-hover-bg: transparent;
        min-width: 760px;
    }

    .gx-stock-table > thead > tr > th {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 750;
        color: #64748b;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        border-top: 0;
        padding: .6rem .55rem;
        white-space: nowrap;
        vertical-align: middle;
    }
    .gx-stock-table > thead > tr > th:first-child { border-top-left-radius: 10px; }
    .gx-stock-table > thead > tr > th:last-child { border-top-right-radius: 10px; }

    /* --- Pinned column headers -------------------------------------------
     * Opt-in, on the long reports only. A .table-responsive wrapper is already
     * a scroll container, so a header pinned to the page would never move
     * relative to it — the wrapper has to own the vertical scroll too, which
     * is what the height cap does. The header then stays put while a hundred
     * rows of a twenty-column sheet go past, so "Consumption" still has a name
     * at row eighty.
     */
    .gx-stock-scroll {
        max-height: 72vh;
        overflow: auto;
    }
    .gx-stock-scroll > .gx-stock-table > thead > tr > th {
        position: sticky;
        top: 0;
        /* Above the row backgrounds, which are painted per-cell. */
        z-index: 2;
        /* The header background is already opaque (#f8fafc); without it the
           rows would read straight through the pinned row. */
    }
    /* While a live filter is fetching. The old figures stay legible and simply
       recede — blanking the table on every keystroke reads as the report
       breaking. Pointer events go off so a pagination link cannot be clicked
       against rows that are about to be replaced. */
    [data-ledger-table].is-loading,
    [data-list-table].is-loading {
        opacity: .55;
        transition: opacity .15s ease-in;
        pointer-events: none;
    }
    @media (prefers-reduced-motion: reduce) {
        [data-ledger-table].is-loading,
        [data-list-table].is-loading { transition: none; }
    }

    /* Short viewports — a laptop with the browser bar and the page header
       taking their share — would be left scrolling a sliver of a pane. Below
       that, let the table run at its natural height and scroll with the page. */
    @media (max-height: 640px) {
        .gx-stock-scroll { max-height: none; }
        .gx-stock-scroll > .gx-stock-table > thead > tr > th { position: static; }
    }

    .gx-stock-table > tbody > tr > td {
        padding: .6rem .55rem;
        border-bottom: 1px solid #eef2f7;
        border-top: 0;
        vertical-align: middle;
        font-size: .84rem;
    }
    /* Flat rows separated by a hairline, with the hover doing the work of
       telling you which row you are on. The old alternating tint competed with
       that hover — two different "this row is special" signals at once — and on
       a wide table it read as banding rather than as structure. */
    .gx-stock-table > tbody > tr > td { background: #fff; }
    .gx-stock-table > tbody > tr:hover > td { background: #f8fafc; }
    .gx-stock-table > tbody > tr:last-child > td { border-bottom: 0; }

    /* An out-of-stock row is marked `table-danger`, but Bootstrap sets that
       through --bs-table-bg, which the striping above paints straight over —
       so every second out-of-stock row was losing its red. Declared after the
       stripe, at the same specificity, so the warning always wins. */
    .gx-stock-table > tbody > tr.table-danger > td { background: #fef2f2; }
    .gx-stock-table > tbody > tr.table-danger:hover > td { background: #fee2e2; }

    /* Totals row. Sits apart from the data with a heavier top rule. */
    .gx-stock-table > tfoot > tr > td {
        padding: .7rem .55rem;
        background: #f8fafc;
        border-top: 2px solid #e2e8f0;
        border-bottom: 0;
        font-size: .84rem;
        font-weight: 700;
        color: #0f172a;
        vertical-align: middle;
    }
    .gx-stock-table > tfoot > tr > td:first-child { border-bottom-left-radius: 10px; }
    .gx-stock-table > tfoot > tr > td:last-child { border-bottom-right-radius: 10px; }
    /* The word "Total" itself is a label, not a figure — set like the column
       headers so it does not compete with the numbers beside it. */
    .gx-stock-table > tfoot .gx-stock-total-label,
    .gx-line-table > tfoot .gx-stock-total-label {
        font-size: .68rem;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #64748b;
    }

    /* Secondary detail inside a cell — dates, specification, remarks. Was an
       inline font-size repeated on seven cells of the Stock Report. */
    .gx-stock-table .gx-stock-micro { font-size: .72rem; line-height: 1.45; color: #94a3b8; }

    /* Third line of an item cell — the specification. Was an inline font-size
       and max-width on the one screen that shows it. */
    .gx-stock-table .gx-stock-spec {
        font-size: .72rem;
        line-height: 1.45;
        color: #94a3b8;
        max-width: 320px;
    }

    /* Numeric columns line up on the decimal point, not ragged. */
    .gx-stock-table .num,
    .gx-stock-table .text-end { font-variant-numeric: tabular-nums; }

    /* Action column: padded away from the field beside it so a fast click on
       the last input cannot land on a destructive button. */
    .gx-stock-table td.gx-stock-actions,
    .gx-stock-table th.gx-stock-actions { padding-left: 1.25rem; white-space: nowrap; }

    /* Spacing between the actions in a cell.
     *
     * Was three sibling-margin rules — .btn + .btn, .btn + form, form + form —
     * which is a list of the shapes an action was expected to take, and a list
     * like that is only ever one entry short. A DELETE action is a whole <form>
     * (it needs a CSRF token and a method override), so an Edit button beside
     * one is a button next to a BLOCK element: the two stacked vertically and
     * the row grew to two button-heights. The margin rule meant to separate
     * them never applied, because it was written for a form the screen had
     * remembered to mark `d-inline` and this one had not.
     *
     * Both halves are fixed here rather than on each screen. The form is laid
     * out inline whether or not anybody remembers the class, and the gap is a
     * universal adjacent-sibling rule that does not care what shape the actions
     * are. Deliberately NOT display:flex on the cell — that would take the td
     * out of table layout, and this rule covers every General Stock table.
     *
     * The vertical margins are zeroed and the horizontal ones deliberately
     * LEFT ALONE: `margin: 0` here would out-specify the sibling rule below —
     * two classes and two elements against two classes and one — and silently
     * cancel the gap it exists to create. */
    .gx-stock-table td.gx-stock-actions > form {
        display: inline-block;
        margin-top: 0;
        margin-bottom: 0;
        vertical-align: middle;
    }
    .gx-stock-table td.gx-stock-actions > * + * { margin-left: .4rem; }

    /* Row actions — View / Edit / Delete — are pills, so they read as a set and
       never as the table's own borders. Every screen was writing this as
       `rounded-pill px-3` on each button, which is nine copies of one decision
       and one more thing to forget on the next screen. Specificity has to clear
       the icon-button opt-out further down this file, which sets a 9px radius
       with !important; the extra class on the left does that. */
    .content .gx-stock-scope td.gx-stock-actions :is(a, button).btn:has(> i.bi),
    .content .gx-stock-scope td.gx-stock-actions :is(a, button).btn,
    /* The Remove button on an editable item line. It lives in .gx-line-action,
       not .gx-stock-actions, so it was the one row action in the section still
       drawing itself as a square. */
    .content .gx-stock-scope td.gx-line-action :is(a, button).btn:has(> i.bi),
    .content .gx-stock-scope td.gx-line-action :is(a, button).btn {
        border-radius: 999px !important;
        padding-left: .85rem !important;
        padding-right: .85rem !important;
    }

    /* Row actions on the two HISTORY tables — Issue History and the item lines
     * inside a receiving. Same pill, drawn tighter.
     *
     * Those two are the densest tables in the section: nine and seven columns
     * of .small text, twenty-five rows to a page. A pill padded for the Item
     * Master's roomier rows is the widest thing on the row there, and two of
     * them per row set the row height.
     *
     * PADDING AND GAP ONLY. Type size is not touched, because the section pins
     * it deliberately: an icon button is 13px with a 14px icon, declared
     * !important further down this file so every icon button in the app matches
     * whatever Bootstrap size class it happens to carry. Shrinking the label
     * here would mean out-shouting that with more !important, which is how a
     * design system stops being one. The row is tightened by the space around
     * the words, not by making the words smaller.
     *
     * An opt-in class on those cells, not a change to the shared pill: Item
     * Master, Issue Setup and Purchase Setup are not dense and are left alone. */
    .content .gx-stock-scope td.gx-row-actions :is(a, button).btn:has(> i.bi),
    .content .gx-stock-scope td.gx-row-actions :is(a, button).btn {
        padding-left: .7rem !important;
        padding-right: .7rem !important;
        padding-top: .2rem !important;
        padding-bottom: .2rem !important;
    }
    /* Tighter than the .4rem the roomier tables use, in proportion. */
    .gx-stock-table td.gx-row-actions > * + * { margin-left: .3rem; }

    /* --- Item line grid --------------------------------------------------
     * The editable table inside Record Receiving and Record Issue. Both screens
     * carried their own copy of these rules and the two had already drifted —
     * one had a row hover, the other did not. Declared once here; the pieces
     * that genuinely differ (Record Issue needs a fixed layout, Receiving has an
     * expandable detail row) stay on their own screen.
     */
    .gx-line-scroll { overflow-x: auto; }

    .gx-line-table { border-collapse: separate; border-spacing: 0; }

    .gx-line-table > thead > tr > th {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 750;
        color: #64748b;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: .6rem .55rem;
        white-space: nowrap;
    }
    .gx-line-table > thead > tr > th:first-child { border-top-left-radius: 10px; }
    .gx-line-table > thead > tr > th:last-child { border-top-right-radius: 10px; }

    .gx-line-table > tbody > tr > td {
        padding: .55rem;
        border-bottom: 1px solid #eef2f7;
        vertical-align: top;
    }
    /* Alternating tint so a long list stays scannable, and a hover so the line
       being typed into is obvious. */
    .gx-line-table > tbody > tr:nth-child(even) > td { background: #fcfdff; }
    .gx-line-table > tbody > tr:hover > td { background: #f5f9ff; }

    .gx-line-no {
        font-size: .78rem;
        font-weight: 700;
        color: #94a3b8;
        padding-top: .95rem !important;
    }
    /* Remove sits away from the field beside it, so a fast click on the last
       input cannot land on it. */
    .gx-line-action { padding-left: 1.25rem !important; }

    .gx-line-table .js-line-alert:empty { display: none; }
    .gx-line-table .js-line-alert .badge { font-weight: 650; }

    /* Totals row on an item-line grid. The footer treatment was written only for
       .gx-stock-table, so Record Receiving's "Total Value" row inherited nothing
       at all — no band, no rule, and .gx-stock-total-label below did not reach
       it either. It read as one more editable line rather than as the sum of
       the lines above it. */
    .gx-line-table > tfoot > tr > td {
        padding: .6rem .55rem;
        background: #f8fafc;
        border-top: 2px solid #e2e8f0;
        border-bottom: 0;
        vertical-align: middle;
    }
    .gx-line-table > tfoot > tr > td:first-child { border-bottom-left-radius: 10px; }
    .gx-line-table > tfoot > tr > td:last-child { border-bottom-right-radius: 10px; }

    /* --- Auto-filled marker ----------------------------------------------
     * A tag beside the label of a field the form fills in for you. The muted
     * .gx-stock-readonly fill says "not editable" quietly; on a dense row of
     * eight fields, quietly was not enough — people still clicked into Month
     * and GRN No and waited for a cursor.
     */
    .gx-stock-auto {
        display: inline-block;
        margin-left: .3rem;
        padding: .05em .4em;
        border-radius: 5px;
        background: #f1f5f9;
        color: #94a3b8;
        font-size: .6rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        vertical-align: middle;
    }

    /* --- Sideways scrolling ----------------------------------------------
     * The Stock Report is 21 columns wide, so it always scrolls. The browser
     * default scrollbar is thin, grey and only appears mid-scroll on Windows,
     * which is why the report reads as "cut off" rather than "scrollable".
     * A permanently visible track says there is more to the right.
     */
    .gx-stock-scope .table-responsive {
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 #f1f5f9;
        padding-bottom: 2px;
    }
    .gx-stock-scope .table-responsive::-webkit-scrollbar { height: 10px; }
    .gx-stock-scope .table-responsive::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 999px;
    }
    .gx-stock-scope .table-responsive::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 999px;
        border: 2px solid #f1f5f9;
    }
    .gx-stock-scope .table-responsive::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

    /* --- Tabs ------------------------------------------------------------
     * Issue Setup switches its three masters with pills. Bootstrap's default
     * is a filled blue block that outweighs the card titles under it.
     */
    .gx-stock-scope .nav-pills { gap: .4rem; }
    .gx-stock-scope .nav-pills .nav-link {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: .45rem .9rem;
        font-size: .82rem;
        font-weight: 650;
        color: #475569;
        background: #fff;
    }
    .gx-stock-scope .nav-pills .nav-link:hover { background: #f8fafc; color: #0f172a; }
    .gx-stock-scope .nav-pills .nav-link.active {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }

    /* --- Buttons keep their labels ---------------------------------------
     * components.css carries a project-wide rule (".content :is(a,button).btn
     * :has(> i.bi)") that collapses ANY button containing a Bootstrap icon into
     * a 38px square with font-size:0, and hides any child <span>. Every button
     * in this section is written as icon + text, but that rule renders them all
     * icon-only — which is exactly what CLAUDE.md and docs/04_UI_UX_RULES.md
     * forbid. The markup was never the problem; this was.
     *
     * Opting General Stock back out restores the label the button was written
     * with. Prefixed with .content so it outranks the original rule outright
     * rather than relying on stylesheet order.
     */
    .content .gx-stock-scope :is(a, button).btn:has(> i.bi) {
        width: auto !important;
        min-width: 0 !important;
        height: auto !important;
        padding: .375rem .75rem !important;
        font-size: 13px !important;
        line-height: 1.5 !important;
        border-radius: 11px !important;
        gap: 0 !important;
        overflow: visible !important;
        box-shadow: none;
    }
    .content .gx-stock-scope :is(a, button).btn.btn-sm:has(> i.bi) {
        width: auto !important;
        min-width: 0 !important;
        height: auto !important;
        padding: .25rem .6rem !important;
        border-radius: 9px !important;
    }
    /* Full-width buttons (modal actions, the setup forms) must stay full width
       — "auto" above would otherwise shrink them to their text. */
    .content .gx-stock-scope :is(a, button).btn.w-100:has(> i.bi) { width: 100% !important; }

    .content .gx-stock-scope :is(a, button).btn:has(> i.bi) > i.bi {
        margin: 0 .35rem 0 0 !important;
        font-size: 1em !important;
        line-height: inherit !important;
    }
    /* The original rule hides child spans outright. */
    .content .gx-stock-scope :is(a, button).btn:has(> i.bi) > span { display: inline !important; }

    /* --- Screen toolbar --------------------------------------------------
     * A full-width bar above a list: title, count, then the actions. Used by
     * the Item Master, where the primary action is a button rather than a
     * permanently open form.
     */
    .gx-stock-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .6rem;
    }
    .gx-stock-toolbar h5 { margin: 0; }
    .gx-stock-toolbar .gx-stock-toolbar-gap { flex: 1 1 auto; }
    @media (max-width: 575.98px) {
        /* The two actions share the width rather than wrapping one per line. */
        .gx-stock-toolbar .gx-stock-toolbar-gap { flex-basis: 100%; }
        .gx-stock-toolbar .btn { flex: 1 1 0; justify-content: center; }
    }

    /* --- Stock health ----------------------------------------------------
     * A slim bar under a stock figure showing where it sits against the
     * re-order level. Drawn only when a re-order level exists — a bar against
     * an invented denominator would say less than no bar at all.
     */
    .gx-stock-health {
        height: 4px;
        border-radius: 999px;
        background: #eef2f7;
        overflow: hidden;
        margin-top: .25rem;
        min-width: 54px;
    }
    .gx-stock-health > i {
        display: block;
        height: 100%;
        border-radius: 999px;
        /* Falls back to the neutral tone when no status class is set, so a bar
           is never drawn with no colour at all. */
        background: #94a3b8;
    }
    /* The fill colour was three hex values written inline on every row of the
       Item Master, repeating a decision the status badge beside it had already
       made. Named here instead, so the bar and the badge cannot drift. */
    .gx-stock-health--danger > i  { background: #dc2626; }
    .gx-stock-health--warning > i { background: #d97706; }
    .gx-stock-health--success > i { background: #16a34a; }

    /* --- Filter chips ----------------------------------------------------
     * The counts above a list — "12 Out of Stock", "8 Place Order" — are
     * links that re-run the list filtered to themselves. Drawn as plain
     * badges they looked exactly like the static status pills in the rows
     * below, so nothing said they could be clicked, and nothing said which
     * one was currently applied.
     */
    .gx-stock-chip {
        text-decoration: none;
        border: 1px solid transparent;
        transition: box-shadow .12s ease, border-color .12s ease;
    }
    .gx-stock-chip:hover { box-shadow: 0 1px 2px rgba(15, 23, 42, .10); border-color: currentColor; }
    .gx-stock-chip:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    /* The filter this list is narrowed to. */
    .gx-stock-chip[aria-current] {
        border-color: currentColor;
        box-shadow: 0 0 0 3px rgba(15, 23, 42, .07);
    }

    /* Two pills in one cell — a status and, rarely, "Inactive" — sit side by
       side and wrap only when they have to, rather than the second always
       taking a line of its own and making every row in the table taller. */
    .gx-stock-pills {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .25rem;
    }

    /* --- Grouped form --------------------------------------------------
     * Twelve fields in one flat run is a wall. These label four groups so a
     * store user reads "what the item is", then "how much is on the shelf".
     */
    .gx-stock-fieldset + .gx-stock-fieldset { margin-top: 1.35rem; }
    .gx-stock-fieldset-label {
        display: flex;
        align-items: center;
        gap: .6rem;
        font-size: .66rem;
        font-weight: 750;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: #94a3b8;
        margin-bottom: .6rem;
    }
    .gx-stock-fieldset-label::after {
        content: "";
        flex: 1 1 auto;
        height: 1px;
        background: #eef2f7;
    }

    /* --- Segmented tab strip ---------------------------------------------
     * Master Setup switches five master lists. Rendered as one control with
     * five states — a grey trough with the active tab raised on white — rather
     * than five separate buttons, which is what a row of pills reads as.
     */
    .gx-seg {
        display: inline-flex;
        align-items: center;
        gap: 0;
        background: #e9eef5;
        border-radius: 12px;
        padding: 3px;
        max-width: 100%;
        overflow-x: auto;
        scrollbar-width: none;
    }
    .gx-seg::-webkit-scrollbar { display: none; }

    .gx-seg .nav-link {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        white-space: nowrap;
        padding: .4rem .85rem;
        border: 1px solid transparent;
        border-radius: 9px;
        background: transparent;
        color: #5a6b85;
        font-size: .8rem;
        font-weight: 650;
        transition: background .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .gx-seg .nav-link:hover { color: #0f172a; }
    .gx-seg .nav-link.active {
        background: #fff;
        color: #0f172a;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .10), 0 1px 1px rgba(15, 23, 42, .06);
    }
    .gx-seg .nav-link:focus-visible { outline: 2px solid #2563eb; outline-offset: 1px; }

    /* Row count inside a tab. */
    .gx-seg .gx-seg-count {
        font-size: .66rem;
        font-weight: 750;
        padding: .1em .45em;
        border-radius: 6px;
        background: #e2e8f0;
        color: #475569;
    }
    .gx-seg .nav-link.active .gx-seg-count { background: #eff6ff; color: #2563eb; }

    /* One plain sentence saying what the selected list drives. */
    .gx-seg-note {
        font-size: .76rem;
        color: #64748b;
        margin: .55rem 0 1.25rem .15rem;
    }

    /* --- Empty state -----------------------------------------------------
     * Was a bare sentence in a <td> on every screen.
     */
    .gx-stock-empty { text-align: center; padding: 2.75rem 1rem !important; background: #fff !important; }
    .gx-stock-empty .gx-stock-empty-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        border-radius: 14px;
        background: #f1f5f9;
        color: #94a3b8;
        font-size: 20px;
        margin-bottom: .6rem;
    }
    .gx-stock-empty .gx-stock-empty-title { font-weight: 700; color: #475569; font-size: .88rem; }
    .gx-stock-empty .gx-stock-empty-hint { font-size: .76rem; color: #94a3b8; margin-top: .15rem; }

    /* --- Status badges ---------------------------------------------------
     * One weight and shape for every status pill across the section.
     */
    .gx-stock-scope .badge {
        font-weight: 650;
        letter-spacing: .01em;
        padding: .3em .65em;
        /* Fully rounded: a status is a pill, and it stops the badge reading as
           a small button next to the real buttons in an actions column. */
        border-radius: 999px;
    }

    /* --- Label / value pairs ---------------------------------------------
     * The read-only detail block on a record page. A quiet label above or
     * beside a solid value, so the page scans as data rather than as prose.
     */
    .gx-stock-dl { margin-bottom: 0; }
    .gx-stock-dl > dt {
        font-size: .72rem;
        font-weight: 600;
        color: #94a3b8;
        padding: .4rem 0;
    }
    .gx-stock-dl > dd {
        font-size: .84rem;
        font-weight: 600;
        color: #0f172a;
        padding: .4rem 0;
        margin-bottom: 0;
    }
    /* A hairline between pairs, but never under the last one. */
    .gx-stock-dl > dt,
    .gx-stock-dl > dd { border-bottom: 1px solid #f1f5f9; }
    .gx-stock-dl > dt:nth-last-of-type(1),
    .gx-stock-dl > dd:nth-last-of-type(1) { border-bottom: 0; }

    /* --- Correction modals -----------------------------------------------
     * The Edit dialogs on Issue History and Purchase History. Both mix fields
     * that can be changed with fields that identify the record and cannot, and
     * the distinction has to survive a glance — a correction screen is used
     * rarely, under mild pressure, by someone who has just noticed a mistake.
     *
     * A greyed-out <input> was not enough. Disabled inputs are how a broken
     * form looks, so a locked field styled as one invites a support call
     * ("the item box won't let me type"). These are not inputs at all: a flat
     * slate panel with a lock and the word LOCKED, which reads as a stated fact
     * about the record rather than a control that is failing to respond.
     */
    .gx-edit-locked {
        border: 1px solid #eef2f7;
        background: #f8fafc;
        border-radius: 10px;
        padding: .5rem .7rem;
        display: flex;
        align-items: center;
        gap: .5rem;
        min-height: 38px;
    }
    .gx-edit-locked > .bi {
        color: #94a3b8;
        font-size: .85rem;
        flex: 0 0 auto;
    }
    .gx-edit-locked-value {
        color: #475569;
        font-weight: 600;
        font-size: .875rem;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    /* Same shape as .gx-stock-auto, different word: that one means "we filled
     * this in", this one means "this cannot change". */
    .gx-lock-tag {
        display: inline-block;
        margin-left: .3rem;
        padding: .05em .4em;
        border-radius: 5px;
        background: #f1f5f9;
        color: #94a3b8;
        font-size: .6rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        vertical-align: middle;
    }

    /* Marks a field whose change is written to every line of the delivery, not
     * just the one being edited. Amber rather than grey: it is not a warning,
     * but it IS a consequence beyond the row the user opened. */
    .gx-edit-scope {
        display: inline-block;
        margin-left: .3rem;
        padding: .05em .4em;
        border-radius: 5px;
        background: #fef3c7;
        color: #b45309;
        font-size: .6rem;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        vertical-align: middle;
    }

    /* The refusal, shown inside the dialog the user was typing in rather than
     * only as a banner at the top of a page they have been redirected to and
     * cannot see behind the reopened modal.
     *
     * Deliberately not .alert-danger. A refused correction is a normal, expected
     * answer — "there is not enough stock for that" — and a red slab overstates
     * it. Amber, a rule down the side, and the number they need in the sentence.
     */
    .gx-edit-alert {
        border: 1px solid #fde68a;
        border-left: 3px solid #f59e0b;
        background: #fffbeb;
        border-radius: 10px;
        padding: .6rem .75rem;
        display: flex;
        gap: .55rem;
        align-items: flex-start;
    }
    .gx-edit-alert > .bi {
        color: #d97706;
        font-size: .95rem;
        line-height: 1.35;
        flex: 0 0 auto;
    }
    .gx-edit-alert-title {
        font-weight: 700;
        font-size: .8rem;
        color: #92400e;
        letter-spacing: .01em;
    }
    .gx-edit-alert-body {
        font-size: .8rem;
        color: #78350f;
        line-height: 1.45;
    }
    .gx-edit-alert-body > span { display: block; }

    /* The same point the Edit dialog's scope chip makes, said once above a
     * delivery's item list so it is read BEFORE anything is clicked rather than
     * after. Deliberately the dialog's own chip and the dialog's own muted
     * grey, not a second visual language for one idea. */
    .gx-line-scope {
        display: flex;
        align-items: baseline;
        gap: .5rem;
        font-size: .75rem;
        color: #94a3b8;
        line-height: 1.45;
    }
    .gx-line-scope > .gx-edit-scope { margin-left: 0; flex: 0 0 auto; }

    /* A quiet note under a field, for the sentence that explains a lock. */
    .gx-edit-hint {
        font-size: .75rem;
        color: #94a3b8;
        margin-top: .3rem;
        line-height: 1.4;
    }

    /* --- Responsive ------------------------------------------------------ */
    @media (max-width: 991.98px) {
        .gx-stock-card > .gx-stock-card-body { padding: 1.15rem; }
        .gx-stock-card-head { margin-bottom: 1rem; }
        /* Filter toolbars stack instead of squeezing their inputs to nothing. */
        .gx-stock-card-head form { width: 100%; }
        .gx-stock-card-head form .form-control,
        .gx-stock-card-head form .form-select { width: auto !important; flex: 1 1 auto; min-width: 0; }
    }
    @media (max-width: 575.98px) {
        .gx-stock-card > .gx-stock-card-body { padding: .9rem; }
        .gx-stock-table > tbody > tr > td { font-size: .8rem; }
    }
</style>
