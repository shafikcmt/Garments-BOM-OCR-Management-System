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
    const countEl = document.querySelector('[data-ledger-count]');
    const closingEl = document.querySelector('[data-ledger-closing]');
    const monthLabelEl = document.querySelector('[data-ledger-month-label]');
    const actionAlert = document.querySelector('[data-ledger-action-alert]');
    const actionCount = document.querySelector('[data-ledger-action-count]');
    const searchInput = form.querySelector('[name="search"]');

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

    function syncUrl() {
        const qs = buildQuery().toString();
        // replaceState, not push: typing a six-letter search term would
        // otherwise stack six entries the Back button has to walk out of.
        // The URL still reflects the current state, so a refresh or a copied
        // link reopens the same filtered report.
        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
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

        if (countEl) countEl.textContent = Number(meta.items).toLocaleString();
        if (closingEl) closingEl.textContent = meta.closing_value;
        if (monthLabelEl) monthLabelEl.textContent = meta.month_label;

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
    container.addEventListener('click', (e) => {
        const link = e.target.closest('.pagination a');
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
