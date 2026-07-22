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
    const TAB_LABELS = { all: 'All Issues', today: 'Today', week: 'This Week', month: 'This Month' };

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
        const chips = [];
        if (state.q) chips.push(['q', 'Search: "' + state.q + '"']);
        if (state.tab && state.tab !== 'all') chips.push(['tab', TAB_LABELS[state.tab] || state.tab]);

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
            if (key === 'q' || key === 'all') { state.q = ''; if (searchInput) searchInput.value = ''; }
            if (key === 'tab' || key === 'all') state.tab = 'all';
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

    if (bulkBar) {
        bulkBar.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bi-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-bi-action');
            const count = selectedIds().length;
            if (action === 'excel') submitBulk(cfg.routes.exportExcel);
            else if (action === 'pdf') submitBulk(cfg.routes.exportPdf);
            else if (action === 'print') printSelection();
            else if (action === 'delete') {
                if (window.confirm('Delete ' + count + ' selected bulk issue(s)? Closing stock will update. This cannot be undone.')) {
                    submitBulk(cfg.routes.bulkDestroy, 'DELETE');
                }
            }
        });
    }

    // --- Create / Edit slide-in panel -----------------------------------------
    initPanel(cfg);

    // Initial paint (state already matches the server-rendered partial).
    afterSwap();
}

/** The offcanvas create/edit form: PO picker, summary, quantities. */
function initPanel(cfg) {
    const panelEl = document.getElementById('biPanel');
    if (!panelEl || typeof bootstrap === 'undefined') return;
    const panel = bootstrap.Offcanvas.getOrCreateInstance(panelEl);

    const byId = {};
    cfg.options.forEach((o) => { byId[o.id] = o; });

    const form = document.getElementById('biForm');
    const methodEl = document.getElementById('biMethod');
    const poId = document.getElementById('biPoId');
    const title = document.getElementById('biPanelTitle');
    const saveLabel = document.getElementById('biSaveLabel');

    const poSearch = document.getElementById('biPoSearch');
    const poPanel = document.getElementById('biPoPanel');
    const poList = document.getElementById('biPoList');
    const poHint = document.getElementById('biPoHint');
    const selectedRow = document.getElementById('biSelectedRow');
    const selectedText = document.getElementById('biSelectedText');
    const summary = document.getElementById('biSummaryGrid');
    const runningEl = document.getElementById('biRunning');

    const bulk = document.getElementById('biBulk');
    const overWarn = document.getElementById('biOverWarn');
    const overText = document.getElementById('biOverText');
    const qtyEls = Array.from(form.querySelectorAll('.bi-qty'));
    const issueNoEl = document.getElementById('biIssueNo');
    const issueDateEl = document.getElementById('biIssueDate');

    let summaryTicket = 0;

    function setSummaryFrom(data) {
        summary.querySelectorAll('[data-sum]').forEach((el) => {
            const v = data ? data[el.getAttribute('data-sum')] : null;
            el.textContent = (v === null || v === undefined || String(v).trim() === '') ? '—' : String(v);
        });
    }

    function loadSummary(id) {
        const ticket = ++summaryTicket;
        setSummaryFrom(null);
        summary.classList.remove('d-none');
        fetch(cfg.routes.poDetails.replace('__ID__', encodeURIComponent(id)), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data) => { if (ticket === summaryTicket && String(poId.value) === String(id)) setSummaryFrom(data); })
            .catch(() => { if (ticket === summaryTicket) setSummaryFrom(null); });
    }

    function running() {
        const d = cfg.prefill[poId.value];
        return d ? Number(d.running) || 0 : 0;
    }
    function total() { return qtyEls.reduce((s, el) => s + (parseFloat(el.value) || 0), 0); }

    function checkOver() {
        const over = poId.value && total() > running() + 1e-9;
        if (over) {
            overText.textContent = 'This issue (' + fmtNum(total()) + ') exceeds available stock (' + fmtNum(running()) + '). You can still proceed if intentional.';
            overWarn.classList.remove('d-none');
        } else {
            overWarn.classList.add('d-none');
        }
        return over;
    }
    qtyEls.forEach((el) => el.addEventListener('input', checkOver));

    function suggestIssueNo() {
        if (issueNoEl && !issueNoEl.value) {
            const d = new Date();
            const ymd = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
            issueNoEl.value = 'BI-' + ymd + '-' + String(Math.floor(1000 + Math.random() * 9000));
        }
    }

    // --- PO picker (client-side over options) ---------------------------------
    let activeIndex = -1;

    function renderOpts(term) {
        const needle = term.trim().toLowerCase();
        const results = needle === '' ? cfg.options : cfg.options.filter((o) =>
            [o.po_no, o.style, o.buyer, o.material, o.art_no].some((f) => esc(f).toLowerCase().includes(needle)));
        activeIndex = -1;
        poHint.textContent = results.length + (results.length === 1 ? ' result' : ' results');
        if (!results.length) {
            poList.innerHTML = '<div class="list-group-item text-center text-muted py-3 small">No matching records</div>';
            return;
        }
        poList.innerHTML = results.map((o) =>
            '<div class="list-group-item bi-opt" role="option" tabindex="-1" data-id="' + h(o.id) + '">' +
                '<div class="d-flex justify-content-between align-items-start gap-2"><div class="bi-opt-primary">' + dash(o.po_no) +
                '</div><span class="badge bg-success-subtle text-success text-nowrap">Avail: ' + fmtNum(o.available) + '</span></div>' +
                '<div class="bi-opt-meta">' + [dash(o.style), dash(o.buyer), dash(o.material)].join(' · ') + '</div></div>'
        ).join('');
    }

    function openPanel() { poPanel.classList.remove('d-none'); poSearch.setAttribute('aria-expanded', 'true'); }
    function closePanel() { poPanel.classList.add('d-none'); poSearch.setAttribute('aria-expanded', 'false'); activeIndex = -1; }
    function showOpts() { renderOpts(poSearch.value); openPanel(); }

    function selectPo(id, opts) {
        const o = byId[id];
        poId.value = id;
        selectedText.textContent = o ? ([o.po_no, o.material].filter(Boolean).join(' · ') || o.po_no) : (opts && opts.label) || id;
        selectedRow.classList.remove('d-none');
        closePanel();
        poSearch.value = '';
        if (opts && opts.summary) { setSummaryFrom(opts.summary); summary.classList.remove('d-none'); }
        else loadSummary(id);
        runningEl.textContent = fmtNum(running());
        if (!(opts && opts.keepQty)) {
            const d = cfg.prefill[id] || {};
            if (d.gmts_order_qty && !bulk.value) bulk.value = d.gmts_order_qty;
            suggestIssueNo();
        }
        checkOver();
    }

    poSearch.addEventListener('focus', showOpts);
    poSearch.addEventListener('click', showOpts);
    poSearch.addEventListener('input', showOpts);
    poSearch.addEventListener('keydown', (e) => {
        const open = !poPanel.classList.contains('d-none');
        const els = Array.from(poList.querySelectorAll('.bi-opt'));
        if (e.key === 'Escape') return closePanel();
        if (e.key === 'ArrowDown' && open) { e.preventDefault(); activeIndex = (activeIndex + 1) % els.length; }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); activeIndex = (activeIndex - 1 + els.length) % els.length; }
        else if (e.key === 'Enter') { e.preventDefault(); if (open && els.length) selectPo((activeIndex >= 0 ? els[activeIndex] : els[0]).dataset.id); return; }
        else return;
        els.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
        if (els[activeIndex]) els[activeIndex].scrollIntoView({ block: 'nearest' });
    });
    poList.addEventListener('click', (e) => {
        const opt = e.target.closest('.bi-opt');
        if (opt) selectPo(opt.dataset.id);
    });
    document.getElementById('biClearPo').addEventListener('click', () => {
        poId.value = '';
        selectedRow.classList.add('d-none');
        summary.classList.add('d-none');
        summaryTicket++;
        poSearch.focus();
        showOpts();
    });
    document.addEventListener('click', (e) => {
        const wrap = document.getElementById('biSearchWrap');
        if (wrap && !wrap.contains(e.target)) closePanel();
    });

    // --- Open create / edit ---------------------------------------------------
    function resetForm() {
        form.reset();
        methodEl.value = '';
        form.action = cfg.routes.store;
        poId.value = '';
        selectedRow.classList.add('d-none');
        summary.classList.add('d-none');
        overWarn.classList.add('d-none');
        title.textContent = 'New Bulk Issue';
        saveLabel.textContent = 'Save';
        if (issueDateEl) issueDateEl.value = new Date().toISOString().slice(0, 10);
    }

    const newBtn = document.getElementById('biNewBtn');
    if (newBtn) newBtn.addEventListener('click', () => { resetForm(); panel.show(); });

    // Delegated edit buttons in the (swappable) table.
    document.getElementById('biTableContainer').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-bi-edit]');
        if (!btn) return;
        const id = btn.getAttribute('data-bi-edit');
        resetForm();
        title.textContent = 'Edit Bulk Issue';
        saveLabel.textContent = 'Update';
        form.action = cfg.routes.update.replace('__ID__', encodeURIComponent(id));
        methodEl.value = 'PUT';
        panel.show();

        fetch(cfg.routes.show.replace('__ID__', encodeURIComponent(id)), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d) => {
                selectPo(String(d.booking_po_id), { summary: d, keepQty: true, label: [d.po_no, d.material_name].filter(Boolean).join(' · ') });
                setField('biReq', d.material_requisition_id);
                setField('biSection', d.indent_section);
                setField('biPerson', d.indent_person);
                setField('biReqNo', d.requisition_number);
                setField('biIssueNo', d.issue_no);
                setField('biIssueDate', d.issue_date);
                setField('biBulk', numOrBlank(d.bulk_qty));
                setField('biSample', numOrBlank(d.sample_qty));
                setField('biLiability', numOrBlank(d.liability_qty));
                setField('biDead', numOrBlank(d.dead_qty));
                setField('biRemarks', d.remarks);
                checkOver();
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

    // Soft over-stock confirm on submit (never a hard block).
    form.addEventListener('submit', (e) => {
        if (checkOver() && !window.confirm('This issue exceeds available stock (' + fmtNum(running()) + '). Proceed anyway?')) {
            e.preventDefault();
        }
    });
}
