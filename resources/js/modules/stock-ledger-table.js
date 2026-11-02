/**
 * General Stock — Stock Report: live search, filters and paging.
 *
 * A progressive enhancement over the plain GET form that is still in the page.
 * The server action is unchanged; it is asked for `?partial=1` and answers with
 * the same Blade fragment the full page renders inline, which is then swapped
 * in. Nothing about the report is formatted here — doing that would put a
 * second copy of a twenty-column business report inside a JS file.
 *
 * Follows the pattern already used by bulk-issue-table.js: debounced input, a
 * ticket to drop out-of-order responses, delegated pagination, replaceState.
 */
export function initStockLedgerTable() {
    const container = document.getElementById('stockLedgerTable');
    const form = document.querySelector('[data-ledger-filters]');
    if (!container || !form) return;

    const spinner = document.querySelector('[data-ledger-spinner]');
    const closingEl = document.querySelector('[data-ledger-closing]');
    const actionAlert = document.querySelector('[data-ledger-action-alert]');
    const actionCount = document.querySelector('[data-ledger-action-count]');
    const searchInput = form.querySelector('[name="search"]');

    // querySelectorAll, not querySelector: the item count and the month label
    // each appear more than once now that the pre-count notice quotes them, and
    // updating only the first would leave the notice describing the month the
    // page opened with.
    const countEls = document.querySelectorAll('[data-ledger-count]');
    const monthLabelEls = document.querySelectorAll('[data-ledger-month-label]');

    // The pre-count notice: shown only for a period that predates some item's
    // stock count, so it has to be toggled as well as refilled.
    const uncountedAlert = document.querySelector('[data-ledger-uncounted-alert]');
    const uncountedCount = document.querySelector('[data-ledger-uncounted-count]');
    const countedCount = document.querySelector('[data-ledger-counted-count]');

    // The Download PDF / Download Excel buttons. Their hrefs are rendered by the
    // server from the query at page load, so on a screen the JS has since
    // re-filtered they point at the wrong report — the bug where a table showing
    // August downloaded as September. The base is captured ONCE here, before any
    // sync rewrites it, so repeated syncs cannot compound a query onto a query.
    const exportLinks = Array.from(document.querySelectorAll('[data-ledger-export]'))
        .map((el) => ({ el, base: el.href.split('?')[0] }));

    // Bumped on every request; a response whose ticket is stale is thrown away.
    // Without it a slow reply to "co" can land after the reply to "cotton" and
    // leave the table showing results for a term no longer in the box.
    let ticket = 0;
    let timer = null;
    let page = 1;

    /**
     * The filter form is the single source of truth for the query, so a control
     * added to the form later is picked up here with no JS change. Blank values
     * are dropped to keep the URL readable.
     */
    function buildQuery(extra) {
        const params = new URLSearchParams();

        new FormData(form).forEach((value, key) => {
            if (value === '' || value === null) return;
            // The unticked checkbox posts its hidden "0" companion; that is the
            // default, so it does not belong in the URL.
            if (key === 'include_inactive' && value === '0') return;
            params.set(key, value);
        });

        if (page > 1) params.set('page', page);
        Object.keys(extra || {}).forEach((k) => params.set(k, extra[k]));

        return params;
    }

    /**
     * Point the two download buttons at the report currently on screen.
     *
     * `page` is dropped: the exports always cover every row in the period, not
     * the page being looked at, so carrying it would only put a misleading
     * number in the URL. `partial` is never in buildQuery() to begin with —
     * it is added per-request for the fetch alone.
     */
    function syncExportLinks() {
        const params = buildQuery();
        params.delete('page');

        const qs = params.toString();
        exportLinks.forEach(({ el, base }) => {
            el.href = qs ? base + '?' + qs : base;
        });
    }

    function syncUrl() {
        const qs = buildQuery().toString();
        // replaceState, not push: typing a six-letter search term would
        // otherwise stack six entries the Back button has to walk out of.
        // The URL still reflects the current state, so a refresh or a copied
        // link reopens the same filtered report.
        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
        // The address bar and the download buttons describe the same report, so
        // they move together. Anything that desyncs them is the original bug.
        syncExportLinks();
    }

    /** Figures that sit outside the swapped fragment, carried in with it. */
    function applyMeta() {
        const el = container.querySelector('[data-ledger-meta]');
        if (!el) return;

        let meta;
        try {
            meta = JSON.parse(el.getAttribute('data-ledger-meta'));
        } catch (e) {
            return;
        }

        countEls.forEach((node) => {
            node.textContent = Number(meta.items).toLocaleString();
        });
        monthLabelEls.forEach((node) => {
            node.textContent = meta.month_label;
        });
        if (closingEl) closingEl.textContent = meta.closing_value;

        if (uncountedAlert) {
            uncountedAlert.classList.toggle('d-none', !meta.not_counted);
            if (uncountedCount) {
                uncountedCount.textContent = Number(meta.not_counted).toLocaleString();
            }
            if (countedCount) {
                countedCount.textContent = Number(meta.counted_items).toLocaleString();
            }
        }

        document.querySelectorAll('[data-ledger-tile]').forEach((tile) => {
            const key = tile.getAttribute('data-ledger-tile');
            if (meta[key] === undefined) return;
            // The status counts arrive as numbers and are grouped here; the two
            // money/quantity cards arrive already formatted by the server, so
            // they are written through untouched rather than run back through
            // toLocaleString and losing their decimals.
            tile.textContent = typeof meta[key] === 'number'
                ? meta[key].toLocaleString()
                : meta[key];
        });

        // Stock by Unit: the card and its View all list, rendered by the server.
        const unitsEl = document.querySelector('[data-ledger-units]');
        const unitsAllEl = document.querySelector('[data-ledger-units-all]');
        if (unitsEl && meta.units_html !== undefined) unitsEl.innerHTML = meta.units_html;
        if (unitsAllEl && meta.units_all_html !== undefined) unitsAllEl.innerHTML = meta.units_all_html;

        if (actionAlert) {
            actionAlert.classList.toggle('d-none', !meta.attention);
            if (actionCount) actionCount.textContent = Number(meta.attention).toLocaleString();
        }
    }

    function load() {
        const mine = ++ticket;
        if (spinner) spinner.classList.remove('d-none');
        container.setAttribute('aria-busy', 'true');
        // Dimmed rather than blanked: the previous figures stay readable while
        // the next set is fetched, which is far less jarring mid-keystroke than
        // the table disappearing on every letter.
        container.classList.add('is-loading');

        const url = form.action + '?' + buildQuery({ partial: 1 }).toString();

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.text() : Promise.reject(r.status)))
            .then((html) => {
                if (mine !== ticket) return;
                container.innerHTML = html;
                applyMeta();
                syncUrl();
            })
            .catch(() => {
                if (mine !== ticket) return;
                // The Filter button is still a working plain submit, so the way
                // out of a failed fetch is the control the user already has.
                container.innerHTML =
                    '<div class="alert alert-warning mb-0">Could not update the report. Press Filter to reload the page.</div>';
            })
            .finally(() => {
                if (mine !== ticket) return;
                if (spinner) spinner.classList.add('d-none');
                container.removeAttribute('aria-busy');
                container.classList.remove('is-loading');
            });
    }

    /** Any filter change starts the report again from page 1. */
    function reload() {
        page = 1;
        load();
    }

    // Typing: debounced, so a fetch goes out once the user pauses rather than
    // once per keystroke.
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(reload, 350);
        });
        // A type="search" field's own clear "x" fires `search`, not `input`.
        searchInput.addEventListener('search', reload);
    }

    // Month, Category, Status and Include inactive: no reason to wait, the
    // change is already deliberate.
    form.addEventListener('change', (e) => {
        if (e.target === searchInput) return;
        clearTimeout(timer);
        reload();
    });

    // With JS on, the Filter button just runs the same refresh — pressing Enter
    // in the search box should not reload a page the fetch can update.
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        clearTimeout(timer);
        reload();
    });

    // Pagination is delegated to the container, so links that arrive with a
    // swapped fragment work without re-binding anything.
    //
    // .gx-pagination, not Bootstrap's .pagination: this project sets its own
    // paginator view through Paginator::defaultView('pagination.gx'), and that
    // view renders .gx-pagination / .gx-page. A .pagination selector matches
    // nothing here and the click falls through to a full page load.
    container.addEventListener('click', (e) => {
        const link = e.target.closest('.gx-pagination a');
        if (!link) return;
        e.preventDefault();

        const next = new URL(link.href, window.location.origin).searchParams.get('page');
        if (!next) return;

        page = Number(next) || 1;
        load();
        // The table is a tall pane; after a page change the user expects to be
        // reading from its first row, not wherever the last page left them.
        container.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    // Back/Forward past a replaceState still needs to show what the URL says.
    window.addEventListener('popstate', () => window.location.reload());
}
