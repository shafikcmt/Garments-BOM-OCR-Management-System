{{--
    Stock Report — presentation, this screen only.

    Every rule here is scoped under .gx-ledger, which nothing but
    store.stock.ledger carries. _stock-ui.blade.php is included by fourteen
    screens and .gx-stock-table styles nine of them, so the base rules there are
    deliberately left alone: this file overrides on top of them rather than
    editing them, and no other General Store page can shift as a side effect.

    The one idea behind the whole thing: a store manager opens this report to
    find what needs ordering. On a normal month most rows are fine, so colour is
    spent only on the ones that are not, and everything else is set to be read
    past quickly.
--}}
<style>
    /* --- Column hierarchy -------------------------------------------------
     * Three tiers, expressed as weight and contrast only — the column order is
     * the reference sheet's and does not move. Bootstrap's .text-muted and the
     * base cell colour sit between these two extremes and are left as they are.
     */

    /* Primary: what the report is looked up for. Item name, the stock figure it
       resolves to, and the answer to "do I order this?". */
    .gx-ledger .gx-stock-table > tbody > tr > td.gx-ledger-primary {
        color: #0f172a;
        font-weight: 650;
    }

    /* Quiet: real data, but nobody scans a thousand rows for a lead time. Kept
       legible and pushed back so the eye travels over it. */
    .gx-ledger .gx-stock-table > tbody > tr > td.gx-ledger-quiet {
        color: #94a3b8;
        font-size: .76rem;
    }

    /* Column headers follow their columns, so the hierarchy is visible in the
       header band too rather than starting at the first row. */
    .gx-ledger .gx-stock-table > thead > tr > th.gx-ledger-primary { color: #334155; }
    .gx-ledger .gx-stock-table > thead > tr > th.gx-ledger-quiet { color: #a3aec0; font-weight: 600; }

    /* --- Status: the signature -------------------------------------------
     * Inverted from where this started. "Ok" used to carry a green pill on
     * roughly every row, which made the common case the loudest thing on the
     * screen and left the handful of rows that need buying to compete with it.
     * Now Ok is nearly silent and only an exception is allowed colour.
     */

    /* Ok: a dot and a word, no pill, no colour. */
    .gx-ledger .gx-ledger-ok {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        font-size: .74rem;
        font-weight: 600;
        color: #94a3b8;
    }
    .gx-ledger .gx-ledger-ok::before {
        content: "";
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #cbd5e1;
        flex: 0 0 5px;
    }

    /* The spine. A row that needs action is marked on its left edge, so the
       exceptions are findable by running an eye down the side of the table
       instead of reading the Order? column row by row. 3px is enough to catch
       at a glance and narrow enough not to shift the column grid. */
    .gx-ledger .gx-stock-table > tbody > tr > td:first-child,
    /* The header and total rows carry the same transparent 3px so the first
       column stays in line with the body — without it the spine widens the
       body cells only and the Order? heading sits 3px off its own column. */
    .gx-ledger .gx-stock-table > thead > tr > th:first-child,
    .gx-ledger .gx-stock-table > tfoot > tr > td:first-child {
        border-left: 3px solid transparent;
    }
    .gx-ledger .gx-stock-table > tbody > tr.gx-ledger-flag-low > td:first-child { border-left-color: #f59e0b; }
    .gx-ledger .gx-stock-table > tbody > tr.gx-ledger-flag-place_order > td:first-child { border-left-color: #ef4444; }
    .gx-ledger .gx-stock-table > tbody > tr.gx-ledger-flag-out > td:first-child { border-left-color: #b91c1c; }

    /* The same spine on the purchase-action banner, which counts exactly the
       rows the spine marks. Two places, one visual language. */
    .gx-ledger .gx-ledger-action-alert {
        border-left: 3px solid #f59e0b !important;
        border-top-left-radius: 4px !important;
        border-bottom-left-radius: 4px !important;
    }

    /* An out-of-stock row keeps its red wash, but a lighter one: with the spine
       carrying the warning the full tint no longer has to do it alone, and at
       the old strength a run of them flooded the table. */
    .gx-ledger .gx-stock-table > tbody > tr.table-danger > td { background: #fef6f6; }
    .gx-ledger .gx-stock-table > tbody > tr.table-danger:hover > td { background: #fdeaea; }

    /* --- Total row --------------------------------------------------------
     * Was #f8fafc — the same tint as the sticky header, so the summary read as
     * one more band of table. Darker ground, a heavier rule to sit behind, and
     * ink at full strength.
     */
    .gx-ledger .gx-stock-table > tfoot > tr > td {
        background: #f1f5f9;
        border-top: 2px solid #cbd5e1;
        padding-top: .8rem;
        padding-bottom: .8rem;
        font-weight: 750;
        color: #0f172a;
    }
    /* Says what the Total Qty figure spans when it spans more than one unit of
       measure. Set small and quiet: it qualifies the number above it, it does
       not compete with it. */
    .gx-ledger .gx-ledger-total-caveat {
        font-size: .62rem;
        font-weight: 600;
        letter-spacing: .02em;
        color: #94a3b8;
        line-height: 1.2;
        margin-top: .1rem;
    }
    /* "Total" is a label, not a figure. The base rule sets it quiet on purpose
       and the darker ink above would otherwise override it into competing with
       the numbers it introduces. */
    .gx-ledger .gx-stock-table > tfoot > tr > td.gx-stock-total-label { color: #64748b; }

    /* --- Pinned header ----------------------------------------------------
     * _stock-ui pins the header inside the scroll pane, and below 641px of
     * viewport height it switches the pinning off entirely to avoid leaving a
     * sliver of a pane. That threshold is the machine this report is actually
     * read on: a 1366x768 laptop, less the browser chrome and the taskbar,
     * lands right around 640px — so the guard was disabling the header on
     * exactly the screens that needed it most.
     *
     * The pane is sized off the viewport instead, with a floor, so a short
     * screen gives up pane height rather than giving up the header. Overridden
     * from here rather than edited in _stock-ui, which thirteen other screens
     * share.
     */
    /* What made the sticky pane below inert until now.
     *
     * main.content carries `overflow-x: hidden` on desktop. A box cannot clip
     * one axis and leave the other visible, so overflow-y computes to `auto`
     * and .content becomes a scroll container. position:sticky resolves against
     * the nearest scroll container, so the pane was sticking to .content — and
     * .content never scrolls, the document does. The offset therefore never
     * engaged and the pane rode the page straight up behind the nav.
     *
     * `clip` clips without making a scroll container, so sticky falls through
     * to the viewport where it belongs, and the page still cannot scroll
     * sideways. Applied through :has() so it lands on this screen only and
     * .content keeps its existing behaviour everywhere else. Where :has() is
     * unsupported the rule is skipped and the page behaves as it does today.
     */
    body:has(.gx-ledger) main.content {
        overflow-x: clip;
        overflow-y: visible;
    }

    .gx-ledger .gx-stock-scroll {
        /* The pane pins its own header at the pane's top edge, so the pane's top
           edge is the thing that has to stay on screen. .header is fixed at the
           viewport top, 60px tall and only 76% opaque, so a pane allowed to
           scroll past it puts the pinned row behind translucent chrome — which
           is exactly the "header slides under the top bar" this fixes. Sticky
           here stops the pane rising above the nav in the first place. */
        position: sticky;
        top: calc(var(--app-header, 60px) + .5rem);
        /* Leaves room under the pane for the pager, which follows it in flow. */
        max-height: calc(100vh - var(--app-header, 60px) - 7rem);
        min-height: 320px;
    }
    .gx-ledger .gx-stock-scroll > .gx-stock-table > thead > tr > th {
        position: sticky;
        top: 0;
        /* Above the row backgrounds and nothing else. .header sits at 1015, so
           the global nav always paints over this — which is the correct order;
           the bug was never stacking, it was where the pane could travel. */
        z-index: 2;
        /* Reads as a header floating over the rows rather than a row that
           happens to be at the top. The inset keeps the hairline the base rule
           draws; the outer shadow only appears where rows pass beneath. */
        box-shadow: inset 0 -1px 0 #e2e8f0, 0 6px 10px -8px rgba(15, 23, 42, .35);
    }
    @media (max-height: 640px) {
        .gx-ledger .gx-stock-scroll {
            max-height: calc(100vh - var(--app-header, 60px) - 4rem);
            min-height: 240px;
        }
        /* Explicitly restated: this is the case _stock-ui turns sticky off in,
           and it is the case that needs it. */
        .gx-ledger .gx-stock-scroll > .gx-stock-table > thead > tr > th { position: sticky; }
    }

    /* --- Pagination -------------------------------------------------------
     * The project's own control (components.css), refined rather than
     * replaced: same rounded blue shape, minus the gradient the house rules ban.
     */
    .gx-ledger .pagination { gap: 4px; margin-bottom: 0; }
    .gx-ledger .page-link {
        /* Flex-centred at a fixed height so every button — digits, Prev, Next,
           the ellipsis — is the same size and sits on one line with the count
           text beside it. */
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 .55rem;
        box-shadow: none;
        transition: background-color .14s ease, border-color .14s ease, color .14s ease;
    }
    .gx-ledger .page-link:hover { background: #eff6ff; border-color: #bfdbfe; }
    .gx-ledger .page-item.active .page-link {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
        /* A ring instead of a shadow: it reads as "you are here" without
           lifting the button off the page. Tight, so it marks the button
           rather than haloing it. */
        box-shadow: 0 0 0 2px rgba(37, 99, 235, .18);
    }
    .gx-ledger .page-item.disabled .page-link {
        color: #cbd5e1;
        border-color: #eef2f7;
        background: #fff;
    }
    .gx-ledger .page-link:focus-visible {
        outline: 2px solid #2563eb;
        outline-offset: 2px;
    }
    /* Digits stop the buttons resizing as the page number gains a digit. */
    .gx-ledger .pagination { font-variant-numeric: tabular-nums; }

    /* Count text and buttons on one line, tight. The count is set in tabular
       figures so it does not shuffle as the page changes. */
    .gx-ledger .gx-ledger-pager { margin-top: .6rem; }
    .gx-ledger .gx-ledger-pager-count {
        font-variant-numeric: tabular-nums;
        color: #64748b;
    }
    .gx-ledger .gx-ledger-pager-count strong { color: #0f172a; font-weight: 700; }
    /* The pager is the last thing in the card now, so it carries the bottom
       gap the legend used to sit in. Without this the buttons sit hard against
       the card edge. */
    .gx-ledger .gx-ledger-pager { margin-bottom: .15rem; }

    /* --- Figure cards -----------------------------------------------------
     * Total Stock Qty and Closing Stock Value. Same tile shape as the four
     * status counts, but they are read rather than clicked, so they carry no
     * hover lift and no link affordance. The value is set a step smaller than
     * a count: "5,740.00" is a longer string than "61" and at the count's size
     * it wraps out of its card.
     */
    .gx-ledger .gx-ledger-figure { min-width: 0; }
    .gx-ledger .gx-ledger-figure-value {
        font-size: 1.25rem;
        overflow-wrap: anywhere;
    }
    .gx-ledger .gx-ledger-figure-hint {
        font-size: .62rem;
        font-weight: 600;
        color: #94a3b8;
        line-height: 1.25;
        margin-top: .15rem;
    }
</style>
