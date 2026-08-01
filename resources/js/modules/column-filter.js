/**
 * Excel-style column filter, as pure state over an array of row objects.
 *
 * Deliberately knows nothing about Alpine, Bootstrap or the DOM: the Bulk
 * Issuing "Full Table" renders its rows with Alpine `x-for`, while the item
 * picker inside the New Bulk Issue form builds its rows as an HTML string in
 * vanilla JS. Both need the same filter semantics — cascading value lists,
 * Excel's Select All behaviour, two-way sort — and only one copy of those rules
 * should exist. The two callers keep their own rendering and share this.
 *
 * Rows are read through getRows() rather than held here, because the picker's
 * row set changes whenever the user picks a different style at step 1.
 */

/** Reads better than a bare blank when a cell has no value. */
export const BLANK = '(Blanks)';

/**
 * @param {object} config
 * @param {Array<{key: string, type?: string}>} config.columns
 * @param {() => Array<object>} config.getRows
 */
export function createColumnFilter({ columns = [], getRows = () => [] } = {}) {
    return {
        columns,
        getRows,

        // key => array of allowed values. A key absent from this map means
        // "this column filters nothing", which is not the same as an empty
        // array (that would match nothing).
        filters: {},
        // Per-column search text inside the open dropdown.
        needles: {},
        sortKey: '',
        sortDir: 'asc',

        /** The displayed text for one cell, which is what a filter matches on. */
        cell(row, key) {
            const v = row[key];
            return v === null || v === undefined || v === '' ? BLANK : String(v);
        },

        /** Rows passing every filter, optionally ignoring one column's own. */
        rowsMatching(exceptKey) {
            const keys = Object.keys(this.filters);

            return this.getRows().filter((row) => keys.every((key) => {
                if (key === exceptKey) return true;
                const allowed = this.filters[key];
                if (!allowed) return true;
                return allowed.includes(this.cell(row, key));
            }));
        },

        /**
         * Every distinct value a column holds, under the OTHER columns' filters.
         * Excel behaves this way: opening Season after filtering Buyer offers
         * the seasons that buyer actually has, not every season in the sheet.
         */
        valuesFor(key) {
            const seen = new Set();
            this.rowsMatching(key).forEach((r) => seen.add(this.cell(r, key)));

            const needle = (this.needles[key] || '').trim().toLowerCase();
            const list = Array.from(seen).filter((v) => !needle || v.toLowerCase().includes(needle));

            // Blanks last; everything else naturally, so numbers do not sort as
            // text ("10" before "9").
            return list.sort((a, b) => {
                if (a === BLANK) return 1;
                if (b === BLANK) return -1;
                return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
            });
        },

        /** Filtered rows, in the active sort order. */
        apply() {
            const rows = this.rowsMatching();
            if (!this.sortKey) return rows;

            const col = this.columns.find((c) => c.key === this.sortKey) || {};
            const dir = this.sortDir === 'asc' ? 1 : -1;
            const key = this.sortKey;

            // Sorting a copy: the caller's own order survives clearing the sort.
            return rows.slice().sort((a, b) => {
                if (col.type === 'num') {
                    return ((parseFloat(a[key]) || 0) - (parseFloat(b[key]) || 0)) * dir;
                }
                // Dates are passed in ISO (YYYY-MM-DD), so a string compare is
                // already chronological.
                return String(a[key] ?? '').localeCompare(String(b[key] ?? ''), undefined, {
                    numeric: true, sensitivity: 'base',
                }) * dir;
            });
        },

        // --- Per-column state -------------------------------------------------
        isFiltered(key) {
            return Array.isArray(this.filters[key]);
        },

        activeCount() {
            return Object.keys(this.filters).length;
        },

        /** Is one value ticked? An unfiltered column has everything ticked. */
        isChecked(key, value) {
            const allowed = this.filters[key];
            return allowed ? allowed.includes(value) : true;
        },

        toggleValue(key, value) {
            // The first tick on an unfiltered column starts from "all selected",
            // so unticking one value leaves the rest showing — as in Excel.
            const current = this.filters[key] ? this.filters[key].slice() : this.valuesFor(key);
            const at = current.indexOf(value);

            if (at === -1) current.push(value);
            else current.splice(at, 1);

            this.filters[key] = current;

            // Every value back on = no filter at all. Keeping an "all selected"
            // array would light the funnel icon for a column filtering nothing.
            if (current.length === this.valuesFor(key).length) delete this.filters[key];
        },

        allChecked(key) {
            const allowed = this.filters[key];
            if (!allowed) return true;
            return this.valuesFor(key).every((v) => allowed.includes(v));
        },

        toggleAll(key) {
            // "None" rather than "all": clearing every tick is how Excel lets
            // you then pick just one or two values.
            if (this.allChecked(key)) this.filters[key] = [];
            else delete this.filters[key];
        },

        clearFilter(key) {
            delete this.filters[key];
            this.needles[key] = '';
        },

        clearAll() {
            this.filters = {};
            this.needles = {};
            this.sortKey = '';
        },

        sortBy(key, dir) {
            this.sortKey = key;
            this.sortDir = dir;
        },
    };
}
