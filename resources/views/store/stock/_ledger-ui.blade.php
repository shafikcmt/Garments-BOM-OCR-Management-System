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
    /* "Total" is a label, not a figure. The base rule sets it quiet on purpose
       and the darker ink above would otherwise override it into competing with
       the numbers it introduces. */
    .gx-ledger .gx-stock-table > tfoot > tr > td.gx-stock-total-label { color: #64748b; }

    /* --- Pagination -------------------------------------------------------
     * The project's own control (components.css), refined rather than
     * replaced: same rounded blue shape, minus the gradient the house rules ban.
     */
    .gx-ledger .pagination { gap: 4px; margin-bottom: 0; }
    .gx-ledger .page-link {
        min-width: 34px;
        text-align: center;
        padding: .35rem .6rem;
        box-shadow: none;
        transition: background-color .14s ease, border-color .14s ease, color .14s ease;
    }
    .gx-ledger .page-link:hover { background: #eff6ff; border-color: #bfdbfe; }
    .gx-ledger .page-item.active .page-link {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
        /* A ring instead of a shadow: it reads as "you are here" without
           lifting the button off the page. */
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .16);
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

    /* --- Legend -----------------------------------------------------------
     * The safety-stock formula was loose text pressed against the pager. It is
     * reference material, consulted occasionally, so it gets a quiet panel of
     * its own and clear air above it.
     */
    .gx-ledger .gx-ledger-legend {
        margin-top: 1.25rem;
        padding: .85rem 1rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }
    .gx-ledger .gx-ledger-legend-title {
        font-size: .66rem;
        font-weight: 750;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #94a3b8;
        margin-bottom: .3rem;
    }
    .gx-ledger .gx-ledger-legend .gx-stock-help { color: #64748b; }

    /* The pager sits on its own line above the legend now, so the count line
       and the buttons have the row to themselves. */
    .gx-ledger .gx-ledger-pager { margin-top: .9rem; }
</style>
