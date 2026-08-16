/**
 * Live search / filtering for a General Store list screen.
 *
 * Same shape as stock-ledger-table.js, which this is generalised from: the
 * server action is asked for `?partial=1` and answers with the same Blade
 * fragment the full page renders inline, which is then swapped in. Nothing is
 * formatted here — that would put a second copy of the screen's presentation
 * inside a JS file.
 *
 * Driven entirely by data attributes, so a screen opts in from its Blade
 * without adding JS:
 *
 *   [data-list-table]     the container swapped wholesale (required)
 *   [data-list-filters]   the GET form whose controls drive it (required)
 *   [data-list-spinner]   shown while a request is in flight
 *   [data-list-count]     total-row count, refreshed from the fragment's meta
 *   [data-list-chip]      a status chip; its [data-list-chip-count] is
 *                         refreshed and the chip hidden while the count is 0
 *
 * The fragment carries a [data-list-meta] JSON blob for the figures that sit
 * outside the swapped region.
 *
 * Whether this stays shared or a screen needs its own is decided per screen —
 * Receiving's grouped rows are the next test of it.
 */
export function initStockListTable() {
    document.querySelectorAll('[data-list-table]').forEach(setup);
}

function setup(container) {
    // Scoped to the container's own page region, so two lists on one screen
    // would not fight over each other's controls.
    const scope = container.closest('.gx-stock-scope') || document;
    const form = scope.querySelector('[data-list-filters]');
    if (!form) return;

    const spinner = scope.querySelector('[data-list-spinner]');
    const countEl = scope.querySelector('[data-list-count]');
    const searchInput = form.querySelector('input[name="search"]');

    // Bumped on every request; a response whose ticket is stale is discarded.
    // Without it a slow reply to "co" can land after the reply to "cotton" and
    // leave the table showing results for a term no longer in the box.
    let ticket = 0;
    let timer = null;
    let page = 1;

    /**
     * The filter form is the single source of truth for the query, so a control
     * added to the form later is picked up with no change here. Blank values
     * are dropped to keep the URL readable.
     */
    function buildQuery(extra) {
        const params = new URLSearchParams();

        new FormData(form).forEach((value, key) => {
            if (value === '' || value === null) return;
            params.set(key, value);
        });

        if (page > 1) params.set('page', page);
        Object.keys(extra || {}).forEach((k) => params.set(k, extra[k]));

        return params;
    }

    function syncUrl() {
        const qs = buildQuery().toString();
        // replaceState, not push: typing a six-letter term would otherwise
        // stack six entries the Back button has to walk out of. The URL still
        // reflects the current state, so a refresh or a copied link reopens the
        // same filtered list.
        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    }

    /** Figures that sit outside the swapped fragment, carried in with it. */
    function applyMeta() {
        const el = container.querySelector('[data-list-meta]');
        if (!el) return;

        let meta;
        try {
            meta = JSON.parse(el.getAttribute('data-list-meta'));
        } catch (e) {
            return;
        }

        if (countEl && meta.total !== undefined) {
            countEl.textContent = Number(meta.total).toLocaleString();
        }

        const chips = meta.chips || {};
        scope.querySelectorAll('[data-list-chip]').forEach((chip) => {
            const n = chips[chip.getAttribute('data-list-chip')];
            if (n === undefined) return;
            chip.classList.toggle('d-none', !n);
            const out = chip.querySelector('[data-list-chip-count]');
            if (out) out.textContent = Number(n).toLocaleString();
        });
    }

    function load() {
        const mine = ++ticket;
        if (spinner) spinner.classList.remove('d-none');
        container.setAttribute('aria-busy', 'true');
        // Dimmed rather than blanked: the previous rows stay readable while the
        // next set is fetched, which is far less jarring mid-keystroke than the
        // table disappearing on every letter.
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
                // Re-run any enhancement the swapped markup needs.
                if (window.gxInitSearchable) window.gxInitSearchable(container);
            })
            .catch(() => {
                if (mine !== ticket) return;
                // The Filter button is still a working plain submit, so the way
                // out of a failed fetch is the control the user already has.
                container.innerHTML =
                    '<div class="alert alert-warning mb-0">Could not update the list. Press Filter to reload the page.</div>';
            })
            .finally(() => {
                if (mine !== ticket) return;
                if (spinner) spinner.classList.add('d-none');
                container.removeAttribute('aria-busy');
                container.classList.remove('is-loading');
            });
    }

    /** Any filter change starts the list again from page 1. */
    function reload() {
        page = 1;
        load();
    }

    // Typing: debounced, so a fetch goes out once the user pauses rather than
    // once per keystroke. 350ms, matching the ledger.
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(reload, 350);
        });
        // A type="search" field's own clear "x" fires `search`, not `input`.
        searchInput.addEventListener('search', reload);
    }

    // Every other control: no reason to wait, the change is already deliberate.
    form.addEventListener('change', (e) => {
        if (e.target === searchInput) return;
        clearTimeout(timer);
        reload();
    });

    // With JS on, the Filter button runs the same refresh — pressing Enter in
    // the search box should not reload a page the fetch can update.
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
        container.scrollIntoView({ block: 'start', behavior: 'smooth' });
    });

    // Back/Forward past a replaceState still needs to show what the URL says.
    window.addEventListener('popstate', () => window.location.reload());
}
