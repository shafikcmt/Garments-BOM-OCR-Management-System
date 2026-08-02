/**
 * Bulk Issue history: server-driven tabs / search / sort / pagination (swapped
 * as an HTML partial), row selection + bulk actions, and the create/edit
 * slide-in panel.
 *
 * Filtering is done on the server (the controller returns _bulk-issues-table),
 * so counts and "Showing X of Y" are always accurate across all pages — unlike
 * the row-only file-table module. The browser only asks for a partial and swaps
 * it in; it never invents rows the server did not send.
 */

import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.css';

function readConfig() {
    const el = document.getElementById('bi-config');
    if (!el) return null;
    try {
        return JSON.parse(el.textContent);
    } catch (e) {
        return null;
    }
}

const esc = (v) => (v === null || v === undefined) ? '' : String(v);
const h = (v) => esc(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
const dash = (v) => esc(v).trim() === '' ? '—' : h(v);
const fmtNum = (n) => (Math.round((Number(n) || 0) * 10000) / 10000).toString();

/**
 * The identity fields the issue matrix can be searched and filtered on.
 *
 * `key` is the field name poItems() returns (and the column on
 * material_bulk_issues it is stored in); `data` is the row dataset key the
 * filter modal reads. Nothing here is new — every one is an existing column.
 * The order is the order the filter modal renders its boxes in.
 *
 * `group` is the same field as po-search knows it — the name it validates in
 * its `type` parameter and resolves through BookingPoSourceService. It is what
 * lets one field answer both of the dialog's questions: which bookings carry
 * this value (server, before a PO is loaded), and which loaded rows carry it
 * (browser, after).
 *
 * PO Number maps to `po_no`, not `contract_po`. Both exist server-side and the
 * old drill-down offered `contract_po` under this label, but the matrix column
 * headed "PO Number" shows `item.po_no` — so `po_no` is what makes the field's
 * options and the column it filters agree.
 */
const MATRIX_FIELDS = [
    { key: 'season_name', data: 'season', group: 'season', label: 'Season' },
    { key: 'buyer_name', data: 'buyer', group: 'buyer', label: 'Buyer Name' },
    { key: 'style_name', data: 'style', group: 'style', label: 'Style Number' },
    { key: 'po_no', data: 'po', group: 'po_no', label: 'PO Number' },
    { key: 'gmts_color_name', data: 'gmts', group: 'gmts_color', label: 'GMTS Color Name' },
    { key: 'material_name', data: 'material', group: 'material_name', label: 'Material Name' },
    { key: 'material_description', data: 'desc', group: 'material_description', label: 'Material Description' },
    { key: 'art_no', data: 'art', group: 'art_no', label: 'Art. No' },
    { key: 'sap_code', data: 'sap', group: 'sap_code', label: 'SAP Code' },
    { key: 'material_color', data: 'mcolor', group: 'material_color', label: 'Material Color' },
    { key: 'size', data: 'size', group: 'size', label: 'Size' },
];

export function initBulkIssueTable() {
    const cfg = readConfig();
    if (!cfg) return;

    // The form is initialised first and independently of the listing: it is
    // rendered both inside the index page's slide-in panel and on its own
    // full-width create/edit route, where there is no history table at all.
    initPanel(cfg);

    const container = document.getElementById('biTableContainer');
    const skeleton = document.getElementById('biSkeleton');
    const tabsWrap = document.getElementById('biTabs');
    const searchInput = document.getElementById('biSearchInput');
    const searchSpin = document.getElementById('biSearchSpin');
    const chipsWrap = document.getElementById('biChips');
    const bulkBar = document.getElementById('biBulkBar');
    const selCountEl = document.getElementById('biSelCount');
    if (!container) return;

    const state = Object.assign({ page: 1 }, cfg.state);
    const can = cfg.can || {};

    // --- Server fetch + swap ---------------------------------------------------
    let fetchTicket = 0;

    function buildQuery(extra) {
        const p = new URLSearchParams();
        if (state.tab && state.tab !== 'all') p.set('tab', state.tab);
        if (state.q) p.set('q', state.q);
        if (state.sort && state.sort !== 'date') p.set('sort', state.sort);
        if (state.dir && state.dir !== 'desc') p.set('dir', state.dir);
        if (state.perPage && Number(state.perPage) !== 20) p.set('per_page', state.perPage);
        if (state.page && Number(state.page) !== 1) p.set('page', state.page);
        Object.keys(extra || {}).forEach((k) => p.set(k, extra[k]));
        return p;
    }

    function syncUrl() {
        const qs = buildQuery().toString();
        window.history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    }

    function load() {
        const ticket = ++fetchTicket;
        skeleton.classList.remove('d-none');
        container.classList.add('d-none');

        const url = cfg.routes.index + '?' + buildQuery({ partial: 1 }).toString();
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.text() : Promise.reject(r.status)))
            .then((html) => {
                if (ticket !== fetchTicket) return;
                container.innerHTML = html;
                afterSwap();
            })
            .catch(() => {
                if (ticket !== fetchTicket) return;
                container.innerHTML = '<div class="alert alert-warning">Could not load the list. Please try again.</div>';
                afterSwap();
            })
            .finally(() => {
                if (ticket !== fetchTicket) return;
                skeleton.classList.add('d-none');
                container.classList.remove('d-none');
                searchSpin.classList.add('d-none');
            });
    }

    function afterSwap() {
        // Refresh tab badges from the counts the partial carried.
        const countsEl = container.querySelector('[data-bi-counts]');
        if (countsEl) {
            let counts = {};
            try { counts = JSON.parse(countsEl.getAttribute('data-bi-counts')); } catch (e) { counts = {}; }
            Object.keys(counts).forEach((k) => {
                const badge = tabsWrap && tabsWrap.querySelector('[data-bi-count="' + k + '"]');
                if (badge) badge.textContent = counts[k];
            });
        }
        updateTabsActive();
        renderChips();
        updateSelection();
        syncUrl();
    }

    // --- Tabs ------------------------------------------------------------------
    function updateTabsActive() {
        if (!tabsWrap) return;
        tabsWrap.querySelectorAll('[data-bi-tab]').forEach((btn) => {
            const on = btn.getAttribute('data-bi-tab') === state.tab;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
            const badge = btn.querySelector('.badge');
            if (badge) {
                badge.classList.toggle('bg-primary-subtle', on);
                badge.classList.toggle('text-primary', on);
                badge.classList.toggle('bg-secondary-subtle', !on);
                badge.classList.toggle('text-secondary-emphasis', !on);
            }
        });
    }

    if (tabsWrap) {
        tabsWrap.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bi-tab]');
            if (!btn) return;
            state.tab = btn.getAttribute('data-bi-tab');
            state.page = 1;
            load();
        });
    }

    // --- Search (debounced) ----------------------------------------------------
    let searchTimer = null;
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchSpin.classList.remove('d-none');
            searchTimer = setTimeout(() => {
                state.q = searchInput.value.trim();
                state.page = 1;
                load();
            }, 300);
        });
    }

    // --- Filter chips ----------------------------------------------------------
    function renderChips() {
        if (!chipsWrap) return;
        // Only filters BEYOND the active tab get a chip. The tab itself is
        // already visible as the selected tab, so repeating it as a "This Month ×"
        // chip would be the same filter shown twice.
        const chips = [];
        if (state.q) chips.push(['q', 'Search: "' + state.q + '"']);

        if (!chips.length) { chipsWrap.innerHTML = ''; return; }

        chipsWrap.innerHTML = chips
            .map(([key, label]) => '<span class="gx-chip">' + h(label) + '<button type="button" data-chip-clear="' + key + '" aria-label="Remove this filter">&times;</button></span>')
            .join('') + '<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-chip-clear="all">Clear All Filters</button>';
    }

    if (chipsWrap) {
        chipsWrap.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-chip-clear]');
            if (!btn) return;
            const key = btn.getAttribute('data-chip-clear');
            // Chips cover the extra filters only, so clearing them leaves the
            // active tab alone — the tab is its own visible control.
            if (key === 'q' || key === 'all') { state.q = ''; if (searchInput) searchInput.value = ''; }
            state.page = 1;
            load();
        });
    }

    // --- Delegated table controls (sort / per-page / pagination) ---------------
    container.addEventListener('click', (e) => {
        const sortBtn = e.target.closest('[data-bi-sort]');
        if (sortBtn) {
            const col = sortBtn.getAttribute('data-bi-sort');
            if (state.sort === col) {
                state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sort = col;
                state.dir = 'desc';
            }
            state.page = 1;
            load();
            return;
        }
        const pageLink = e.target.closest('.pagination a');
        if (pageLink) {
            e.preventDefault();
            const url = new URL(pageLink.href, window.location.origin);
            state.page = url.searchParams.get('page') || 1;
            load();
        }
    });

    container.addEventListener('change', (e) => {
        if (e.target.id === 'biPerPage') {
            state.perPage = e.target.value;
            state.page = 1;
            load();
        }
    });

    // --- Row selection + sticky bar -------------------------------------------
    function selectedIds() {
        return Array.from(container.querySelectorAll('.bi-row-check:checked')).map((c) => c.value);
    }

    function updateSelection() {
        const ids = selectedIds();
        if (selCountEl) selCountEl.textContent = ids.length;
        if (bulkBar) bulkBar.classList.toggle('d-none', ids.length === 0);
        const all = container.querySelector('#biSelectAll');
        const boxes = container.querySelectorAll('.bi-row-check');
        if (all) {
            all.checked = boxes.length > 0 && ids.length === boxes.length;
            all.indeterminate = ids.length > 0 && ids.length < boxes.length;
        }
    }

    container.addEventListener('change', (e) => {
        if (e.target.id === 'biSelectAll') {
            container.querySelectorAll('.bi-row-check').forEach((c) => { c.checked = e.target.checked; });
            updateSelection();
        } else if (e.target.classList.contains('bi-row-check')) {
            updateSelection();
        }
    });

    // --- Bulk actions ----------------------------------------------------------
    const bulkForm = document.getElementById('biBulkForm');
    const bulkIds = document.getElementById('biBulkIds');

    function submitBulk(action, method) {
        const ids = selectedIds();
        if (!ids.length) return;
        bulkForm.action = action;
        bulkForm.method = 'POST';
        bulkIds.innerHTML = ids.map((id) => '<input type="hidden" name="ids[]" value="' + h(id) + '">').join('') +
            (method === 'DELETE' ? '' : '');
        bulkForm.submit();
    }

    function printSelection() {
        const rows = Array.from(container.querySelectorAll('.bi-row-check:checked')).map((c) => c.closest('tr'));
        if (!rows.length) return;
        const body = rows.map((tr) => {
            const cell = (label) => {
                const td = tr.querySelector('[data-label="' + label + '"]');
                return td ? td.textContent.trim() : '';
            };
            return '<tr><td>' + h(cell('Date')) + '</td><td>' + h(cell('PO / Material')) +
                '</td><td style="text-align:right">' + h(cell('Bulk')) + '</td><td style="text-align:right">' + h(cell('Sample')) +
                '</td><td style="text-align:right">' + h(cell('Liability')) + '</td><td style="text-align:right">' + h(cell('Dead')) + '</td></tr>';
        }).join('');
        const win = window.open('', '_blank');
        if (!win) return;
        win.document.write('<html><head><title>Bulk Issues</title><style>' +
            'body{font-family:Arial,sans-serif;font-size:12px;padding:20px;}h1{font-size:16px;}' +
            'table{width:100%;border-collapse:collapse;margin-top:12px;}th,td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left;}th{background:#1D4ED8;color:#fff;}' +
            '</style></head><body><h1>Bulk Issuing — Selected (' + rows.length + ')</h1>' +
            '<table><thead><tr><th>Date</th><th>PO / Material</th><th>Bulk</th><th>Sample</th><th>Liab.</th><th>Dead</th></tr></thead><tbody>' +
            body + '</tbody></table></body></html>');
        win.document.close();
        win.focus();
        win.print();
    }

    function clearSelection() {
        container.querySelectorAll('.bi-row-check:checked').forEach((c) => { c.checked = false; });
        updateSelection();
    }

    if (bulkBar) {
        bulkBar.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bi-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-bi-action');
            const count = selectedIds().length;
            if (action === 'excel') submitBulk(cfg.routes.exportExcel);
            else if (action === 'pdf') submitBulk(cfg.routes.exportPdf);
            else if (action === 'print') printSelection();
            else if (action === 'cancel') clearSelection();
            else if (action === 'delete') {
                // The button is not rendered without the permission; this second
                // check keeps a stale DOM from firing a request the server would
                // reject anyway.
                if (!can.delete) return;
                if (window.confirm('Delete ' + count + ' selected bulk issue(s)? Closing stock will update. This cannot be undone.')) {
                    submitBulk(cfg.routes.bulkDestroy, 'DELETE');
                }
            }
        });
    }

    // Escape clears an active selection — the keyboard equivalent of Cancel.
    // Skipped while the slide-in panel is open, where Escape already means
    // "close the panel".
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const panelEl = document.getElementById('biPanel');
        if (panelEl && panelEl.classList.contains('show')) return;
        if (selectedIds().length) clearSelection();
    });

    // Initial paint (state already matches the server-rendered partial).
    afterSwap();
}


/**
 * The offcanvas create/edit form.
 *
 * Selection is a cascade, mirroring Receiving: find the paperwork (PO / PI /
 * Invoice) -> lock the PO -> pick the style -> pick the item(s) -> enter the
 * four quantities per item. Indent Info and Remarks sit below and are shared
 * across every item in one submission, the same way Receiving shares its
 * delivery header.
 */
function initPanel(cfg) {
    const form = document.getElementById('biForm');
    if (!form || typeof bootstrap === 'undefined') return;

    // Two shells render this same form: the index page's slide-in panel, and
    // the full-width create/edit route. Everything below is shell-agnostic —
    // only opening and closing differ, and those are the panel's alone.
    const panelEl = document.getElementById('biPanel');
    const panel = panelEl ? bootstrap.Offcanvas.getOrCreateInstance(panelEl) : null;
    // Where the vanilla side publishes state for the Alpine shell to read.
    const stateHost = panelEl
        ? panelEl.querySelector('.offcanvas-body')
        : form.closest('[x-data]');

    const methodEl = document.getElementById('biMethod');
    const poId = document.getElementById('biPoId');
    const title = document.getElementById('biPanelTitle');
    const saveLabel = document.getElementById('biSaveLabel');

    // Booking lookup lives inside the Filters dialog: choosing the booking and
    // narrowing its rows are the same decision, so they are the same dialog.
    // There is no search input any more — the eleven dropdowns are the search.
    const poLoading = document.getElementById('biPoLoading');
    const poError = document.getElementById('biPoError');
    const poPanel = document.getElementById('biPoPanel');
    const poList = document.getElementById('biPoList');
    const poHint = document.getElementById('biPoHint');
    const selectedRow = document.getElementById('biSelectedRow');
    const selectedText = document.getElementById('biSelectedText');
    const summary = document.getElementById('biSummaryGrid');
    const sumCounts = document.getElementById('biSumCounts');

    const itemRows = document.getElementById('biItemRows');
    const noItems = document.getElementById('biNoItems');
    const includeAll = document.getElementById('biIncludeAll');
    const overWarn = document.getElementById('biOverWarn');
    const overText = document.getElementById('biOverText');
    const issueNoEl = document.getElementById('biIssueNo');
    const issueDateEl = document.getElementById('biIssueDate');

    let items = [];        // every material line under the loaded PO
    let loadedPoId = null;
    let loadingItems = false;
    // Set while the matrix is being built row by row — see loadItems().
    let suspendCheck = false;
    let uid = 0;
    // Edit mode reuses the same panel but corrects exactly one existing issue,
    // so its single row posts flat field names instead of the rows[] array.
    let editing = false;

    // --- Finding a booking through the eleven fields --------------------------
    // Which request's answer is still wanted: a slow lookup for a value the user
    // has already moved on from must not overwrite a newer one.
    let searchTicket = 0;

    function openSuggest() { poPanel.classList.remove('d-none'); }
    function closeSuggest() { poPanel.classList.add('d-none'); }

    /**
     * The distinct values one field holds across every booking.
     *
     * Fetched once per field and kept for the page's life, and only when the
     * field is first focused — every group but po_no costs a scan over
     * ExcelCell, so eleven of them on dialog open is the cost this defers. The
     * endpoint is po-search in its step-1 shape, unchanged.
     */
    const valueCache = {};
    const valueInFlight = {};

    function loadGroupValues(group) {
        if (valueCache[group]) return Promise.resolve(valueCache[group]);
        if (valueInFlight[group]) return valueInFlight[group];

        valueInFlight[group] = fetch(cfg.routes.poSearch + '?type=' + encodeURIComponent(group), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                valueCache[group] = data.results || [];
                return valueCache[group];
            })
            .finally(() => { delete valueInFlight[group]; });

        return valueInFlight[group];
    }

    /**
     * Which bookings carry this value in this field.
     *
     * One match is unambiguous, so it loads straight into the matrix — that is
     * the whole point of picking a PO Number. Several are listed under the grid
     * for the user to choose between, which is what happens when the field is
     * something a booking does not uniquely own, like a Season or a Size.
     */
    function findBookings(group, value, label) {
        const ticket = ++searchTicket;

        openSuggest();
        poHint.textContent = 'Searching…';
        poList.innerHTML = '';

        fetch(cfg.routes.poSearch + '?type=' + encodeURIComponent(group) +
            '&value=' + encodeURIComponent(value), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                if (ticket !== searchTicket) return;
                const results = data.results || [];

                if (results.length === 1) {
                    closeSuggest();
                    selectPo(results[0].id, results[0].po_no);
                    return;
                }

                renderResults(results, label, value);
            })
            .catch(() => {
                if (ticket !== searchTicket) return;
                poHint.textContent = '';
                poList.innerHTML = '<div class="list-group-item text-muted">Could not load the POs for this value. Please try again.</div>';
            });
    }

    /**
     * A value's initial in a tinted disc, so a long list can be scanned by
     * shape before it is read. The tint is derived from the text itself, which
     * keeps one buyer the same colour everywhere without a colour table to
     * maintain.
     */
    const AVATAR_TONES = [
        ['#EFF6FF', '#1D4ED8'], ['#ECFDF5', '#047857'], ['#FFF7ED', '#C2410C'],
        ['#F5F3FF', '#6D28D9'], ['#FDF2F8', '#BE185D'], ['#ECFEFF', '#0E7490'],
        ['#FEFCE8', '#A16207'], ['#F1F5F9', '#334155'],
    ];

    function avatarFor(value) {
        const text = esc(value).trim();
        // Letter or digit — a size of "10" should still show something.
        const initial = text ? text.charAt(0).toUpperCase() : '?';

        // djb2 with an avalanche finish. A plain character sum, or a *31 hash
        // reduced over a power-of-two palette, kept putting same-initial values
        // on the same tone — "Hugo Boss" above "HUMANA" in matching colours
        // reads as one entry repeated, the very thing this list exists to stop.
        // Measured over the real buyer/season/material/size lists, this cuts
        // adjacent colour clashes from seven to two.
        let hash = 5381;
        for (let i = 0; i < text.length; i++) hash = ((hash << 5) + hash + text.charCodeAt(i)) | 0;
        hash ^= hash >>> 15;
        hash = Math.imul(hash, 0x2c1b3c6d) | 0;
        hash ^= hash >>> 12;

        const [bg, fg] = AVATAR_TONES[Math.abs(hash) % AVATAR_TONES.length];

        return '<span class="bi-opt-avatar" style="background:' + bg + ';color:' + fg + ';" aria-hidden="true">' +
            h(initial) + '</span>';
    }

    /**
     * The bookings carrying the value just chosen, listed under the grid.
     *
     * Only ever reached when there is more than one — a single match skips this
     * and loads itself, because there is nothing to choose.
     */
    function renderResults(results, label, value) {
        if (!results.length) {
            poHint.textContent = '';
            poList.innerHTML = '<div class="bi-opt-empty">' +
                '<span class="bi-opt-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>' +
                '<div class="bi-opt-empty-title">No POs found</div>' +
                '<p class="bi-opt-empty-text">No PO has “' + h(value) + '” in ' + h(label) + '.</p></div>';
            return;
        }

        poHint.textContent = results.length + ' POs match “' + h(value) + '” — select one';

        poList.innerHTML = bookingRows(results);
    }

    /** Bookings as rows, ready to be chosen. */
    function bookingRows(results) {
        return results.map((r) => {
            const meta = [r.po_no, r.buyer_name, r.style_name, r.season_name, r.vendor_name]
                .filter(Boolean).join(' · ');

            // Same row shape as step 1, so moving between the two steps does not
            // feel like moving between two different lists.
            return '<div class="list-group-item bi-opt bi-opt-row" role="option" tabindex="-1"' +
                ' data-id="' + h(r.id) + '" data-po="' + h(r.po_no) + '">' +
                avatarFor(r.po_no) +
                '<div class="bi-opt-body">' +
                    '<div class="bi-opt-primary">' + dash(r.po_no) + '</div>' +
                    '<div class="bi-opt-meta">' + dash(meta) + '</div>' +
                '</div></div>';
        }).join('');
    }

    poList.addEventListener('click', (e) => {
        const opt = e.target.closest('.bi-opt');
        if (opt) selectPo(opt.dataset.id, opt.dataset.po);
    });

    // --- Choosing the booking -------------------------------------------------
    /**
     * A booking chosen in the Filters dialog. Its material lines go straight
     * into the matrix — there is no item-picker step between the two any more,
     * so the rows the issue is typed against are the rows the PO actually has.
     */
    function selectPo(newId, poNo) {
        if (String(newId) === String(poId.value)) { closeSuggest(); return; }

        if (hasTypedQty() &&
            !window.confirm('Changing the PO will clear the quantities you typed. Continue?')) return;

        poId.value = newId;
        selectedText.textContent = poNo || '—';
        selectedRow.classList.remove('d-none');
        closeSuggest();
        loadItems(newId);
    }

    /** Anything worth warning about before the matrix is replaced. */
    function hasTypedQty() {
        return matrixRows().some((row) => rowTotal(row) > 0);
    }

    function setSummary(data) {
        summary.querySelectorAll('[data-sum]').forEach((el) => {
            const v = data ? data[el.getAttribute('data-sum')] : null;
            el.textContent = (v === null || v === undefined || String(v).trim() === '') ? '—' : String(v);
        });
        sumCounts.textContent = data ? (styleNames().length + ' style(s) · ' + items.length + ' item(s)') : '—';
    }

    const styleKey = (item) => (esc(item.style_name) === '' ? '—' : esc(item.style_name));
    const styleNames = () => [...new Set(items.map(styleKey))];

    /**
     * Every material line under the chosen booking, laid straight into the
     * matrix.
     *
     * Same endpoint the item picker used to feed — poItems() is unchanged, and
     * so is what it returns. What changed is where the rows land: in the grid
     * the user types into, rather than in a dialog they had to confirm out of
     * first. Edit mode is the exception; it lays out its own single row.
     */
    function loadItems(id) {
        loadingItems = true;
        poLoading.classList.remove('d-none');
        poError.classList.add('d-none');
        itemRows.innerHTML = '';
        setSummary(null);
        refreshItemsState();

        return fetch(cfg.routes.poItems.replace('__ID__', encodeURIComponent(id)), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                if (String(poId.value) !== String(id)) return;   // changed meanwhile
                items = data.items || [];
                loadedPoId = id;
                setSummary(data);

                if (!editing) {
                    // checkOver() walks the whole matrix, so letting each row
                    // trigger it while the matrix is being built is quadratic —
                    // unnoticeable at 20 rows, not at 500. One pass at the end
                    // reaches the same state.
                    suspendCheck = true;
                    items.forEach((item) => addItemRow(item));
                    suspendCheck = false;

                    // A different PO carries different values, so a filter left
                    // over from the last one would hide rows for no stated
                    // reason.
                    resetMatrixChoices();
                    checkOver();
                    refreshItemsState();
                    // The dialog is normally still open — the PO was chosen in
                    // it — so the eleven fields switch to narrowing these rows
                    // now rather than on the next open.
                    syncFilterMode();
                }

                if (!items.length) {
                    poError.textContent = 'This PO has no items to issue.';
                    poError.classList.remove('d-none');
                }
            })
            .catch((status) => {
                if (String(poId.value) !== String(id)) return;
                loadedPoId = null;
                items = [];
                refreshItemsState();
                poError.textContent = status === 423
                    ? 'This file/style is locked. Stock entry is not allowed.'
                    : 'Could not load the items for this PO. Please try again.';
                poError.classList.remove('d-none');
            })
            .finally(() => {
                if (String(poId.value) !== String(id)) return;
                loadingItems = false;
                poLoading.classList.add('d-none');
                refreshItemsState();
            });
    }

    // --- Including and excluding rows -----------------------------------------
    /**
     * The whole PO loads, so most rows start with nothing typed in them and
     * `store()` rejects any submitted row totalling zero. The checkbox is what
     * says which rows are part of this issue.
     *
     * Excluding disables the row's inputs rather than clearing them: a disabled
     * field is not submitted, but it keeps its value, so a row can be taken out
     * and put back without the user retyping anything. That is the whole reason
     * it is a checkbox and not a Remove button — the old Remove is gone, since
     * with no picker to re-add from, removing a row was one-way.
     *
     * Distinct from the display filter: a row hidden by Filters still submits
     * whatever is typed in it. Excluded and hidden are different states, and
     * they are styled differently for exactly that reason.
     */
    function isIncluded(row) {
        return !row.classList.contains('bi-mx-excluded');
    }

    function setRowIncluded(row, on) {
        // Nothing on hand cannot be issued — the server refuses it outright, so
        // the row is shown for reference but never selectable.
        if (row.classList.contains('bi-mx-nostock')) on = false;

        row.classList.toggle('bi-mx-excluded', !on);
        const cb = row.querySelector('[data-bi-include]');
        if (cb) cb.checked = on;
        row.querySelectorAll('.bi-qty, input[type="hidden"]').forEach((el) => { el.disabled = !on; });
    }

    const rowTotal = (row) => Array.from(row.querySelectorAll('.bi-qty'))
        .reduce((sum, el) => sum + (parseFloat(el.value) || 0), 0);

    /** Rows the header checkbox acts on: on screen, and issuable at all. */
    const selectableRows = () => matrixRows().filter((row) =>
        !row.classList.contains('bi-mx-hidden') && !row.classList.contains('bi-mx-nostock'));

    function syncIncludeHead() {
        // Including or excluding a row changes the footer's count without
        // touching the filter, so the count is refreshed from here too.
        syncFooterCount();

        if (!includeAll) return;
        const rows = selectableRows();
        const on = rows.filter(isIncluded).length;

        includeAll.disabled = rows.length === 0;
        includeAll.checked = rows.length > 0 && on === rows.length;
        includeAll.indeterminate = on > 0 && on < rows.length;
    }

    if (includeAll) {
        includeAll.addEventListener('change', () => {
            selectableRows().forEach((row) => setRowIncluded(row, includeAll.checked));
            syncIncludeHead();
            checkOver();
        });
    }

    itemRows.addEventListener('change', (e) => {
        const cb = e.target.closest('[data-bi-include]');
        if (!cb) return;
        setRowIncluded(cb.closest('.bi-item-card'), cb.checked);
        syncIncludeHead();
        checkOver();
    });

    // --- One row per material line --------------------------------------------
    /**
     * In create mode each item posts as rows[i][...] so one submission can record
     * several issues. In edit mode a single row posts the original flat field
     * names, because update() still corrects exactly one existing issue.
     */
    function addItemRow(item, preset) {
        preset = preset || {};
        const i = uid++;
        const n = (field) => (editing ? field : 'rows[' + i + '][' + field + ']');
        const avail = parseFloat(item.available) || 0;
        // Nothing on hand: store() refuses it, so the row is shown for reference
        // and can never be included.
        const noStock = avail <= 0;

        // One <tr> per item. The class, the dataset and every data-bi-* hook are
        // the ones the card layout used, so checkOver(), publishState() and the
        // set-max handler below did not have to change with it.
        const wrap = document.createElement('tr');
        wrap.className = 'bi-item-card' + (noStock ? ' bi-mx-nostock' : '');
        wrap.dataset.rowId = item.excel_row_id;
        wrap.dataset.available = String(avail);

        // Every identity field the matrix can filter on, kept on the row itself
        // so the filter modal reads the row rather than re-parsing its cells.
        MATRIX_FIELDS.forEach((f) => {
            wrap.dataset[f.data] = String(item[f.key] == null ? '' : item[f.key]);
        });

        wrap.innerHTML =
            // Include / exclude. Edit mode corrects exactly one row, so there is
            // nothing to include or leave out and the cell stays empty — the
            // column itself is hidden by CSS in that mode.
            '<td class="bi-mx-check">' + (editing ? '' :
                '<input type="checkbox" class="form-check-input" data-bi-include' +
                (noStock ? ' disabled title="No available stock"' : '') +
                ' aria-label="Include ' + h(item.material_name || item.material_description) + ' in this issue">') +
            '</td>' +
            cell('season', item.season_name) +
            cell('buyer', item.buyer_name) +
            // The style is called out rather than plain text: it is the one
            // thing on this row that cannot be corrected after the issue saves.
            '<td class="bi-mx-po bi-mx-style">' + dash(item.style_name) + '</td>' +
            cell('po', item.po_no) +
            cell('gmts', item.gmts_color_name) +
            '<td class="bi-item-head bi-mx-strong">' + dash(item.material_name || item.material_description) + '</td>' +
            '<td class="bi-mx-desc">' + dash(item.material_description) + '</td>' +
            '<td class="bi-mx-art">' + dash(item.art_no) + '</td>' +
            '<td>' + dash(item.sap_code) + '</td>' +
            '<td>' + dash(item.material_color) + '</td>' +
            '<td>' + dash(item.size) + '</td>' +
            '<td class="text-center text-muted">' + dash(item.uom) + '</td>' +
            // The hidden row id rides inside a cell, not between two: a stray
            // <input> in <tr> context is foster-parented out of the table by the
            // HTML parser and would never reach the POST.
            '<td class="text-end bi-mx-avail">' + fmtNum(avail) +
                '<input type="hidden" name="' + n('excel_row_id') + '" value="' + h(item.excel_row_id) + '">' +
            '</td>' +
            qtyCell('bulk', 'Bulk Issued Qty', n('bulk_qty'), preset.bulk_qty) +
            qtyCell('sample', 'Sample Issued Qty', n('sample_qty'), preset.sample_qty) +
            qtyCell('liability', 'Liability Stock Qty', n('liability_qty'), preset.liability_qty) +
            qtyCell('dead', 'Dead Stock Qty', n('dead_qty'), preset.dead_qty) +
            // Running total against the balance. The same figures checkOver()
            // already computes, stated before the user hits the error rather
            // than only after. The over-limit message lives here too: the limit
            // applies to the sum of four independent fields, so which one to
            // reduce is the user's call — hence a blocking message with a
            // one-click way out, rather than silently rewriting what they typed.
            '<td class="bi-mx-total">' +
                '<span class="bi-item-total-num" data-bi-total>0</span>' +
                '<span class="bi-mx-filled" data-bi-filled ' +
                    'title="How many of the four quantity fields carry a value">0/4</span>' +
                '<div class="bi-mx-over d-none" data-bi-over>' +
                    '<span class="bi-item-error"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>' +
                        '<span data-bi-over-text></span></span>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" data-bi-setmax ' +
                        'title="Set Bulk to the available balance and clear the other three">Set to max</button>' +
                '</div>' +
            '</td>';

        itemRows.appendChild(wrap);

        // A row starts out of the issue and joins it when a quantity is typed.
        // The whole PO loads now, so the opposite default would offer to issue
        // every line under the booking — and the suggested Bulk default this
        // used to take from the BOM's GMTS Order Qty went with it, for the same
        // reason: pre-filling 50 rows is not a suggestion, it is an issue nobody
        // asked for. Edit mode still prefills, from the record being corrected.
        setRowIncluded(wrap, editing || rowTotal(wrap) > 0);

        if (!suspendCheck) checkOver();
    }

    /**
     * One quantity cell. On a matrix the column header carries the label, so the
     * per-field label becomes the input's accessible name instead of visible
     * text — the colour of the input is what tells the four columns apart, and
     * that colour comes from the `bi-qty-<kind>` class rather than inline style.
     */
    function qtyCell(kind, label, name, value) {
        const id = 'biQty_' + String(name).replace(/\W/g, '_');

        return '<td class="bi-mx-qty">' +
            '<input type="number" step="0.0001" min="0" id="' + id + '" name="' + h(name) + '" placeholder="–" ' +
                'aria-label="' + h(label) + '" class="bi-qty bi-qty-input bi-qty-' + kind + '"' +
                (value !== undefined && value !== null && value !== '' ? ' value="' + h(value) + '"' : '') + '>' +
        '</td>';
    }

    /** Plain identity cell, tagged so the panel shell can drop the wide ones. */
    function cell(tag, value) {
        return '<td class="bi-mx-po bi-mx-' + tag + '">' + dash(value) + '</td>';
    }

    itemRows.addEventListener('input', (e) => {
        if (!e.target.classList.contains('bi-qty')) return;

        // Typing a quantity is what says "this line is part of the issue", so
        // it ticks the row rather than making the user tick it first. Clearing
        // the field again does not untick: the row is left in, and submit drops
        // it only if it is still empty then.
        const row = e.target.closest('.bi-item-card');
        if (row && e.target.value !== '') {
            setRowIncluded(row, true);
            syncIncludeHead();
        }

        checkOver();
    });

    function refreshItemsState() {
        const count = itemRows.children.length;
        // "No purchase order selected" would be a lie for the second or two a
        // PO's lines are in flight — the spinner above says what is happening.
        noItems.classList.toggle('d-none', count > 0 || loadingItems);
        applyMatrixFilter();
        syncIncludeHead();
        publishState();
    }

    // --- Matrix search + Excel-style column filter -----------------------------
    // Display only. Hiding a row never detaches its inputs, so a quantity typed
    // and then filtered out of view is still saved — which is why the count of
    // hidden rows is always on screen rather than left for the user to infer.
    const mxSearch = document.getElementById('biMatrixSearch');
    const mxGrid = document.getElementById('biMatrixFilterGrid');
    const mxModal = document.getElementById('biMatrixFilterModal');
    const mxApply = document.getElementById('biMatrixFilterApply');
    const mxReset = document.getElementById('biMatrixFilterReset');
    const mxShowing = document.getElementById('biMatrixShowing');
    const mxFilterBtn = document.getElementById('biMatrixFilterBtn');
    const mxFooterCount = document.getElementById('biFooterCount');
    const mxSectTitle = document.getElementById('biFxSectTitle');
    const mxSectNote = document.getElementById('biFxSectNote');

    // Chosen value per field, empty string meaning "all".
    const mxChoice = {};

    // One TomSelect per field, keyed by `data`. Built once and then refilled,
    // never rebuilt: destroying and re-creating eleven controls on every open
    // loses focus, scroll position and any options already fetched.
    const mxSelects = {};
    let gridBuilt = false;

    /** Identify a booking, or narrow the one already loaded? */
    const inNarrowMode = () => matrixRows().length > 0;

    /** Drop every row filter. A new PO's rows carry different values. */
    function resetMatrixChoices() {
        MATRIX_FIELDS.forEach((f) => { mxChoice[f.data] = ''; });
        if (mxSearch) mxSearch.value = '';
        Object.values(mxSelects).forEach((ts) => ts.clear(true));
    }

    function matrixRows() {
        return Array.from(itemRows.children);
    }

    /**
     * The eleven controls, created once.
     *
     * Each is a TomSelect so a field with 400 SAP codes can be typed at rather
     * than scrolled through. Options are NOT loaded here — see fillField().
     */
    function buildFilterGrid() {
        if (!mxGrid || gridBuilt) return;

        mxGrid.innerHTML = MATRIX_FIELDS.map((f) => {
            // Descriptions are long enough that a half-width box truncates them.
            const wide = f.data === 'desc' ? ' bi-fx-wide' : '';

            // The empty <option> is load-bearing. A single <select> with no
            // empty choice makes TomSelect select the first option the moment
            // any are added — which fired onChange, found one booking and
            // loaded a PO nobody had picked.
            return '<div class="bi-fx-card' + wide + '">' +
                '<label for="biFx_' + f.data + '">' + h(f.label) + '</label>' +
                '<select class="bi-fx-select" id="biFx_' + f.data + '" data-bi-fx="' + f.data + '">' +
                    '<option value="">All</option>' +
                '</select>' +
            '</div>';
        }).join('');

        MATRIX_FIELDS.forEach((f) => {
            const el = mxGrid.querySelector('#biFx_' + f.data);
            if (!el) return;

            const ts = new TomSelect(el, {
                maxOptions: null,
                placeholder: 'All',
                allowEmptyOption: true,
                // The value list is the whole point of the control; hiding it
                // behind a typed character would make an empty field look
                // broken on a screen where most fields are left alone.
                openOnFocus: true,
                onFocus: () => fillField(f),
                onChange: (value) => onFieldChange(f, value),
            });

            mxSelects[f.data] = ts;
        });

        gridBuilt = true;
    }

    /**
     * Put the right options into one field, for whichever question it is
     * currently answering.
     *
     * Narrow mode reads the loaded rows — no request, and the values offered are
     * exactly the values on screen. Identify mode asks the server for what the
     * field holds across every booking, once per field per page: this is the
     * lazy load, and it is why eleven fields do not cost eleven scans of
     * ExcelCell just to open the dialog.
     */
    function fillField(f) {
        const ts = mxSelects[f.data];
        if (!ts) return;

        if (inNarrowMode()) {
            const values = Array.from(new Set(
                matrixRows().map((r) => (r.dataset[f.data] || '').trim()).filter(Boolean)
            )).sort((a, b) => a.localeCompare(b));

            setOptions(ts, values.map((v) => ({ value: v, text: v })), mxChoice[f.data]);
            return;
        }

        if (ts.loading || valueCache[f.group]) {
            if (valueCache[f.group]) {
                setOptions(ts, valueCache[f.group].map((r) => ({
                    value: r.value,
                    text: r.value + (Number(r.count) > 1 ? '  (' + r.count + ' POs)' : ''),
                })), mxChoice[f.data]);
            }
            return;
        }

        ts.loading = 1;
        ts.wrapper.classList.add('bi-fx-loading');

        loadGroupValues(f.group)
            .then((values) => {
                setOptions(ts, values.map((r) => ({
                    value: r.value,
                    text: r.value + (Number(r.count) > 1 ? '  (' + r.count + ' POs)' : ''),
                })), mxChoice[f.data]);
            })
            .catch(() => { /* an empty field is its own message */ })
            .finally(() => {
                ts.loading = 0;
                ts.wrapper.classList.remove('bi-fx-loading');
                ts.refreshOptions(false);
            });
    }

    // Set while options are being written into a control. Refilling a field is
    // not the user choosing something, and TomSelect cannot tell the difference
    // — without this, restoring a value would re-run the booking lookup.
    let fillingField = false;

    /** Replace a control's options without disturbing what is selected. */
    function setOptions(ts, options, keep) {
        fillingField = true;
        try {
            ts.clearOptions();
            ts.addOption({ value: '', text: 'All' });
            ts.addOptions(options);
            if (keep) { ts.addOption({ value: keep, text: keep }); ts.setValue(keep, true); }
            ts.refreshOptions(false);
        } finally {
            fillingField = false;
        }
    }

    function onFieldChange(f, value) {
        if (fillingField) return;
        mxChoice[f.data] = value || '';

        // Narrowing waits for Apply, so a run of changes is one pass over the
        // rows rather than eleven.
        if (inNarrowMode()) return;

        if (!value) { closeSuggest(); return; }

        // Nothing is loaded yet, so this field is being used to find a booking.
        // Every other field is cleared: they describe a different booking's
        // values and would read as an AND this dialog cannot honour.
        MATRIX_FIELDS.forEach((other) => {
            if (other.data === f.data) return;
            mxChoice[other.data] = '';
            if (mxSelects[other.data]) mxSelects[other.data].clear(true);
        });

        findBookings(f.group, value, f.label);
    }

    /**
     * The heading follows the job the grid is currently doing.
     *
     * The note carries the one fact a first-time user cannot guess: narrowing
     * only hides items, it does not drop them from the issue. That used to be
     * spelled out in a second sentence under the dialog title; it is the same
     * point in four words here.
     */
    function syncFilterMode() {
        const narrow = inNarrowMode();

        if (mxSectTitle) mxSectTitle.textContent = narrow ? 'Narrow the list' : 'Find a PO';
        if (mxSectNote) mxSectNote.textContent = narrow ? 'Hidden items are still saved' : 'Click a field and type to search';
    }

    // Rows the current filter is hiding. Kept so the footer can be rewritten
    // without re-running the filter — see applyMatrixFilter().
    let hiddenByFilter = 0;

    /**
     * "n of m row(s) in this issue", plus what the filter is hiding.
     *
     * Two different things move this line: the filter, and ticking a row in or
     * out. Only the first goes through applyMatrixFilter, so the count lives
     * here and both call it.
     */
    function syncFooterCount() {
        if (!mxFooterCount) return;
        const rows = matrixRows();

        mxFooterCount.textContent = rows.length === 0
            ? 'No PO selected'
            : rows.filter(isIncluded).length + ' of ' + rows.length + ' item(s) in this issue' +
              (hiddenByFilter > 0 ? ' · ' + hiddenByFilter + ' hidden by filter' : '');
    }

    /** Hide the rows that fail the search term or any chosen filter value. */
    function applyMatrixFilter() {
        const rows = matrixRows();
        const term = (mxSearch ? mxSearch.value : '').trim().toLowerCase();
        const active = MATRIX_FIELDS.filter((f) => mxChoice[f.data]);

        let shown = 0;
        rows.forEach((row) => {
            const hit = MATRIX_FIELDS.some((f) => (row.dataset[f.data] || '').toLowerCase().includes(term));
            const passes = (term === '' || hit) &&
                active.every((f) => (row.dataset[f.data] || '') === mxChoice[f.data]);

            row.classList.toggle('bi-mx-hidden', !passes);
            if (passes) shown++;
        });

        const filtering = term !== '' || active.length > 0;
        if (mxShowing) {
            mxShowing.textContent = filtering ? 'Showing ' + shown + ' of ' + rows.length : '';
            mxShowing.classList.toggle('d-none', !filtering);
        }
        if (mxFilterBtn) mxFilterBtn.classList.toggle('is-on', active.length > 0);

        // What the filter last hid, so the footer can be rewritten on its own
        // when rows are included or excluded — that changes the count without
        // changing the filter, and re-running the whole pass to say so would be
        // wasted work on a 500-row matrix.
        hiddenByFilter = filtering ? rows.length - shown : 0;
        syncFooterCount();

        // A filter changes which rows the header checkbox speaks for.
        syncIncludeHead();
    }

    if (mxSearch) mxSearch.addEventListener('input', applyMatrixFilter);

    if (mxModal) {
        mxModal.addEventListener('show.bs.modal', () => {
            buildFilterGrid();
            syncFilterMode();
            // Options are fetched on focus, but a field already holding a value
            // has to be able to show it — otherwise reopening the dialog shows
            // an empty box over an active filter.
            MATRIX_FIELDS.forEach((f) => { if (mxChoice[f.data]) fillField(f); });
        });

        // The booking list lives in this dialog; closing over an open list would
        // leave it hanging there for the next open.
        mxModal.addEventListener('hidden.bs.modal', closeSuggest);
    }

    if (mxApply) mxApply.addEventListener('click', applyMatrixFilter);

    if (mxReset) {
        mxReset.addEventListener('click', () => {
            resetMatrixChoices();
            closeSuggest();
            syncFilterMode();
            applyMatrixFilter();
        });
    }

    /**
     * Hands the Alpine shell what it needs to lock its sections and gate Save.
     * One-way on purpose: Alpine reads this and never writes back into the DOM
     * this module owns, so the two cannot fight over the same nodes.
     */
    function publishState(extra) {
        const cards = Array.from(itemRows.children);
        // Only the rows actually going into the issue count. An excluded row is
        // not submitted, so neither its quantity nor its stock error is the
        // user's problem.
        const included = cards.filter(isIncluded);
        // Rows over their stock balance — the count the status bar shows and
        // the reason Save stays disabled.
        const errorCount = included.filter((c) => c.classList.contains('is-over')).length;
        // Rows carrying at least one of the four quantities. These are exactly
        // what will be POSTed, so this is the count the save gate reads.
        const withQty = included.filter((c) => rowTotal(c) > 0).length;

        if (!stateHost) return;
        stateHost.dispatchEvent(new CustomEvent('bi:state', {
            detail: Object.assign({
                hasPo: !!poId.value,
                itemCount: cards.length,
                includedCount: included.length,
                blocked: errorCount > 0,
                errorCount,
                withQty,
                editing,
            }, extra || {}),
        }));
    }

    /**
     * Stock-integrity check, per item. Unlike the earlier soft warning this is a
     * hard rule: an issue can never exceed what the ledger says is on hand, so
     * Save stays disabled until every item is within its balance. The server
     * enforces the same rule — this only makes it visible early.
     */
    function checkOver() {
        const offenders = [];

        Array.from(itemRows.children).forEach((card) => {
            const avail = parseFloat(card.dataset.available) || 0;
            const fields = Array.from(card.querySelectorAll('.bi-qty'));
            const total = fields.reduce((sum, el) => sum + (parseFloat(el.value) || 0), 0);
            // An excluded row is not submitted, so it cannot be over anything —
            // its figures stay on screen but stop blocking Save.
            const over = isIncluded(card) && total > avail + 1e-9;
            const name = card.querySelector('.bi-item-head').textContent.trim();

            // "n/4" reads at a glance on a card that carries four inputs.
            const filled = fields.filter((el) => el.value !== '' && parseFloat(el.value) > 0).length;
            const filledEl = card.querySelector('[data-bi-filled]');
            if (filledEl) {
                filledEl.textContent = filled + '/4';
                filledEl.classList.toggle('bg-secondary-subtle', filled === 0);
                filledEl.classList.toggle('text-secondary-emphasis', filled === 0);
                filledEl.classList.toggle('bg-primary-subtle', filled > 0);
                filledEl.classList.toggle('text-primary-emphasis', filled > 0);
            }

            card.classList.toggle('is-over', over);

            // Live running total, so the balance is visible while typing rather
            // than only once it has already been exceeded.
            const totalEl = card.querySelector('[data-bi-total]');
            if (totalEl) totalEl.textContent = fmtNum(total);

            const box = card.querySelector('[data-bi-over]');
            if (box) {
                box.classList.toggle('d-none', !over);
                const msg = box.querySelector('[data-bi-over-text]');
                if (msg && over) {
                    msg.textContent = 'Exceeds available stock — entered ' + fmtNum(total) +
                        ', available ' + fmtNum(avail) + '.';
                }
            }

            if (over) offenders.push(name + ' (' + fmtNum(total) + ' of ' + fmtNum(avail) + ')');
        });

        if (offenders.length) {
            overText.textContent = offenders.length === 1
                ? 'Cannot save: ' + offenders[0] + ' exceeds its available stock.'
                : 'Cannot save: ' + offenders.length + ' items exceed available stock — ' + offenders.join('; ') + '.';
            overWarn.classList.remove('d-none');
            overWarn.classList.remove('alert-warning');
            overWarn.classList.add('alert-danger');
        } else {
            overWarn.classList.add('d-none');
        }

        // The Save button's disabled state belongs to the Alpine shell, which
        // weighs this against the other save conditions. Publishing the counts
        // is this module's whole part in it — two owners of one attribute is
        // how the two layers start fighting.
        publishState();

        return offenders.length > 0;
    }

    // "Set to max": puts the whole available balance into Bulk and clears the
    // other three, which is the only split the software can infer on its own.
    itemRows.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-bi-setmax]');
        if (!btn) return;

        const card = btn.closest('.bi-item-card');
        const avail = parseFloat(card.dataset.available) || 0;
        card.querySelectorAll('.bi-qty').forEach((el) => { el.value = ''; });
        const bulkInput = card.querySelector('input[name$="[bulk_qty]"], input[name="bulk_qty"]');
        if (bulkInput) bulkInput.value = fmtNum(avail);
        setRowIncluded(card, true);
        syncIncludeHead();
        checkOver();
    });

    /**
     * Fill Issue No with the actual generated number rather than a placeholder,
     * so the user sees the value that will be saved. It stays fully editable —
     * the italic/muted styling drops the moment they type.
     */
    function suggestIssueNo() {
        if (!issueNoEl || issueNoEl.value) return;
        const d = new Date();
        const ymd = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
        issueNoEl.value = 'BI-' + ymd + '-' + String(Math.floor(1000 + Math.random() * 9000));
        issueNoEl.classList.add('bi-suggested');
    }

    if (issueNoEl) issueNoEl.addEventListener('input', () => issueNoEl.classList.remove('bi-suggested'));

    // --- Open create / edit ---------------------------------------------------
    function resetForm() {
        form.reset();
        methodEl.value = '';
        form.action = cfg.routes.store;
        editing = false;
        poId.value = '';
        items = [];
        loadedPoId = null;
        itemRows.innerHTML = '';
        resetMatrixChoices();
        form.classList.remove('bi-edit-mode');
        selectedRow.classList.add('d-none');
        overWarn.classList.add('d-none');
        poLoading.classList.add('d-none');
        poError.classList.add('d-none');
        // A fresh entry starts with the eleven fields back to finding a booking
        // rather than narrowing the last one's rows.
        closeSuggest();
        syncFilterMode();
        title.textContent = 'New Bulk Issue';
        saveLabel.textContent = 'Record Issue';
        if (issueDateEl) issueDateEl.value = new Date().toISOString().slice(0, 10);
        if (issueNoEl) issueNoEl.classList.remove('bi-suggested');
        refreshItemsState();
        // Clears any over-stock block left by the previous entry.
        checkOver();
        // reset:true tells the shell this is a fresh entry, so it re-reads the
        // remarks counter and offers any saved draft.
        publishState({ reset: true });
    }

    const newBtn = document.getElementById('biNewBtn');
    if (newBtn && panel) {
        newBtn.addEventListener('click', () => {
            resetForm();
            // Suggest up front so the field shows a real number on open.
            suggestIssueNo();
            panel.show();
        });
    }

    // On the create page there is nothing to open: the form is the page, so it
    // starts ready. Edit mode is left alone — openEdit() fills it instead.
    if (!panelEl && !document.querySelector('[data-bi-edit-id]')) suggestIssueNo();

    /**
     * Load an existing issue into the form for correction.
     *
     * Reached two ways: an Edit button in the history table (the panel shell),
     * or landing on /bulk-issues/{id}/edit, where the page itself names the
     * record. One loader either way — the prefill route is the same.
     */
    function openEdit(id) {
        resetForm();
        editing = true;
        // Hides the include column: correcting one issue is one row, always in.
        form.classList.add('bi-edit-mode');
        title.textContent = 'Edit Bulk Issue';
        saveLabel.textContent = 'Update';
        form.action = cfg.routes.update.replace('__ID__', encodeURIComponent(id));
        methodEl.value = 'PUT';
        // Correcting an existing issue: the PO is already settled, and the row
        // it loads arrives a moment later, so the shell treats an empty item
        // list in edit mode as "not fetched yet" rather than "nothing chosen".
        publishState({ reset: true });
        if (panel) panel.show();

        fetch(cfg.routes.show.replace('__ID__', encodeURIComponent(id)), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d) => {
                poId.value = d.booking_po_id;
                selectedText.textContent = esc(d.po_no) || '—';
                selectedRow.classList.remove('d-none');

                setField('biReq', d.material_requisition_id);
                setField('biSection', d.indent_section);
                setField('biPerson', d.indent_person);
                setField('biReqNo', d.requisition_number);
                setField('biIssueNo', d.issue_no);
                setField('biIssueDate', d.issue_date);
                setField('biRemarks', d.remarks);

                // Load the PO's lines so the edited item shows its identity and
                // available stock, then lay out the one row being corrected.
                loadItems(d.booking_po_id).then(() => {
                    const match = items.find((it) => String(it.excel_row_id) === String(d.excel_row_id));
                    addItemRow(match || {
                        excel_row_id: d.excel_row_id,
                        material_name: d.material_name,
                        material_description: d.material_description,
                        style_name: d.style_name,
                        art_no: d.art_no,
                        material_color: d.material_color,
                        size: d.size,
                        uom: d.uom,
                        available: 0,
                    }, {
                        bulk_qty: numOrBlank(d.bulk_qty),
                        sample_qty: numOrBlank(d.sample_qty),
                        liability_qty: numOrBlank(d.liability_qty),
                        dead_qty: numOrBlank(d.dead_qty),
                    });
                    refreshItemsState();
                });
            })
            .catch(() => {});
    }

    // Edit buttons in the (swappable) history table — panel shell only.
    const tableContainer = document.getElementById('biTableContainer');
    if (tableContainer) {
        tableContainer.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bi-edit]');
            if (!btn) return;
            openEdit(btn.getAttribute('data-bi-edit'));
        });
    }

    // Landing straight on /bulk-issues/{id}/edit: the page names the record, so
    // the same loader runs without a click.
    const editHost = document.querySelector('[data-bi-edit-id]');
    if (editHost) openEdit(editHost.getAttribute('data-bi-edit-id'));

    function setField(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val === null || val === undefined ? '' : val;
    }
    function numOrBlank(v) {
        const n = Number(v);
        return n ? fmtNum(n) : '';
    }

    form.addEventListener('submit', (e) => {
        if (!poId.value) {
            e.preventDefault();
            window.alert('Select a PO first.');
            return;
        }
        if (!itemRows.children.length) {
            e.preventDefault();
            window.alert('This PO has no items to issue.');
            return;
        }

        // The whole PO is on screen, but only the rows carrying a quantity are
        // the issue. store() rejects a row totalling zero outright, so an empty
        // row is dropped here rather than sent to fail — leaving it out is the
        // same mechanism the checkbox uses, so nothing typed is lost either way.
        if (!editing) {
            matrixRows().forEach((row) => {
                if (rowTotal(row) <= 0) setRowIncluded(row, false);
            });
            syncIncludeHead();

            const included = matrixRows().filter(isIncluded);
            if (!included.length) {
                e.preventDefault();
                window.alert('Enter a quantity on at least one item.');
                return;
            }
        }

        // Stock integrity is a hard rule, not a confirmation. The server rejects
        // the same case, so letting it through here would only waste a round trip.
        if (checkOver()) {
            e.preventDefault();
            overWarn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        // A row whose balance ran out between loading and saving. Only the rows
        // being submitted matter — an out-of-stock line the user never touched
        // is excluded already and is not their problem.
        const empty = matrixRows()
            .filter(isIncluded)
            .filter((card) => (parseFloat(card.dataset.available) || 0) <= 0);
        if (empty.length) {
            e.preventDefault();
            window.alert('Some items have no available stock. Untick them and try again.');
        }
    });

    refreshItemsState();
}
