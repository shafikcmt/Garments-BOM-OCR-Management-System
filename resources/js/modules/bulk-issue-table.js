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

    // --- Create / Edit slide-in panel -----------------------------------------
    initPanel(cfg);

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
    const panelEl = document.getElementById('biPanel');
    if (!panelEl || typeof bootstrap === 'undefined') return;
    const panel = bootstrap.Offcanvas.getOrCreateInstance(panelEl);

    const form = document.getElementById('biForm');
    const methodEl = document.getElementById('biMethod');
    const poId = document.getElementById('biPoId');
    const title = document.getElementById('biPanelTitle');
    const saveLabel = document.getElementById('biSaveLabel');

    const filterType = document.getElementById('biFilterType');
    const searchLabel = document.getElementById('biSearchLabel');
    const poSearch = document.getElementById('biPoSearch');
    const poPanel = document.getElementById('biPoPanel');
    const poList = document.getElementById('biPoList');
    const poHint = document.getElementById('biPoHint');
    const poSpin = document.getElementById('biPoSpin');
    const poClear = document.getElementById('biPoClear');
    const selectedRow = document.getElementById('biSelectedRow');
    const selectedText = document.getElementById('biSelectedText');
    const summary = document.getElementById('biSummaryGrid');
    const sumCounts = document.getElementById('biSumCounts');

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

    const LABELS = { po_no: 'PO Number', pi_number: 'PI Number', invoice_no: 'Invoice No' };
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

    function loadBrowse(type) {
        if (browseCache[type]) return Promise.resolve(browseCache[type]);
        if (browseInFlight[type]) return browseInFlight[type];

        browseInFlight[type] = fetch(cfg.routes.poSearch + '?type=' + encodeURIComponent(type), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                browseCache[type] = { results: data.results || [], complete: !!data.complete };
                return browseCache[type];
            })
            .finally(() => { delete browseInFlight[type]; });

        return browseInFlight[type];
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
            [r.value, r.po_no, r.buyer_name, r.season_name, r.vendor_name]
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

        fetch(cfg.routes.poSearch + '?type=' + encodeURIComponent(type) + '&term=' + encodeURIComponent(term), {
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

    function renderResults(results, type, term, source) {
        activeIndex = -1;

        if (!results.length) {
            poHint.textContent = '';
            poList.innerHTML = '<div class="list-group-item text-center text-muted py-3 small">No matching records' +
                (term ? ' for “' + h(term) + '”' : '') + '</div>';
            return;
        }

        const browsing = term === '' && source && source.complete;
        poHint.textContent = browsing
            ? 'All ' + LABELS[type] + 's (' + results.length + ')'
            : results.length + (results.length === 1 ? ' match' : ' matches');

        poList.innerHTML = results.map((r) => {
            // The PO number is already the primary line when browsing by PO, so
            // it is not repeated in the meta.
            const meta = [type === 'po_no' ? null : r.po_no, r.buyer_name, r.vendor_name].filter(Boolean).join(' · ');

            return '<div class="list-group-item bi-opt" role="option" tabindex="-1"' +
                ' data-id="' + h(r.id) + '" data-po="' + h(r.po_no) + '">' +
                '<div class="bi-opt-primary">' + dash(r.value || r.po_no) + '</div>' +
                '<div class="bi-opt-meta">' + dash(meta) + '</div></div>';
        }).join('');
    }

    filterType.addEventListener('change', () => {
        const label = LABELS[filterType.value];
        searchLabel.textContent = label;
        poSearch.placeholder = 'Click or type to see available ' + label + 's…';
        poSearch.value = '';
        closeSuggest();
        syncSearchStatus();
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
            if (open && list.length) selectPo(activeIndex >= 0 ? list[activeIndex] : list[0]);
            return;
        }
        if (!open || !list.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = (activeIndex + 1) % list.length; }
        else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = (activeIndex - 1 + list.length) % list.length; }
        else return;

        list.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
        list[activeIndex].scrollIntoView({ block: 'nearest' });
    });

    poList.addEventListener('click', (e) => {
        const opt = e.target.closest('.bi-opt');
        if (opt) selectPo(opt);
    });

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
        syncSearchStatus();
        refreshItemsState();
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
        setSummary(null);

        return fetch(cfg.routes.poItems.replace('__ID__', encodeURIComponent(id)), {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => {
                if (String(poId.value) !== String(id)) return;   // changed meanwhile
                items = data.items || [];
                loadedPoId = id;
                setSummary(data);
                modalPo.textContent = 'PO ' + (esc(data.po_no) || '—') +
                    ' · ' + styleNames().length + ' style(s) · ' + items.length + ' item(s)';
            })
            .catch((status) => {
                modalLoading.classList.add('d-none');
                modalError.classList.remove('d-none');
                modalError.textContent = status === 423
                    ? 'This file/style is locked. Stock entry is not allowed.'
                    : 'Could not load the items for this PO. Please try again.';
            });
    }

    function showStep(step) {
        modalLoading.classList.add('d-none');
        modalError.classList.add('d-none');
        step1.classList.toggle('d-none', step !== 1);
        step2.classList.toggle('d-none', step !== 2);
        // With a single style there is nothing to choose at step 1, so Back would
        // only lead to a one-row list the user never saw.
        backBtn.classList.toggle('d-none', step !== 2 || styleNames().length < 2);
        nextBtn.classList.toggle('d-none', step !== 1);
        addBtn.classList.toggle('d-none', step !== 2);
        crumb1.classList.toggle('is-current', step === 1);
        crumb1.classList.toggle('is-done', step === 2);
        crumb2.classList.toggle('is-current', step === 2);

        if (step === 1) renderStyles(); else renderItems();
    }

    function renderStyles() {
        const already = addedRowIds();

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

        updatePickCount();
    }

    const chosenStyles = () => Array.from(styleBody.querySelectorAll('.bi-style-cb:checked')).map((cb) => cb.value);

    function renderItems() {
        const already = addedRowIds();
        // With one style there is no style step, so every item is in scope.
        const styles = styleNames().length < 2 ? styleNames() : chosenStyles();
        const sub = (label, value) => (esc(value) === '' ? '' : label + ' ' + h(value));
        const joinSub = (parts) => parts.filter(Boolean).join(' · ') || '—';
        let html = '';

        styles.forEach((name) => {
            const under = items.filter((it) => styleKey(it) === name);

            if (styles.length > 1) {
                html += '<tr class="bi-group-row"><td colspan="6">' +
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

                    '<td><div class="bi-cell-primary">' + dash(item.material_name) + '</div>' +
                        '<div class="bi-cell-sub">' + dash(item.material_description) + '</div></td>' +

                    '<td><div class="bi-cell-primary small">' + dash(item.art_no) + '</div>' +
                        '<div class="bi-cell-sub">' + (esc(item.sap_code) === '' ? '—' : 'SAP ' + h(item.sap_code)) + '</div></td>' +

                    // GMTS colour is the garment's, material colour is the trim's.
                    '<td><div class="bi-cell-primary small">' +
                            joinSub([sub('GMTS', item.gmts_color_name), sub('Mat', item.material_color)]) + '</div>' +
                        '<div class="bi-cell-sub">' + (esc(item.size) === '' ? '—' : 'Size ' + h(item.size)) + '</div></td>' +

                    '<td class="small">' + dash(item.uom) + '</td>' +
                    '<td class="small text-end">' + (noStock
                        ? '<span class="badge bg-secondary-subtle text-secondary-emphasis">Out of stock</span>'
                        : '<span class="fw-semibold">' + fmtNum(avail) + '</span>') + '</td>' +
                '</tr>';
            });
        });

        itemBody.innerHTML = html;
        updatePickCount();
    }

    const onItemStep = () => step1.classList.contains('d-none');
    const activeBoxes = () => Array.from(
        (onItemStep() ? itemBody : styleBody)
            .querySelectorAll(onItemStep() ? '.bi-item-cb:not(:disabled)' : '.bi-style-cb:not(:disabled)'));

    function updatePickCount() {
        const boxes = activeBoxes();
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
        master.disabled = boxes.length === 0;
        master.checked = boxes.length > 0 && boxes.every((cb) => cb.checked);

        boxes.forEach((cb) => {
            const tr = cb.closest('tr');
            if (tr) tr.classList.toggle('is-checked', cb.checked);
        });
    }

    [[styleAll, styleBody], [itemAll, itemBody]].forEach(([master, body]) => {
        master.addEventListener('change', () => {
            activeBoxes().forEach((cb) => { cb.checked = master.checked; });
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
        const open = () => {
            // One style means nothing to choose — go straight to its items.
            showStep(styleNames().length < 2 ? 2 : 1);
        };
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
                    '<div class="bi-item-meta">' + dash([item.style_name, item.art_no, identity].filter(Boolean).join(' · ')) + '</div>' +
                '</div>' +
                '<div class="d-flex align-items-center gap-2 flex-shrink-0">' +
                    '<span class="badge bg-success-subtle text-success text-nowrap">Avail: ' + fmtNum(avail) + '</span>' +
                    '<button type="button" class="btn btn-sm btn-link text-danger p-0" data-bi-remove-item ' +
                        'title="Remove this item" aria-label="Remove this item"><i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
                '</div>' +
            '</div>' +
            '<input type="hidden" name="' + n('excel_row_id') + '" value="' + h(item.excel_row_id) + '">' +
            '<div class="row g-2 bi-qty-grid">' +
                qtyCell('bulk', 'Bulk', '🟢', 'text-success', n('bulk_qty'), preset.bulk_qty) +
                qtyCell('sample', 'Sample', '🔵', 'text-primary', n('sample_qty'), preset.sample_qty) +
                qtyCell('liability', 'Liability', '🟠', 'text-warning', n('liability_qty'), preset.liability_qty) +
                qtyCell('dead', 'Dead', '🔴', 'text-danger', n('dead_qty'), preset.dead_qty) +
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

    function qtyCell(cls, label, dot, tone, name, value) {
        return '<div class="col-6"><div class="bi-qty-card ' + cls + '">' +
            '<label class="form-label fw-semibold ' + tone + '">' + dot + ' ' + label + '</label>' +
            '<input type="number" step="0.0001" min="0" name="' + h(name) + '" placeholder="0" ' +
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
            const total = Array.from(card.querySelectorAll('.bi-qty'))
                .reduce((sum, el) => sum + (parseFloat(el.value) || 0), 0);
            const over = total > avail + 1e-9;
            const name = card.querySelector('.bi-item-head').textContent.trim();

            card.classList.toggle('is-over', over);

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

        // Blocking the button states the rule before the user commits to it.
        const saveBtn = form.querySelector('button[type="submit"]');
        if (saveBtn) {
            saveBtn.disabled = offenders.length > 0;
            saveBtn.title = offenders.length ? 'One or more items exceed their available stock' : '';
        }

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
        itemRows.innerHTML = '';
        selectedRow.classList.add('d-none');
        overWarn.classList.add('d-none');
        poSearch.value = '';
        filterType.value = 'po_no';
        searchLabel.textContent = LABELS.po_no;
        poSearch.placeholder = 'Click or type to see available PO Numbers…';
        syncSearchStatus();
        closeSuggest();
        title.textContent = 'New Bulk Issue';
        saveLabel.textContent = 'Save';
        if (issueDateEl) issueDateEl.value = new Date().toISOString().slice(0, 10);
        if (issueNoEl) issueNoEl.classList.remove('bi-suggested');
        refreshItemsState();
    }

    const newBtn = document.getElementById('biNewBtn');
    if (newBtn) {
        newBtn.addEventListener('click', () => {
            resetForm();
            // Suggest up front so the field shows a real number on open.
            suggestIssueNo();
            panel.show();
        });
    }

    // Delegated edit buttons in the (swappable) table.
    document.getElementById('biTableContainer').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-bi-edit]');
        if (!btn) return;
        const id = btn.getAttribute('data-bi-edit');

        resetForm();
        editing = true;
        title.textContent = 'Edit Bulk Issue';
        saveLabel.textContent = 'Update';
        form.action = cfg.routes.update.replace('__ID__', encodeURIComponent(id));
        methodEl.value = 'PUT';
        panel.show();

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
    });

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
