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

import { createColumnFilter } from './column-filter';

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

    const filterType = document.getElementById('biFilterType');
    const poSearch = document.getElementById('biPoSearch');
    const poLoading = document.getElementById('biPoLoading');
    const poError = document.getElementById('biPoError');
    const poPanel = document.getElementById('biPoPanel');
    const poList = document.getElementById('biPoList');
    const poHint = document.getElementById('biPoHint');
    const poSpin = document.getElementById('biPoSpin');
    const poClear = document.getElementById('biPoClear');
    const poChip = document.getElementById('biPoChip');
    const selectedRow = document.getElementById('biSelectedRow');
    const selectedText = document.getElementById('biSelectedText');
    const summary = document.getElementById('biSummaryGrid');
    const sumCounts = document.getElementById('biSumCounts');
    const pickBtn = document.getElementById('biPickBtn');

    const itemRows = document.getElementById('biItemRows');
    const noItems = document.getElementById('biNoItems');
    const addMoreWrap = document.getElementById('biAddMoreWrap');
    const overWarn = document.getElementById('biOverWarn');
    const overText = document.getElementById('biOverText');
    const issueNoEl = document.getElementById('biIssueNo');
    const issueDateEl = document.getElementById('biIssueDate');

    const modalEl = document.getElementById('biItemsModal');
    const step1 = document.getElementById('biStep1');
    const step2 = document.getElementById('biStep2');
    const styleBody = document.getElementById('biStyleBody');
    const itemBody = document.getElementById('biItemBody');
    const styleAll = document.getElementById('biStyleAll');
    const itemAll = document.getElementById('biItemAll');
    const backBtn = document.getElementById('biBackBtn');
    const nextBtn = document.getElementById('biNextBtn');
    const addBtn = document.getElementById('biAddSelected');
    const pickCount = document.getElementById('biPickCount');
    const pickCountLabel = document.getElementById('biPickCountLabel');
    const pickCountWrap = document.getElementById('biSelCountWrap');
    const modalLoading = document.getElementById('biModalLoading');
    const modalError = document.getElementById('biModalError');
    const modalPo = document.getElementById('biModalPo');
    const crumb1 = document.getElementById('biCrumb1');
    const crumb2 = document.getElementById('biCrumb2');
    const pickBar = document.getElementById('biPickBar');
    const filterInput = document.getElementById('biFilter');
    const filterClear = document.getElementById('biFilterClear');
    const selectAllBtn = document.getElementById('biSelectAllBtn');
    const selectAllText = document.getElementById('biSelectAllText');
    const showingEl = document.getElementById('biShowing');
    const noMatchEl = document.getElementById('biNoMatch');

    // contract_po is the buyer's order/contract PO (GMNTS PO Number / Initial
    // Contract Number) — a separate identifier from the material PO that po_no
    // searches, which is why both keys exist here.
    const LABELS = {
        po_no: 'Material PO',
        contract_po: 'PO Number',
        season: 'Season',
        buyer: 'Buyer Name',
        style: 'Style Number',
        material_name: 'Material Name',
        material_description: 'Material Description',
        sap_code: 'SAP Code',
        art_no: 'Art. No',
        gmts_color: 'GMTS Color Name',
        material_color: 'Material Color',
        size: 'Size',
    };

    // Spelled out rather than built by appending "s", which turned
    // "Garments PO" into "Garments POs". Each entry is the wording that reads
    // correctly inside "Click or type to browse …".
    const LABELS_PLURAL = {
        po_no: 'Material PO numbers',
        contract_po: 'PO Numbers',
        season: 'Seasons',
        buyer: 'Buyer names',
        style: 'Style numbers',
        material_name: 'Material names',
        material_description: 'Material descriptions',
        sap_code: 'SAP Codes',
        art_no: 'Art. Nos',
        gmts_color: 'GMTS Color names',
        material_color: 'Material Colors',
        size: 'Sizes',
    };
    const DEBOUNCE_MS = 300;

    let items = [];        // every material line under the loaded PO
    let loadedPoId = null;
    let uid = 0;
    // Edit mode reuses the same panel but corrects exactly one existing issue,
    // so its single row posts flat field names instead of the rows[] array.
    let editing = false;

    // --- Step 1: find the PO --------------------------------------------------
    let searchTimer = null;
    let searchTicket = 0;
    let activeIndex = -1;
    let searching = false;

    function syncSearchStatus() {
        const hasText = poSearch.value !== '';
        poSpin.classList.toggle('d-none', !searching);
        poClear.classList.toggle('d-none', searching || !hasText);
    }

    function openSuggest() { poPanel.classList.remove('d-none'); poSearch.setAttribute('aria-expanded', 'true'); }
    function closeSuggest() { poPanel.classList.add('d-none'); poSearch.setAttribute('aria-expanded', 'false'); activeIndex = -1; }

    // Browse list per filter type, fetched once and kept for the page's life.
    // `complete` means the server sent the whole dataset, so typing can be
    // filtered here instead of going back for every keystroke.
    const browseCache = {};
    const browseInFlight = {};

    /**
     * The picker is two steps for every search type.
     *
     * Step 1 lists what the chosen field actually holds — each Season, each
     * Material Name, each Size, once. Step 2 lists the bookings under the one
     * value the user picked. Before this, a field like Buyer repeated "Hugo
     * Boss" once per booking, which read as a duplicate bug rather than as ten
     * different bookings.
     *
     * pickedValue is the whole of the state: null means step 1.
     */
    let pickedValue = null;

    const browseKey = (type) => type + '|' + (pickedValue === null ? '' : pickedValue);

    function loadBrowse(type) {
        const key = browseKey(type);
        if (browseCache[key]) return Promise.resolve(browseCache[key]);
        if (browseInFlight[key]) return browseInFlight[key];

        const url = cfg.routes.poSearch + '?type=' + encodeURIComponent(type) +
            (pickedValue === null ? '' : '&value=' + encodeURIComponent(pickedValue));

        browseInFlight[key] = fetch(url, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                browseCache[key] = {
                    results: data.results || [],
                    complete: !!data.complete,
                    step: data.step || 1,
                };
                return browseCache[key];
            })
            .finally(() => { delete browseInFlight[key]; });

        return browseInFlight[key];
    }

    /** The chosen step 1 value, shown as a chip so it can be seen and undone. */
    function syncPickChip() {
        if (!poChip) return;

        if (pickedValue === null) {
            poChip.classList.add('d-none');
            poChip.innerHTML = '';
            return;
        }

        poChip.classList.remove('d-none');
        poChip.innerHTML = '<span class="bi-pickchip">' +
            '<span class="bi-pickchip-label">' + h(LABELS[filterType.value] || '') + '</span>' +
            '<span class="bi-pickchip-value">' + h(pickedValue) + '</span>' +
            '<button type="button" class="bi-pickchip-x" data-bi-pickclear aria-label="Clear this value and choose another">&times;</button>' +
            '</span>';
    }

    /** Back to step 1: a different value, or a different field entirely. */
    function clearPickedValue() {
        pickedValue = null;
        poSearch.value = '';
        syncPickChip();
        syncSearchStatus();
        poSearch.placeholder = 'Click or type to browse ' + LABELS_PLURAL[filterType.value] + '…';
        showBrowse();
        poSearch.focus();
    }

    if (poChip) {
        poChip.addEventListener('click', (e) => {
            if (e.target.closest('[data-bi-pickclear]')) clearPickedValue();
        });
    }

    // Opening the field shows what exists — no typing required.
    function showBrowse() {
        const type = filterType.value;
        const ticket = ++searchTicket;
        openSuggest();

        if (!browseCache[type]) {
            searching = true;
            syncSearchStatus();
            poHint.textContent = 'Loading…';
            poList.innerHTML = '';
        }

        return loadBrowse(type)
            .then((data) => {
                if (ticket !== searchTicket) return;
                searching = false;
                syncSearchStatus();
                renderResults(filterLocally(data.results, poSearch.value.trim()), type, poSearch.value.trim(), data);
            })
            .catch(() => {
                if (ticket !== searchTicket) return;
                searching = false;
                syncSearchStatus();
                poHint.textContent = '';
                poList.innerHTML = '<div class="list-group-item text-muted">Could not load the list. Please try again.</div>';
            });
    }

    function filterLocally(results, term) {
        const needle = term.toLowerCase();
        if (needle === '') return results;

        return results.filter((r) =>
            [r.value, r.po_no, r.buyer_name, r.season_name, r.style_name, r.vendor_name]
                .some((f) => esc(f).toLowerCase().includes(needle)));
    }

    function runSearch() {
        const type = filterType.value;
        const term = poSearch.value.trim();
        const ticket = ++searchTicket;

        searching = true;
        syncSearchStatus();
        openSuggest();
        poHint.textContent = 'Searching…';
        poList.innerHTML = '';

        // A term narrows whichever step is showing: the value list, or the
        // bookings under the value already chosen.
        fetch(cfg.routes.poSearch + '?type=' + encodeURIComponent(type) + '&term=' + encodeURIComponent(term) +
            (pickedValue === null ? '' : '&value=' + encodeURIComponent(pickedValue)), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                if (ticket !== searchTicket) return;
                searching = false;
                syncSearchStatus();
                renderResults(data.results || [], type, term, data);
            })
            .catch(() => {
                if (ticket !== searchTicket) return;
                searching = false;
                syncSearchStatus();
                poHint.textContent = '';
                poList.innerHTML = '<div class="list-group-item text-muted">Could not load the list. Please try again.</div>';
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

    function renderResults(results, type, term, source) {
        activeIndex = -1;

        if (!results.length) {
            poHint.textContent = '';
            poList.innerHTML = '<div class="bi-opt-empty">' +
                '<span class="bi-opt-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>' +
                '<div class="bi-opt-empty-title">No matches found</div>' +
                '<p class="bi-opt-empty-text">' +
                    (term ? 'Nothing here matches “' + h(term) + '”. Try a shorter term'
                          : 'This field has no values on record yet') +
                    (pickedValue === null ? ', or pick a different field above.' : ', or clear the value above.') +
                '</p></div>';
            return;
        }

        const browsing = term === '' && source && source.complete;

        // --- Step 1: the field's own values, each once ------------------------
        if (pickedValue === null) {
            poHint.textContent = browsing
                ? 'All ' + LABELS_PLURAL[type] + ' (' + results.length + ')'
                : results.length + (results.length === 1 ? ' match' : ' matches');

            poList.innerHTML = results.map((r) => {
                const n = Number(r.count) || 0;
                // Several bookings under one value is the case worth spotting
                // while scanning — that value is where the work is.
                const heavy = n > 1 ? ' is-many' : '';

                return '<div class="list-group-item bi-opt bi-opt-row" role="option" tabindex="-1"' +
                    ' data-bi-pick="' + h(r.value) + '">' +
                    avatarFor(r.value) +
                    '<div class="bi-opt-body"><div class="bi-opt-primary">' + dash(r.value) + '</div></div>' +
                    '<span class="bi-opt-count' + heavy + '">' + n +
                        '<span class="bi-opt-count-unit">' + (n === 1 ? 'booking' : 'bookings') + '</span></span>' +
                    '</div>';
            }).join('');
            return;
        }

        // --- Step 2: the bookings under the value chosen above ----------------
        poHint.textContent = results.length + (results.length === 1 ? ' booking' : ' bookings') +
            ' under ' + LABELS[type] + ' “' + h(pickedValue) + '”';

        // Whatever identified step 1 is already stated on the chip above, so it
        // is left out here rather than repeated on every row.
        const skip = {
            po_no: 'po_no', contract_po: 'po_no', buyer: 'buyer_name',
            season: 'season_name', style: 'style_name',
        }[type];

        poList.innerHTML = results.map((r) => {
            const meta = [
                skip === 'po_no' ? null : r.po_no,
                skip === 'buyer_name' ? null : r.buyer_name,
                skip === 'style_name' ? null : r.style_name,
                skip === 'season_name' ? null : r.season_name,
                r.vendor_name,
            ].filter(Boolean).join(' · ');

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

    filterType.addEventListener('change', () => {
        // A new field means a new question, so any value chosen under the old
        // one goes with it rather than scoping a list it no longer describes.
        pickedValue = null;
        syncPickChip();
        poSearch.placeholder = 'Click or type to browse ' + LABELS_PLURAL[filterType.value] + '…';
        poSearch.value = '';
        closeSuggest();
        syncSearchStatus();
        // The selector is part of the search control, so changing it reopens the
        // list for the new type rather than leaving an empty box.
        poSearch.focus();
        showBrowse();
    });

    poSearch.addEventListener('focus', showBrowse);
    poSearch.addEventListener('click', showBrowse);

    poSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const term = poSearch.value.trim();
        const cached = browseCache[filterType.value];
        syncSearchStatus();

        // Whole dataset already in hand — filter here, no request, no debounce.
        if (cached && cached.complete) {
            searchTicket++;
            searching = false;
            syncSearchStatus();
            openSuggest();
            renderResults(filterLocally(cached.results, term), filterType.value, term, cached);
            return;
        }

        if (term === '') { showBrowse(); return; }
        searchTimer = setTimeout(runSearch, DEBOUNCE_MS);
    });

    poClear.addEventListener('click', () => {
        clearTimeout(searchTimer);
        searchTicket++;
        searching = false;
        poSearch.value = '';
        syncSearchStatus();
        showBrowse();
        poSearch.focus();
    });

    const options = () => Array.from(poList.querySelectorAll('.bi-opt'));

    poSearch.addEventListener('keydown', (e) => {
        const open = !poPanel.classList.contains('d-none');
        const list = options();

        if (e.key === 'Escape') { closeSuggest(); return; }
        if (e.key === 'Enter') {
            // Never submit the form from the search box.
            e.preventDefault();
            if (open && list.length) chooseOption(activeIndex >= 0 ? list[activeIndex] : list[0]);
            return;
        }
        if (!open || !list.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = (activeIndex + 1) % list.length; }
        else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = (activeIndex - 1 + list.length) % list.length; }
        else return;

        list.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
        list[activeIndex].scrollIntoView({ block: 'nearest' });
    });

    /**
     * One row, whichever step it belongs to: a step 1 row answers "which
     * value", a step 2 row answers "which booking". Mouse and keyboard both
     * come through here so the two cannot drift apart.
     */
    function chooseOption(opt) {
        if (!opt) return;

        if (opt.dataset.biPick !== undefined) {
            pickedValue = opt.dataset.biPick;
            poSearch.value = '';
            syncPickChip();
            syncSearchStatus();
            poSearch.placeholder = 'Search bookings under this ' + (LABELS[filterType.value] || 'value') + '…';
            showBrowse();
            poSearch.focus();
            return;
        }

        selectPo(opt);
    }

    poList.addEventListener('click', (e) => chooseOption(e.target.closest('.bi-opt')));

    document.addEventListener('click', (e) => {
        const wrap = document.getElementById('biSearchWrap');
        if (wrap && !wrap.contains(e.target)) closeSuggest();
    });

    // --- Step 2: lock the selection ------------------------------------------
    function selectPo(optEl) {
        const newId = optEl.dataset.id;

        if (itemRows.children.length && String(newId) !== String(poId.value)) {
            if (!window.confirm('Changing the PO will clear the items already added. Continue?')) return;
            itemRows.innerHTML = '';
        }

        poId.value = newId;
        selectedText.textContent = optEl.dataset.po || '—';
        selectedRow.classList.remove('d-none');
        poSearch.value = '';
        syncSearchStatus();
        closeSuggest();
        loadItems(newId);
        refreshItemsState();
    }

    document.getElementById('biClearPo').addEventListener('click', () => {
        if (itemRows.children.length &&
            !window.confirm('Clearing the PO will remove the items already added. Continue?')) return;

        itemRows.innerHTML = '';
        poId.value = '';
        selectedRow.classList.add('d-none');
        items = [];
        loadedPoId = null;
        poSearch.value = '';
        poLoading.classList.add('d-none');
        poError.classList.add('d-none');
        syncSearchStatus();
        refreshItemsState();
        checkOver();
        poSearch.focus();
        showBrowse();
    });

    function setSummary(data) {
        summary.querySelectorAll('[data-sum]').forEach((el) => {
            const v = data ? data[el.getAttribute('data-sum')] : null;
            el.textContent = (v === null || v === undefined || String(v).trim() === '') ? '—' : String(v);
        });
        sumCounts.textContent = data ? (styleNames().length + ' style(s) · ' + items.length + ' item(s)') : '—';
    }

    // --- Steps 3-4: the item picker ------------------------------------------
    const styleKey = (item) => (esc(item.style_name) === '' ? '—' : esc(item.style_name));
    const styleNames = () => [...new Set(items.map(styleKey))];
    const addedRowIds = () => Array.from(itemRows.querySelectorAll('[data-row-id]')).map((el) => String(el.dataset.rowId));

    function loadItems(id) {
        modalLoading.classList.remove('d-none');
        step1.classList.add('d-none');
        step2.classList.add('d-none');
        modalError.classList.add('d-none');
        // Nothing to filter or select all of until the rows arrive.
        pickBar.classList.add('d-none');
        noMatchEl.classList.add('d-none');
        setSummary(null);

        // The lookup is started from the panel, so its progress and any failure
        // have to be visible there — not only inside a modal that may be closed.
        poLoading.classList.remove('d-none');
        poError.classList.add('d-none');
        pickBtn.disabled = true;

        return fetch(cfg.routes.poItems.replace('__ID__', encodeURIComponent(id)), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                if (String(poId.value) !== String(id)) return;   // changed meanwhile
                items = data.items || [];
                loadedPoId = id;
                // A different PO means the remembered ticks refer to lines that
                // are no longer on screen.
                clearPicked();
                setSummary(data);
                pickBtn.disabled = items.length === 0;
                modalPo.textContent = 'PO ' + (esc(data.po_no) || '—') +
                    ' · ' + styleNames().length + ' style(s) · ' + items.length + ' item(s)';

                if (!items.length) {
                    poError.textContent = 'This PO has no material lines to issue against.';
                    poError.classList.remove('d-none');
                }
            })
            .catch((status) => {
                if (String(poId.value) !== String(id)) return;
                loadedPoId = null;
                items = [];
                modalLoading.classList.add('d-none');
                pickBar.classList.add('d-none');
                const message = status === 423
                    ? 'This file/style is locked. Stock entry is not allowed.'
                    : 'Could not load the items for this PO. Please try again.';
                modalError.textContent = message;
                modalError.classList.remove('d-none');
                poError.textContent = message;
                poError.classList.remove('d-none');
            })
            .finally(() => {
                if (String(poId.value) !== String(id)) return;
                poLoading.classList.add('d-none');
            });
    }

    /**
     * The picker's ticks, held outside the table markup.
     *
     * renderStyles() and renderItems() rebuild their tbody from scratch on every
     * step change, which threw away every checkbox the user had set: stepping
     * Back to add a style and forward again silently dropped the items already
     * chosen. Keeping the selection here and re-applying it after each rebuild
     * is what makes Back/Next non-destructive.
     */
    let pickedStyles = [];
    let pickedItems = [];
    const clearPicked = () => { pickedStyles = []; pickedItems = []; };

    function showStep(step) {
        modalLoading.classList.add('d-none');
        modalError.classList.add('d-none');
        step1.classList.toggle('d-none', step !== 1);
        step2.classList.toggle('d-none', step !== 2);
        // The style step is always part of the flow, including the single-style
        // case: issuing against the wrong style is not a recoverable mistake, so
        // the style is confirmed explicitly rather than assumed.
        backBtn.classList.toggle('d-none', step !== 2);
        nextBtn.classList.toggle('d-none', step !== 1);
        addBtn.classList.toggle('d-none', step !== 2);
        crumb1.classList.toggle('is-current', step === 1);
        crumb1.classList.toggle('is-done', step === 2);
        crumb2.classList.toggle('is-current', step === 2);

        // The two steps filter on different columns, so the box starts empty on
        // each one rather than carrying a style term into the item list.
        filterInput.value = '';
        pickBar.classList.remove('d-none');

        // Column filters belong to one pass through the item list. Returning to
        // the style step can change which items exist at all, so a filter left
        // over from the previous pass could hide rows the user just chose.
        closePickerMenus();
        pickerFilter.clearAll();

        if (step === 1) renderStyles(); else renderItems();

        // Restart the entrance animation on the panel that just took over.
        const panel = step === 1 ? step1 : step2;
        panel.classList.remove('bi-step-in');
        void panel.offsetWidth;
        panel.classList.add('bi-step-in');
    }

    function renderStyles() {
        const already = addedRowIds();

        // Capture the ticks before the tbody they live in is replaced.
        if (styleBody.querySelector('.bi-style-cb')) pickedStyles = chosenStyles();

        styleBody.innerHTML = styleNames().map((name, i) => {
            const under = items.filter((it) => styleKey(it) === name);
            const allAdded = under.every((it) => already.includes(String(it.excel_row_id)));
            const avail = under.reduce((sum, it) => sum + (parseFloat(it.available) || 0), 0);
            // Nothing under this style can be issued, so there is nothing to
            // choose here either.
            const noStock = !under.some((it) => (parseFloat(it.available) || 0) > 0);
            const blocked = allAdded || noStock;
            const why = noStock ? 'No available stock under this style' : 'Every item under this style is already added';

            return '<tr class="' + (blocked ? (noStock ? 'is-empty' : 'is-added') : 'bi-row') + '">' +
                '<td><input type="checkbox" class="form-check-input bi-style-cb" id="biStyle' + i + '"' +
                    ' value="' + h(name) + '"' + (blocked ? ' disabled title="' + why + '"' : '') +
                    ' aria-label="Select style ' + h(name) + '"></td>' +
                '<td class="bi-cell-primary fw-semibold">' + dash(name) + '</td>' +
                '<td class="text-end small">' + under.length + '</td>' +
                '<td class="text-end small">' + (noStock
                    ? '<span class="badge bg-secondary-subtle text-secondary-emphasis">Out of stock</span>'
                    : fmtNum(avail)) + '</td>' +
            '</tr>';
        }).join('');

        const boxes = Array.from(styleBody.querySelectorAll('.bi-style-cb:not(:disabled)'));

        if (pickedStyles.length) {
            // Put back what the user had chosen before stepping away.
            boxes.forEach((cb) => { cb.checked = pickedStyles.indexOf(cb.value) !== -1; });
        } else if (boxes.length === 1) {
            // A PO with one style still shows this step, but there is nothing to
            // decide — so it comes pre-ticked and the user only confirms it.
            boxes[0].checked = true;
        }

        // applyFilter() finishes with updatePickCount(), so the toolbar counts
        // and the footer badge are refreshed from one place.
        applyFilter();
    }

    const chosenStyles = () => Array.from(styleBody.querySelectorAll('.bi-style-cb:checked')).map((cb) => cb.value);

    /**
     * Excel-style column filter over the item picker's list, sharing its rules
     * with the Bulk Issuing Full Table through column-filter.js — one set of
     * semantics, two renderers.
     *
     * Scoped to the styles confirmed at step 1, so a value list never offers a
     * colour or SAP code from a style the user did not pick.
     */
    const PICKER_COLUMNS = [
        // Style leads the table and is filterable in its own right. It replaces
        // the separate "choose the style first" step: issuing against the wrong
        // style is not recoverable, so the style must be readable on every row
        // rather than confirmed once and then out of sight.
        { key: 'style_name' },
        { key: 'material_name' },
        { key: 'material_description' },
        { key: 'art_no' },
        { key: 'sap_code' },
        { key: 'gmts_color_name' },
        { key: 'material_color' },
        { key: 'size' },
        { key: 'uom' },
        { key: 'available', type: 'num' },
    ];

    const pickerFilter = createColumnFilter({
        columns: PICKER_COLUMNS,
        getRows: () => {
            const styles = chosenStyles();
            return items.filter((it) => styles.indexOf(styleKey(it)) !== -1);
        },
    });

    let openPickerMenu = '';

    function renderItems() {
        const already = addedRowIds();

        // Capture the ticks before the tbody they live in is replaced. Already-
        // added rows are excluded: they are checked because they are disabled,
        // not because the user chose them in this pass.
        if (itemBody.querySelector('.bi-item-cb')) {
            pickedItems = Array.from(itemBody.querySelectorAll('.bi-item-cb:not(:disabled)'))
                .filter((cb) => cb.checked).map((cb) => cb.value);
        }
        // Only the styles the user confirmed at step 1 are in scope, narrowed
        // further by whatever the column filters allow. apply() has already
        // sorted, so slicing per style keeps that order inside each group.
        const styles = chosenStyles();
        const allowed = pickerFilter.apply();
        let html = '';

        styles.forEach((name) => {
            const under = allowed.filter((it) => styleKey(it) === name);

            if (styles.length > 1) {
                html += '<tr class="bi-group-row"><td colspan="11">' +
                    '<i class="bi bi-tag me-1" aria-hidden="true"></i>Style ' + dash(name) +
                    ' <span class="fw-normal">· ' + under.length + ' item(s)</span></td></tr>';
            }

            under.forEach((item, i) => {
                const isAdded = already.includes(String(item.excel_row_id));
                const avail = parseFloat(item.available) || 0;
                // Stock integrity: an item with nothing on hand cannot be issued,
                // so it is shown for reference but never selectable.
                const noStock = avail <= 0;
                const cbId = 'biItem' + h(name).replace(/\W/g, '') + i;
                const rowCls = isAdded ? 'is-added' : (noStock ? 'is-empty' : 'bi-row');

                html += '<tr class="' + rowCls + '">' +
                    '<td><input type="checkbox" class="form-check-input bi-item-cb" id="' + cbId + '"' +
                        ' value="' + h(item.excel_row_id) + '"' +
                        (isAdded ? ' checked disabled title="Already added below"'
                                 : (noStock ? ' disabled title="No available stock"' : '')) +
                        ' aria-label="Select material line ' + h(item.material_name) + '"></td>' +

                    // One cell per field, matching the header columns so each is
                    // filterable on its own. GMTS colour is the garment's,
                    // material colour is the trim's — separate columns, not one.
                    '<td><span class="bi-style-tag">' + dash(item.style_name) + '</span></td>' +
                    '<td><div class="bi-cell-primary">' + dash(item.material_name) + '</div></td>' +
                    '<td class="bi-ft-wide"><div class="bi-cell-sub" title="' + h(item.material_description) + '">' +
                        dash(item.material_description) + '</div></td>' +
                    '<td class="small">' + dash(item.art_no) + '</td>' +
                    '<td class="small">' + dash(item.sap_code) + '</td>' +
                    '<td class="small">' + dash(item.gmts_color_name) + '</td>' +
                    '<td class="small">' + dash(item.material_color) + '</td>' +
                    '<td class="small">' + dash(item.size) + '</td>' +
                    '<td class="small">' + dash(item.uom) + '</td>' +
                    '<td class="small text-end">' + (noStock
                        ? '<span class="badge bg-secondary-subtle text-secondary-emphasis">Out of stock</span>'
                        : '<span class="fw-semibold">' + fmtNum(avail) + '</span>') + '</td>' +
                '</tr>';
            });
        });

        itemBody.innerHTML = html;

        // Re-tick what was already chosen. An item that fell out of scope because
        // its style was unticked simply has no checkbox left to match.
        Array.from(itemBody.querySelectorAll('.bi-item-cb:not(:disabled)'))
            .forEach((cb) => { if (pickedItems.indexOf(cb.value) !== -1) cb.checked = true; });

        syncPickerHeads();
        applyFilter();
    }

    // --- Item picker column filters -------------------------------------------
    /**
     * The dropdown for one column, built with the same markup and classes as the
     * Full Table's so the two look and behave identically. Rendered on demand:
     * a picker can hold hundreds of values across nine columns, and building all
     * of them up front would cost more than it saves.
     */
    function renderPickerMenu(key) {
        const menu = document.querySelector('[data-bi-pmenu="' + key + '"]');
        if (!menu) return;

        const values = pickerFilter.valuesFor(key);
        const opts = values.map((v) => '<label class="bi-ft-mopt">' +
            '<input type="checkbox" class="form-check-input" data-bi-pval="' + h(v) + '"' +
            (pickerFilter.isChecked(key, v) ? ' checked' : '') + '>' +
            '<span>' + h(v) + '</span></label>').join('');

        menu.innerHTML =
            '<button type="button" class="bi-ft-mitem" data-bi-psort="asc"><i class="bi bi-sort-alpha-down" aria-hidden="true"></i>Sort A to Z</button>' +
            '<button type="button" class="bi-ft-mitem" data-bi-psort="desc"><i class="bi bi-sort-alpha-up-alt" aria-hidden="true"></i>Sort Z to A</button>' +
            '<div class="bi-ft-msep"></div>' +
            '<div class="bi-ft-msearch"><i class="bi bi-search" aria-hidden="true"></i>' +
                '<input type="text" class="form-control form-control-sm" data-bi-pneedle placeholder="Search values…"' +
                ' value="' + h(pickerFilter.needles[key] || '') + '" aria-label="Search values"></div>' +
            '<label class="bi-ft-mall"><input type="checkbox" class="form-check-input" data-bi-pall' +
                (pickerFilter.allChecked(key) ? ' checked' : '') + '><span>(Select All)</span></label>' +
            '<div class="bi-ft-mlist">' + (opts || '<div class="bi-ft-mempty">No matching values</div>') + '</div>' +
            '<div class="bi-ft-mfoot">' +
                '<button type="button" class="btn btn-sm btn-link text-decoration-none p-0" data-bi-pclear>Clear filter</button>' +
                '<button type="button" class="btn btn-sm btn-primary bi-btn-xs" data-bi-pdone>Done</button>' +
            '</div>';
    }

    /** Funnel icons follow the filter state, as they do on the Full Table. */
    function syncPickerHeads() {
        document.querySelectorAll('[data-bi-pfilter]').forEach((btn) => {
            const key = btn.getAttribute('data-bi-pfilter');
            const on = pickerFilter.isFiltered(key) || pickerFilter.sortKey === key;
            btn.classList.toggle('is-on', on);
            const icon = btn.querySelector('i');
            if (icon) icon.className = pickerFilter.isFiltered(key) ? 'bi bi-funnel-fill' : 'bi bi-chevron-down';
        });
    }

    function closePickerMenus() {
        openPickerMenu = '';
        document.querySelectorAll('[data-bi-pmenu]').forEach((m) => m.classList.add('d-none'));
        document.querySelectorAll('[data-bi-pfilter]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
    }

    const itemTableEl = document.getElementById('biItemTable');

    if (itemTableEl) {
        itemTableEl.addEventListener('click', (e) => {
            // Open / close a column's menu.
            const trigger = e.target.closest('[data-bi-pfilter]');
            if (trigger) {
                const key = trigger.getAttribute('data-bi-pfilter');
                const wasOpen = openPickerMenu === key;
                closePickerMenus();
                if (!wasOpen) {
                    openPickerMenu = key;
                    pickerFilter.needles[key] = '';
                    renderPickerMenu(key);

                    const menuEl = document.querySelector('[data-bi-pmenu="' + key + '"]');
                    menuEl.classList.remove('d-none');
                    // The picker table scrolls sideways, and an absolutely
                    // positioned menu inside that box would be clipped by it.
                    // Pinned to the viewport instead, under its own header.
                    const box = trigger.getBoundingClientRect();
                    const width = menuEl.offsetWidth || 250;
                    menuEl.style.top = Math.round(box.bottom + 4) + 'px';
                    menuEl.style.left = Math.round(Math.min(box.left, window.innerWidth - width - 12)) + 'px';

                    trigger.setAttribute('aria-expanded', 'true');
                }
                return;
            }

            const menu = e.target.closest('[data-bi-pmenu]');
            if (!menu) { closePickerMenus(); return; }

            const key = menu.getAttribute('data-bi-pmenu');

            const sort = e.target.closest('[data-bi-psort]');
            if (sort) {
                pickerFilter.sortBy(key, sort.getAttribute('data-bi-psort'));
                closePickerMenus();
                renderItems();
                return;
            }

            if (e.target.closest('[data-bi-pclear]')) {
                pickerFilter.clearFilter(key);
                closePickerMenus();
                renderItems();
                return;
            }

            if (e.target.closest('[data-bi-pdone]')) { closePickerMenus(); return; }
        });

        itemTableEl.addEventListener('change', (e) => {
            const menu = e.target.closest('[data-bi-pmenu]');
            if (!menu) return;
            const key = menu.getAttribute('data-bi-pmenu');

            if (e.target.hasAttribute('data-bi-pall')) {
                pickerFilter.toggleAll(key);
            } else if (e.target.hasAttribute('data-bi-pval')) {
                pickerFilter.toggleValue(key, e.target.getAttribute('data-bi-pval'));
            } else {
                return;
            }

            // The list is rebuilt rather than patched: ticking one value changes
            // what the OTHER columns can offer, and the row list behind it.
            renderPickerMenu(key);
            renderItems();
        });

        itemTableEl.addEventListener('input', (e) => {
            if (!e.target.hasAttribute('data-bi-pneedle')) return;
            const menu = e.target.closest('[data-bi-pmenu]');
            const key = menu.getAttribute('data-bi-pmenu');
            pickerFilter.needles[key] = e.target.value;

            // Only the value list changes while typing, so the caret stays put.
            const list = menu.querySelector('.bi-ft-mlist');
            const values = pickerFilter.valuesFor(key);
            list.innerHTML = values.length
                ? values.map((v) => '<label class="bi-ft-mopt">' +
                    '<input type="checkbox" class="form-check-input" data-bi-pval="' + h(v) + '"' +
                    (pickerFilter.isChecked(key, v) ? ' checked' : '') + '>' +
                    '<span>' + h(v) + '</span></label>').join('')
                : '<div class="bi-ft-mempty">No matching values</div>';
        });
    }

    const onItemStep = () => step1.classList.contains('d-none');
    const activeBoxes = () => Array.from(
        (onItemStep() ? itemBody : styleBody)
            .querySelectorAll(onItemStep() ? '.bi-item-cb:not(:disabled)' : '.bi-style-cb:not(:disabled)'));

    /**
     * The boxes the filter is currently showing.
     *
     * Select all — the header checkbox and the toolbar button — acts on these, so
     * "filter, then take the lot" is one gesture. The selected COUNT stays on
     * activeBoxes(): a tick made before the filter was typed is still a real
     * selection and must not appear to vanish.
     */
    const visibleBoxes = () => activeBoxes().filter((cb) => {
        const tr = cb.closest('tr');
        return tr && !tr.classList.contains('is-filtered');
    });

    /**
     * Client-side row filter over the row's own rendered text, so it covers
     * material, art no, SAP code, colour, size and unit without the markup
     * having to declare which columns are searchable.
     */
    function applyFilter() {
        const body = onItemStep() ? itemBody : styleBody;
        const term = (filterInput.value || '').trim().toLowerCase();
        const rows = Array.from(body.querySelectorAll('tr'));
        let total = 0;
        let shown = 0;

        rows.forEach((tr) => {
            if (tr.classList.contains('bi-group-row')) return;   // handled below
            total += 1;
            const hit = !term || tr.textContent.toLowerCase().indexOf(term) !== -1;
            tr.classList.toggle('is-filtered', !hit);
            if (hit) shown += 1;
        });

        // A style heading with nothing left under it is noise, so it follows its
        // own rows out of the list.
        rows.filter((tr) => tr.classList.contains('bi-group-row')).forEach((head) => {
            let any = false;
            for (let el = head.nextElementSibling; el && !el.classList.contains('bi-group-row'); el = el.nextElementSibling) {
                if (!el.classList.contains('is-filtered')) { any = true; break; }
            }
            head.classList.toggle('is-filtered', !any);
        });

        filterClear.classList.toggle('d-none', term === '');
        noMatchEl.classList.toggle('d-none', !(total > 0 && shown === 0));
        showingEl.textContent = term
            ? shown + ' of ' + total + ' shown'
            : total + (total === 1 ? ' row' : ' rows');

        updatePickCount();
    }

    function updatePickCount() {
        const boxes = activeBoxes();
        const shownBoxes = visibleBoxes();
        const checked = boxes.filter((cb) => cb.checked);
        const onItems = onItemStep();

        pickCount.textContent = checked.length;
        pickCountLabel.textContent = onItems
            ? (checked.length === 1 ? 'item selected' : 'items selected')
            : (checked.length === 1 ? 'style selected' : 'styles selected');
        pickCountWrap.classList.toggle('is-active', checked.length > 0);

        nextBtn.disabled = onItems ? false : checked.length === 0;
        addBtn.disabled = checked.length === 0;

        const master = onItems ? itemAll : styleAll;
        master.disabled = shownBoxes.length === 0;
        master.checked = shownBoxes.length > 0 && shownBoxes.every((cb) => cb.checked);

        // The button mirrors the header checkbox, spelled out. On a one- or
        // two-item PO it is the whole selection in a single click.
        const allShown = shownBoxes.length > 0 && shownBoxes.every((cb) => cb.checked);
        selectAllBtn.disabled = shownBoxes.length === 0;
        selectAllText.textContent = allShown
            ? 'Clear all'
            : 'Select all' + (shownBoxes.length ? ' (' + shownBoxes.length + ')' : '');

        boxes.forEach((cb) => {
            const tr = cb.closest('tr');
            if (tr) tr.classList.toggle('is-checked', cb.checked);
        });
    }

    filterInput.addEventListener('input', applyFilter);
    filterInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { filterInput.value = ''; applyFilter(); }
    });
    filterClear.addEventListener('click', () => {
        filterInput.value = '';
        filterInput.focus();
        applyFilter();
    });
    selectAllBtn.addEventListener('click', () => {
        const shownBoxes = visibleBoxes();
        const allShown = shownBoxes.length > 0 && shownBoxes.every((cb) => cb.checked);
        shownBoxes.forEach((cb) => { cb.checked = !allShown; });
        updatePickCount();
    });

    [[styleAll, styleBody], [itemAll, itemBody]].forEach(([master, body]) => {
        master.addEventListener('change', () => {
            visibleBoxes().forEach((cb) => { cb.checked = master.checked; });
            updatePickCount();
        });
        body.addEventListener('change', (e) => {
            if (e.target.classList.contains('bi-style-cb') || e.target.classList.contains('bi-item-cb')) updatePickCount();
        });
        // Clicking anywhere on a selectable row toggles it. Clicks on the box
        // itself are left alone or they would toggle twice.
        body.addEventListener('click', (e) => {
            const tr = e.target.closest('tr.bi-row');
            if (!tr || e.target.matches('input[type="checkbox"]')) return;
            const cb = tr.querySelector('input[type="checkbox"]:not(:disabled)');
            if (!cb) return;
            cb.checked = !cb.checked;
            updatePickCount();
        });
    });

    nextBtn.addEventListener('click', () => showStep(2));
    backBtn.addEventListener('click', () => showStep(1));

    modalEl.addEventListener('show.bs.modal', () => {
        // Always land on the style step, whatever the PO carries.
        const open = () => { if (items.length) showStep(1); };
        if (loadedPoId === poId.value) open(); else loadItems(poId.value).then(open);
    });

    addBtn.addEventListener('click', () => {
        const chosen = activeBoxes().filter((cb) => cb.checked)
            .map((cb) => items.find((it) => String(it.excel_row_id) === String(cb.value)))
            .filter(Boolean);

        // The checkboxes for these are disabled, so this only catches a tampered
        // or stale DOM — but an out-of-stock item must never reach the form.
        const empty = chosen.filter((it) => (parseFloat(it.available) || 0) <= 0);
        if (empty.length) {
            modalError.textContent = empty.length === 1
                ? 'That item has no available stock and cannot be issued.'
                : empty.length + ' of the selected items have no available stock and cannot be issued.';
            modalError.classList.remove('d-none');
            return;
        }

        chosen.forEach((item) => addItemRow(item));
        refreshItemsState();
        // These are quantity blocks now, not pending ticks — reopening the
        // picker to "Add More Items" should start from a clean sheet.
        clearPicked();
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });

    // --- Step 5: one quantity block per selected item -------------------------
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

        const wrap = document.createElement('div');
        wrap.className = 'bi-item-card';
        wrap.dataset.rowId = item.excel_row_id;
        wrap.dataset.available = String(avail);

        const identity = [item.material_color, item.size, item.uom].filter(Boolean).join(' · ');

        wrap.innerHTML =
            '<div class="d-flex align-items-start justify-content-between gap-2 mb-2">' +
                '<div class="min-w-0">' +
                    '<div class="bi-item-head">' + dash(item.material_name || item.material_description) + '</div>' +
                    // The style is called out rather than buried in the meta
                    // line: it is the one thing on this card that cannot be
                    // corrected after the issue is saved.
                    '<div class="bi-item-style"><i class="bi bi-tag-fill" aria-hidden="true"></i>' +
                        dash(item.style_name) + '</div>' +
                    '<div class="bi-item-meta">' + dash([item.art_no, identity].filter(Boolean).join(' · ')) + '</div>' +
                '</div>' +
                '<div class="d-flex align-items-center gap-2 flex-shrink-0">' +
                    '<span class="badge bg-secondary-subtle text-secondary-emphasis text-nowrap" data-bi-filled ' +
                        'title="How many of the four quantity fields carry a value">0/4</span>' +
                    '<span class="badge bg-success-subtle text-success text-nowrap">Avail: ' + fmtNum(avail) + '</span>' +
                    // Labelled rather than a bare ✕ — store staff should not have
                    // to hover a card to learn that the cross drops the item.
                    '<button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none bi-btn-inline" ' +
                        'data-bi-remove-item title="Remove this item from the issue">' +
                        '<i class="bi bi-x-lg me-1" aria-hidden="true"></i>Remove</button>' +
                '</div>' +
            '</div>' +
            '<input type="hidden" name="' + n('excel_row_id') + '" value="' + h(item.excel_row_id) + '">' +
            '<div class="row g-2 bi-qty-grid">' +
                qtyCell('bulk', 'Bulk', 'text-success', n('bulk_qty'), preset.bulk_qty) +
                qtyCell('sample', 'Sample', 'text-primary', n('sample_qty'), preset.sample_qty) +
                qtyCell('liability', 'Liability', 'text-warning', n('liability_qty'), preset.liability_qty) +
                qtyCell('dead', 'Dead', 'text-danger', n('dead_qty'), preset.dead_qty) +
            '</div>' +
            // Running total against the balance. The same figures checkOver()
            // already computes, stated before the user hits the error rather
            // than only after.
            '<div class="bi-item-total">' +
                '<i class="bi bi-calculator" aria-hidden="true"></i>' +
                '<span>Total <span class="bi-item-total-num" data-bi-total>0</span> of ' + fmtNum(avail) + '</span>' +
            '</div>' +
            // The limit applies to the sum of four independent fields, so which
            // one to reduce is the user's call — hence a blocking message with a
            // one-click way out, rather than silently rewriting what they typed.
            '<div class="d-flex align-items-center justify-content-between gap-2 mt-2 d-none" data-bi-over>' +
                '<span class="bi-item-error"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>' +
                    '<span data-bi-over-text></span></span>' +
                '<button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" data-bi-setmax ' +
                    'title="Set Bulk to the available balance and clear the other three">Set to max</button>' +
            '</div>';

        itemRows.appendChild(wrap);

        // Suggested bulk default from the BOM's GMTS Order Qty, as before.
        if (preset.bulk_qty === undefined && item.gmts_order_qty) {
            const bulkInput = wrap.querySelector('input[name$="[bulk_qty]"], input[name="bulk_qty"]');
            if (bulkInput) bulkInput.value = item.gmts_order_qty;
        }

        checkOver();
    }

    function qtyCell(cls, label, tone, name, value) {
        // Two-up on a narrow panel, all four on one line once the slide-over is
        // wide enough for them (xl and above).
        //
        // The colour marker is a CSS dot rather than an emoji: emoji render at a
        // different size and hue on every OS, which is what made the four labels
        // look uneven. The field name is untouched.
        const id = 'biQty_' + String(name).replace(/\W/g, '_');

        return '<div class="col-6 col-xl-3"><div class="bi-qty-card ' + cls + '">' +
            '<label class="form-label ' + tone + '" for="' + id + '">' +
                '<span class="bi-qty-dot" aria-hidden="true"></span>' + label +
            '</label>' +
            '<input type="number" step="0.0001" min="0" id="' + id + '" name="' + h(name) + '" placeholder="0" ' +
                'class="form-control form-control-sm bi-qty"' +
                (value !== undefined && value !== null && value !== '' ? ' value="' + h(value) + '"' : '') + '>' +
        '</div></div>';
    }

    itemRows.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-bi-remove-item]');
        if (!btn) return;
        btn.closest('.bi-item-card').remove();
        refreshItemsState();
        checkOver();
    });

    itemRows.addEventListener('input', (e) => {
        if (e.target.classList.contains('bi-qty')) checkOver();
    });

    function refreshItemsState() {
        const count = itemRows.children.length;
        noItems.classList.toggle('d-none', count > 0);
        // Editing corrects one issue, so there is nothing to add alongside it.
        addMoreWrap.classList.toggle('d-none', count === 0 || editing);
        publishState();
    }

    /**
     * Hands the Alpine shell what it needs to lock its sections and gate Save.
     * One-way on purpose: Alpine reads this and never writes back into the DOM
     * this module owns, so the two cannot fight over the same nodes.
     */
    function publishState(extra) {
        const cards = Array.from(itemRows.children);
        // Items over their stock balance — the count the status bar shows and
        // the reason Save stays disabled.
        const errorCount = cards.filter((c) => c.classList.contains('is-over')).length;
        // Items carrying at least one of the four quantities. An issue with
        // items but no numbers on them is not saveable.
        const withQty = cards.filter((c) => Array.from(c.querySelectorAll('.bi-qty'))
            .some((el) => el.value !== '' && parseFloat(el.value) > 0)).length;

        if (!stateHost) return;
        stateHost.dispatchEvent(new CustomEvent('bi:state', {
            detail: Object.assign({
                hasPo: !!poId.value,
                itemCount: cards.length,
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
            const over = total > avail + 1e-9;
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
                : 'Cannot save: ' + offenders.length + ' items exceed their available stock — ' + offenders.join('; ') + '.';
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
        clearPicked();
        itemRows.innerHTML = '';
        selectedRow.classList.add('d-none');
        overWarn.classList.add('d-none');
        poSearch.value = '';
        // Reset returns the selector to whichever option the markup ships first,
        // so this does not have to be kept in step with the dropdown by hand.
        filterType.selectedIndex = 0;
        pickedValue = null;
        syncPickChip();
        poSearch.placeholder = 'Click or type to browse ' + LABELS_PLURAL[filterType.value] + '…';
        poLoading.classList.add('d-none');
        poError.classList.add('d-none');
        syncSearchStatus();
        closeSuggest();
        title.textContent = 'New Bulk Issue';
        saveLabel.textContent = 'Confirm';
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
            window.alert('Select at least one item to issue.');
            return;
        }
        // Stock integrity is a hard rule, not a confirmation. The server rejects
        // the same case, so letting it through here would only waste a round trip.
        if (checkOver()) {
            e.preventDefault();
            overWarn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        // An item whose balance ran out between picking and saving.
        const empty = Array.from(itemRows.children)
            .filter((card) => (parseFloat(card.dataset.available) || 0) <= 0);
        if (empty.length) {
            e.preventDefault();
            window.alert('One or more selected items have no available stock and cannot be issued. Remove them and try again.');
        }
    });

    refreshItemsState();
}
