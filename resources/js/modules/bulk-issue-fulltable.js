/**
 * Alpine component behind the Bulk Issuing "Full Table" view: an Excel-style
 * column filter over the rows already on the page, plus row selection and the
 * two exports.
 *
 * The filter semantics themselves live in column-filter.js and are shared with
 * the item picker inside the New Bulk Issue form — this component owns the
 * Alpine surface (reactivity, template bindings, selection, export) and
 * forwards every filter decision to that one implementation.
 *
 * Client-side by decision: the filtering, sorting and value lists all work on
 * the rows the server sent for the current page, so no new endpoint exists and
 * nothing here can disagree with what the user is looking at. The trade is that
 * a filter narrows the current page, not the whole history — the tabs and the
 * search box above stay the server-side scope. If the table ever outgrows that
 * (thousands of rows), this is the piece that moves to the server.
 *
 * Rows arrive as JSON from the Blade partial rather than being scraped from the
 * DOM: sorting has to reorder rows, and reordering server-rendered <tr>s is how
 * a table and its data get out of step.
 */

import { createColumnFilter } from './column-filter';

export function registerBulkIssueFullTable(Alpine) {
    Alpine.data('bulkIssueFullTable', (config) => ({
        columns: config.columns || [],
        rows: config.rows || [],
        routes: config.routes || {},
        csrf: config.csrf || '',

        openKey: '',
        selected: [],

        // The shared filter state. Nested in the Alpine data object so its
        // mutations stay reactive; the methods below keep this component's
        // original API so the Blade bindings did not have to change.
        cf: null,

        init() {
            this.cf = createColumnFilter({
                columns: this.columns,
                getRows: () => this.rows,
            });
        },

        // --- Filter surface (forwarded) --------------------------------------
        get filters() { return this.cf ? this.cf.filters : {}; },
        get needles() { return this.cf ? this.cf.needles : {}; },
        get sortKey() { return this.cf ? this.cf.sortKey : ''; },

        valuesFor(key) { return this.cf.valuesFor(key); },
        isFiltered(key) { return this.cf.isFiltered(key); },
        isChecked(key, value) { return this.cf.isChecked(key, value); },
        allChecked(key) { return this.cf.allChecked(key); },

        get activeFilterCount() { return this.cf ? this.cf.activeCount() : 0; },
        get visibleRows() { return this.cf ? this.cf.apply() : this.rows; },

        toggleValue(key, value) {
            this.cf.toggleValue(key, value);
            this.pruneSelection();
        },

        toggleAll(key) {
            this.cf.toggleAll(key);
            this.pruneSelection();
        },

        clearFilter(key) {
            this.cf.clearFilter(key);
            this.openKey = '';
            this.pruneSelection();
        },

        clearAll() {
            this.cf.clearAll();
            this.openKey = '';
            this.pruneSelection();
        },

        sortBy(key, dir) {
            this.cf.sortBy(key, dir);
            this.openKey = '';
        },

        // --- Dropdown ---------------------------------------------------------
        toggleMenu(key) {
            this.openKey = this.openKey === key ? '' : key;
            if (this.openKey) this.cf.needles[key] = '';
        },

        // --- Selection --------------------------------------------------------
        /** A row hidden by a filter must not stay silently selected. */
        pruneSelection() {
            const visible = this.visibleRows.map((r) => r.id);
            this.selected = this.selected.filter((id) => visible.includes(id));
        },

        get allVisibleSelected() {
            const visible = this.visibleRows;
            return visible.length > 0 && visible.every((r) => this.selected.includes(r.id));
        },

        toggleSelectAll() {
            this.selected = this.allVisibleSelected ? [] : this.visibleRows.map((r) => r.id);
        },

        // --- Export -----------------------------------------------------------
        /**
         * What an export covers: the ticked rows, or — when nothing is ticked —
         * everything the current filters leave showing.
         *
         * Both go to the existing ids[] endpoints, so no server-side change was
         * needed to make "export the filtered view" work.
         */
        exportIds() {
            return this.selected.length ? this.selected : this.visibleRows.map((r) => r.id);
        },

        submitExport(url) {
            const ids = this.exportIds();
            if (!ids.length) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            form.style.display = 'none';

            const token = document.createElement('input');
            token.name = '_token';
            token.value = this.csrf;
            form.appendChild(token);

            ids.forEach((id) => {
                const field = document.createElement('input');
                field.name = 'ids[]';
                field.value = id;
                form.appendChild(field);
            });

            document.body.appendChild(form);
            form.submit();
            form.remove();
        },

        printView() {
            // The browser's own print, against a stylesheet that drops the page
            // chrome — same approach the Summary view's Print uses.
            window.print();
        },
    }));
}
