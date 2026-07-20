/**
 * Workspace file list: search, status filter, column sort, row selection and
 * CSV export of the selection.
 *
 * Everything operates on rows already rendered by the server, so the lock and
 * permission logic in the Blade partial stays authoritative — the browser only
 * decides which of those rows to show. That also means no filtering state can
 * leak a row the server did not send.
 */

function initTable(root) {
    const tbody = root.querySelector('tbody');
    const search = root.querySelector('[data-file-search]');
    const status = root.querySelector('[data-file-status]');
    const clearBtn = root.querySelector('[data-file-clear]');
    const exportBtn = root.querySelector('[data-file-export]');
    const exportCount = root.querySelector('[data-file-export-count]');
    const countEl = root.querySelector('[data-file-count]');
    const chipsEl = root.querySelector('[data-file-chips]');
    const selectAll = root.querySelector('[data-file-select-all]');
    const noMatch = root.querySelector('[data-file-no-match]');

    if (!tbody) {
        return;
    }

    const rows = () => Array.from(root.querySelectorAll('[data-file-row]'));

    function visibleRows() {
        return rows().filter((r) => !r.classList.contains('d-none'));
    }

    function updateChips() {
        const chips = [];

        if (search.value.trim()) {
            chips.push(['search', 'Search: "' + search.value.trim() + '"']);
        }

        if (status.value) {
            chips.push(['status', 'Status: ' + status.value]);
        }

        chipsEl.innerHTML = chips
            .map(
                ([key, label]) =>
                    '<span class="gx-chip">' +
                    label.replace(/[<>&"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[c]) +
                    '<button type="button" data-chip-clear="' + key + '" aria-label="Remove this filter">&times;</button></span>'
            )
            .join('');

        chipsEl.classList.toggle('d-none', chips.length === 0);
    }

    function applyFilters() {
        const term = search.value.trim().toLowerCase();
        const wanted = status.value.toLowerCase();

        rows().forEach((row) => {
            const haystack = row.dataset.search || '';
            const rowStatus = (row.dataset.status || '').toLowerCase();
            const matches = (!term || haystack.includes(term)) && (!wanted || rowStatus === wanted);

            row.classList.toggle('d-none', !matches);

            if (!matches) {
                const box = row.querySelector('[data-file-check]');

                if (box) {
                    box.checked = false; // never keep a hidden row selected
                }
            }
        });

        const shown = visibleRows().length;
        const total = rows().length;

        countEl.textContent = total === 0 ? '' : 'Showing ' + shown + ' of ' + total + ' file(s)';
        noMatch?.classList.toggle('d-none', shown > 0 || total === 0);

        updateChips();
        updateSelection();
    }

    function updateSelection() {
        const boxes = visibleRows()
            .map((r) => r.querySelector('[data-file-check]'))
            .filter(Boolean);
        const checked = boxes.filter((b) => b.checked);

        exportBtn.disabled = checked.length === 0;
        exportCount.textContent = checked.length ? '(' + checked.length + ')' : '';

        if (selectAll) {
            selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
    }

    // --- Sorting ----------------------------------------------------------
    let sortIndex = null;
    let sortAsc = true;

    root.querySelectorAll('[data-sort]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const index = parseInt(btn.dataset.sort, 10);

            sortAsc = sortIndex === index ? !sortAsc : true;
            sortIndex = index;

            root.querySelectorAll('[data-sort]').forEach((b) => b.classList.remove('is-asc', 'is-desc'));
            btn.classList.add(sortAsc ? 'is-asc' : 'is-desc');

            const sorted = rows().sort((a, b) => {
                const av = (a.children[index + 1]?.textContent || '').trim().toLowerCase();
                const bv = (b.children[index + 1]?.textContent || '').trim().toLowerCase();

                if (av === bv) return 0;
                if (av === '' || av === '-') return 1;   // blanks always last
                if (bv === '' || bv === '-') return -1;

                return (av < bv ? -1 : 1) * (sortAsc ? 1 : -1);
            });

            sorted.forEach((row) => tbody.appendChild(row));

            if (noMatch) {
                tbody.appendChild(noMatch); // keep the message row at the bottom
            }
        });
    });

    // --- Events -----------------------------------------------------------
    search.addEventListener('input', applyFilters);
    status.addEventListener('change', applyFilters);

    clearBtn.addEventListener('click', () => {
        search.value = '';
        status.value = '';
        applyFilters();
    });

    chipsEl.addEventListener('click', (e) => {
        const key = e.target.dataset?.chipClear;

        if (!key) return;

        if (key === 'search') search.value = '';
        if (key === 'status') status.value = '';

        applyFilters();
    });

    selectAll?.addEventListener('change', () => {
        visibleRows().forEach((row) => {
            const box = row.querySelector('[data-file-check]');

            if (box) box.checked = selectAll.checked;
        });

        updateSelection();
    });

    tbody.addEventListener('change', (e) => {
        if (e.target.matches('[data-file-check]')) updateSelection();
    });

    // --- Export -----------------------------------------------------------
    // Read-only: writes out what is already on screen. Deliberately not a bulk
    // delete or status change — those touch records the BOM rows are linked to.
    exportBtn.addEventListener('click', () => {
        const header = ['Buyer Name', 'Season Name', 'Style Name', 'Contract Number', 'Contract Shipment Date', 'Status'];

        const lines = [header]
            .concat(
                visibleRows()
                    .map((r) => r.querySelector('[data-file-check]'))
                    .filter((b) => b && b.checked)
                    .map((b) => (b.dataset.export || '').split('|'))
            )
            .map((cols) => cols.map((c) => '"' + String(c).replace(/"/g, '""') + '"').join(','))
            .join('\r\n');

        const blob = new Blob(['﻿' + lines], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');

        link.href = URL.createObjectURL(blob);
        link.download = 'bom-files-' + new Date().toISOString().slice(0, 10) + '.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    });

    applyFilters();
}

export function initFileTable() {
    document.querySelectorAll('[data-file-table]').forEach(initTable);
}
