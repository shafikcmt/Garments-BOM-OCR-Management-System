{{--
    Two keyboard/entry helpers shared by the item grids on Record Receiving and
    Record Issue.

    Both screens keep their own inline script — they have different row classes,
    different columns and different follow-on logic, and threading those through
    one controller would be a far bigger change to those forms than either
    feature is. What IS shared is the part with no screen-specific knowledge in
    it: picking several items at once, and moving focus around a grid. Both are
    configured by the caller and hold no assumption about either form.

    window.gxItemPicker(config)   search-and-tick several items, then hand the
                                  chosen ids back one at a time.
    window.gxArrowNav(config)     spreadsheet-style arrow keys over a table of
                                  rows.

    Included once per screen, next to _searchable.
--}}

{{-- One picker, reused by whichever grid opened it. The list is rebuilt on open
     from whatever that grid passed in, so a screen cannot show another's
     items. --}}
<div class="modal fade" id="gxItemPicker" tabindex="-1" aria-labelledby="gxItemPickerTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content gx-stock-card">
            <div class="modal-header">
                <h5 class="modal-title" id="gxItemPickerTitle">Add Multiple Items</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="gx-stock-help mb-2">
                    Search, tick everything you need, then add them all at once. One line is created
                    per item, with Uom, Category and Brand filled in — exactly as if you had picked
                    each one by hand. Quantity and the rest are left for you to type.
                </p>

                <div class="mb-2">
                    <input type="search" class="form-control form-control-sm" id="gxItemPickerSearch"
                           placeholder="Type to search item name, brand or category…"
                           autocomplete="off" aria-label="Search the item list">
                </div>

                {{-- Brand is a column of its own, not a suffix. Many item names
                     are stocked under more than one brand, and in a list of
                     tick-boxes two identical names with nothing to tell them
                     apart is how the wrong one gets added. --}}
                <div class="gx-picker-scroll border rounded">
                    <table class="table table-sm mb-0 gx-picker-table">
                        <thead>
                            <tr>
                                <th style="width:34px;"></th>
                                <th>Item Name</th>
                                <th style="width:26%;">Brand/Specification</th>
                                <th style="width:76px;">Uom</th>
                                <th style="width:22%;">Category</th>
                            </tr>
                        </thead>
                        <tbody id="gxItemPickerRows"></tbody>
                    </table>
                    <p class="gx-stock-help text-center py-3 mb-0 d-none" id="gxItemPickerEmpty">
                        No item matches that search.
                    </p>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                {{-- The count is the feedback that the ticks registered; it is
                     the only thing on screen that says so once the list is
                     scrolled. --}}
                <div class="d-flex align-items-center gap-2">
                    <span class="gx-picker-count" id="gxItemPickerCount">No items selected</span>
                    <button type="button" class="btn btn-sm btn-link p-0 d-none" id="gxItemPickerClear">Clear selection</button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="gxItemPickerConfirm" disabled>
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i><span data-gx-confirm-label>Add Items</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Tall enough to show a working number of items without the modal itself
       growing past the viewport on a laptop. */
    .gx-picker-scroll { max-height: 46vh; overflow-y: auto; }

    .gx-picker-table thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background: #f8fafc;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: .02em;
        color: #64748b;
        border-bottom: 1px solid #e2e8f0;
    }

    .gx-picker-table td {
        font-size: 12.5px;
        vertical-align: middle;
        cursor: pointer;
    }

    .gx-picker-table tbody tr:hover td { background: #f1f5f9; }
    .gx-picker-table tbody tr.gx-picked td { background: #dbeafe; }

    /* An unbranded or uncategorised item prints the same em dash the item
       lines use for a missing value, rather than an empty cell that reads as a
       rendering fault. */
    .gx-picker-muted { color: #94a3b8; }

    .gx-picker-count { font-size: 12.5px; color: #475569; }
</style>

<script>
    (function () {
        var KEYS = { up: 'ArrowUp', down: 'ArrowDown', left: 'ArrowLeft', right: 'ArrowRight' };

        /* ------------------------------------------------------------------
         * Multi-item picker
         * ------------------------------------------------------------------
         *
         * Search, tick, confirm — then the caller adds one row per ticked id
         * using whatever function it already uses for a single item. This
         * deliberately knows nothing about rows: it hands back ids in the order
         * they appear in the list and the grid does the rest, so a line created
         * here goes through exactly the same code path, and the same AUTO-field
         * population, as one the user picked by hand.
         *
         * config.trigger   element(s) that open it
         * config.items     [{ value, name, brand, uom, category }]
         *                  read fresh each time it opens, so a screen whose
         *                  list changes is never stale
         * config.onPick    function(id) — called once per ticked item
         * config.title     optional modal heading
         */
        function itemPicker(config) {
            var modalEl = document.getElementById('gxItemPicker');
            if (!modalEl) { return; }

            var search = document.getElementById('gxItemPickerSearch');
            var tbody = document.getElementById('gxItemPickerRows');
            var empty = document.getElementById('gxItemPickerEmpty');
            var count = document.getElementById('gxItemPickerCount');
            var clear = document.getElementById('gxItemPickerClear');
            var confirm = document.getElementById('gxItemPickerConfirm');
            var confirmLabel = confirm.querySelector('[data-gx-confirm-label]');
            var title = document.getElementById('gxItemPickerTitle');

            /**
             * The Bootstrap modal, asked for at the moment it is needed.
             *
             * NOT resolved when this wires up: the app bundle that defines
             * window.bootstrap is deferred, so at inline-script time it does not
             * exist yet. Reading it here used to abandon the whole picker before
             * the page had finished loading — the button rendered and did
             * nothing. By the time anybody can click, the bundle has long since
             * run.
             */
            function modal() {
                return window.bootstrap
                    ? window.bootstrap.Modal.getOrCreateInstance(modalEl)
                    : null;
            }

            // Ticks survive a search being retyped: someone ticking three items,
            // then searching for a fourth, has not changed their mind about the
            // first three. Held by id rather than by row, because the row for an
            // id is destroyed and rebuilt on every keystroke.
            var picked = {};
            var items = [];

            function pickedIds() {
                return items
                    .map(function (item) { return String(item.value); })
                    .filter(function (id) { return picked[id]; });
            }

            function dash(value) {
                var text = (value === null || value === undefined) ? '' : String(value).trim();

                return text === ''
                    ? '<span class="gx-picker-muted">—</span>'
                    : escapeHtml(text);
            }

            function escapeHtml(value) {
                return String(value).replace(/[&<>"']/g, function (ch) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
                });
            }

            function render() {
                var wanted = search.value.trim().toLowerCase();

                var shown = items.filter(function (item) {
                    if (!wanted) { return true; }

                    // Brand and category are searchable too: "gum tape 3m" and
                    // "packing" are both how store staff look for a line.
                    return [item.name, item.brand, item.category]
                        .join(' ')
                        .toLowerCase()
                        .indexOf(wanted) !== -1;
                });

                tbody.innerHTML = shown.map(function (item) {
                    var id = String(item.value);
                    var on = !!picked[id];

                    return '<tr class="' + (on ? 'gx-picked' : '') + '" data-gx-id="' + escapeHtml(id) + '">'
                        + '<td><input type="checkbox" class="form-check-input" ' + (on ? 'checked' : '')
                        + ' aria-label="Select ' + escapeHtml(item.name) + '"></td>'
                        + '<td>' + escapeHtml(item.name) + '</td>'
                        + '<td>' + dash(item.brand) + '</td>'
                        + '<td>' + dash(item.uom) + '</td>'
                        + '<td>' + dash(item.category) + '</td>'
                        + '</tr>';
                }).join('');

                empty.classList.toggle('d-none', shown.length > 0);

                refreshCount();
            }

            function refreshCount() {
                var n = pickedIds().length;

                count.textContent = n === 0
                    ? 'No items selected'
                    : n + (n === 1 ? ' item selected' : ' items selected');

                confirmLabel.textContent = n === 0
                    ? 'Add Items'
                    : 'Add ' + n + (n === 1 ? ' Item' : ' Items');

                confirm.disabled = n === 0;
                clear.classList.toggle('d-none', n === 0);
            }

            function toggle(id, on) {
                if (on) { picked[id] = true; } else { delete picked[id]; }
            }

            // Delegated: the rows are rebuilt on every keystroke.
            tbody.addEventListener('click', function (event) {
                var row = event.target.closest('tr[data-gx-id]');
                if (!row) { return; }

                var box = row.querySelector('input[type="checkbox"]');
                if (!box) { return; }

                // A click on the box itself has already flipped it; a click
                // anywhere else on the row has to do the flipping.
                if (event.target !== box) { box.checked = !box.checked; }

                toggle(row.dataset.gxId, box.checked);
                row.classList.toggle('gx-picked', box.checked);
                refreshCount();
            });

            search.addEventListener('input', render);

            // Enter in the search box confirms rather than submitting the form
            // behind the modal, which would post a half-filled receiving.
            search.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') { return; }

                event.preventDefault();
                if (!confirm.disabled) { confirm.click(); }
            });

            clear.addEventListener('click', function () {
                picked = {};
                render();
            });

            confirm.addEventListener('click', function () {
                var ids = pickedIds();
                var dialog = modal();

                if (dialog) { dialog.hide(); }

                // After hiding, so the rows appear on a page the user can see
                // rather than behind the backdrop as it fades.
                ids.forEach(function (id) { config.onPick(id); });
            });

            // A NodeList, a single element, or an array — a caller should not
            // have to care which. Array.prototype.concat does NOT spread a
            // NodeList (it appends the list itself as one entry), so the shape
            // is normalised here rather than concatenated.
            var triggers = config.trigger;

            if (!triggers) {
                triggers = [];
            } else if (triggers.nodeType) {
                triggers = [triggers];
            } else {
                triggers = Array.prototype.slice.call(triggers);
            }

            triggers.forEach(function (btn) {
                if (!btn) { return; }

                btn.addEventListener('click', function () {
                    var dialog = modal();
                    if (!dialog) { return; }

                    picked = {};
                    items = config.items() || [];
                    search.value = '';

                    if (config.title) { title.textContent = config.title; }

                    render();
                    dialog.show();
                });
            });

            modalEl.addEventListener('shown.bs.modal', function () { search.focus(); });
        }

        /* ------------------------------------------------------------------
         * Spreadsheet-style arrow keys
         * ------------------------------------------------------------------
         *
         * Up/Down move to the same field in the row above/below. Left/Right move
         * to the previous/next field in the row, in the order config.fields
         * lists — which is the order Tab already visits them, so the two agree.
         *
         * WHAT IS DELIBERATELY LEFT ALONE:
         *
         *   TomSelect     Up/Down/Left/Right inside a .ts-wrapper belong to the
         *                 dropdown: Up/Down walk the suggestions, and the widget
         *                 opens on Down when closed. Stealing them would break
         *                 choosing an item by keyboard, which is the main way
         *                 this grid is filled in. Focus therefore leaves the
         *                 Item cell by Tab, as it always has.
         *   <select>      a plain select uses Up/Down to change its value.
         *   Tab           not handled here at all. The Tab-to-add-row handler on
         *                 each screen is a separate listener and is untouched;
         *                 this one returns immediately for every non-arrow key.
         *   the last row  Down at the bottom does nothing. Tab out of Remarks
         *                 stays the one way a row is added, so arrowing down
         *                 through the grid to read it cannot grow it.
         *
         * NUMBER INPUTS. Up/Down on <input type="number"> natively steps the
         * value; that is the spinner this grid does not want. preventDefault()
         * runs BEFORE the focus move for exactly that reason — and it runs even
         * when there is no row to move to, or the value would still creep on the
         * last row. Left/Right are left native on a number input: browsers do
         * not report a caret position for one (selectionStart throws), so there
         * is no way to tell "cursor is at the end" from "cursor is in the
         * middle", and guessing would break typing a figure. Tab still crosses
         * those fields.
         *
         * config.body      the tbody to delegate from
         * config.row       row selector, e.g. 'tr.purchase-line'
         * config.fields    field selectors, in Tab order
         */
        function arrowNav(config) {
            var body = config.body;
            if (!body) { return; }

            function rows() {
                return Array.prototype.slice.call(body.querySelectorAll(config.row));
            }

            /** Which of config.fields is this element, or -1. */
            function fieldIndex(el) {
                for (var i = 0; i < config.fields.length; i++) {
                    if (el.matches(config.fields[i])) { return i; }
                }

                return -1;
            }

            /**
             * Move the cursor into a field, asking TomSelect when it owns one.
             *
             * The same reason focusItem() on each screen does this: TomSelect
             * hides the original <select>, so focus() on it puts the cursor
             * nowhere and the next keystroke goes to the page.
             */
            function focusField(field) {
                if (!field) { return false; }

                if (field.tomselect) { field.tomselect.focus(); return true; }

                field.focus();

                // Land at the end rather than selecting the text, so the next
                // keystroke appends instead of replacing what is there.
                if (typeof field.select === 'function' && field.type !== 'number') {
                    try {
                        field.setSelectionRange(field.value.length, field.value.length);
                    } catch (e) { /* not a field with a caret */ }
                }

                return true;
            }

            /** The first field at or after `from` that is actually reachable. */
            function fieldIn(row, from, step) {
                for (var i = from; i >= 0 && i < config.fields.length; i += step) {
                    var field = row.querySelector(config.fields[i]);

                    if (field && !field.disabled && !field.readOnly) { return field; }
                }

                return null;
            }

            /**
             * Is the caret at the very start / end of this field's text?
             *
             * This is what keeps ordinary editing intact: Left in the middle of
             * "12.50" moves the cursor, and only Left with the cursor already at
             * the front leaves the cell.
             *
             * A <select> has no caret and keeps its own Left/Right, so it is
             * never at a boundary. Every other field on these grids is a real
             * text input — including Qty and Unit Price, which is exactly why
             * they are no longer type="number": that type reports selectionStart
             * as null, and a field whose caret cannot be read can only ever be
             * treated as "not at a boundary", which is why Left/Right did
             * nothing on them.
             */
            function atBoundary(field, wantStart) {
                if (field.tagName === 'SELECT') { return false; }

                var start;
                var end;

                try {
                    start = field.selectionStart;
                    end = field.selectionEnd;
                } catch (e) {
                    return false;
                }

                // Still guarded: a field type that reports no caret, or a live
                // selection rather than a plain cursor, is not a boundary.
                if (start === null || start === undefined || start !== end) { return false; }

                return wantStart ? start === 0 : start === (field.value || '').length;
            }

            /**
             * The configured field that owns a TomSelect's search box.
             *
             * A TomSelect hides the original <select> and puts its own <input>
             * in the row, so the element the event came from is not the element
             * config.fields names. This maps one back to the other.
             */
            function tomSelectOwner(row, wrapper) {
                for (var i = 0; i < config.fields.length; i++) {
                    var el = row.querySelector(config.fields[i]);

                    if (el && el.tomselect && el.tomselect.wrapper === wrapper) {
                        return { field: el, index: i };
                    }
                }

                return null;
            }

            body.addEventListener('keydown', function (event) {
                var key = event.key;

                if (key !== KEYS.up && key !== KEYS.down && key !== KEYS.left && key !== KEYS.right) {
                    return;
                }

                // A modifier combination is the browser's, or the OS's.
                if (event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) { return; }
                if (event.isComposing) { return; }

                var field = event.target;
                if (!field || !field.matches) { return; }

                var row = field.closest(config.row);
                if (!row || !body.contains(row)) { return; }

                var index = fieldIndex(field);

                // Inside an item dropdown's search box.
                //
                // UP/DOWN are the dropdown's, always — they walk the suggestions
                // and open the list when it is closed, which is the main way
                // this grid gets filled in.
                //
                // LEFT/RIGHT are not: TomSelect only uses them to step between
                // several chosen items, and these are single-select fields. So
                // they behave like any other text field — the cursor moves
                // through what has been typed, and only at the edge does focus
                // leave the cell.
                //
                // Deliberately NOT conditioned on the list being closed. The
                // list opens the moment the field is focused, so an "only when
                // closed" rule would mean Left/Right never escaped the Item cell
                // at all. The caret is the honest signal instead: somebody
                // part-way through typing "gum ta" is editing and keeps the key,
                // and only a caret already at the edge leaves the cell — closing
                // the list on the way out so it cannot hang over the next field.
                var wrapper = field.closest('.ts-wrapper');
                var leaving = null;

                if (wrapper) {
                    if (key === KEYS.up || key === KEYS.down) { return; }

                    var owner = tomSelectOwner(row, wrapper);
                    if (!owner) { return; }

                    index = owner.index;
                    leaving = owner.field.tomselect;
                } else if (index === -1) {
                    return;
                }

                if (key === KEYS.up || key === KEYS.down) {
                    // A plain <select> uses Up/Down to change its own value.
                    if (field.tagName === 'SELECT') { return; }

                    // Before anything else, and whether or not the focus moves.
                    // This is what stopped a number input stepping its value,
                    // and it still stops a text input jumping the caret to the
                    // start or end of what has been typed.
                    event.preventDefault();

                    var all = rows();
                    var here = all.indexOf(row);
                    var next = all[here + (key === KEYS.down ? 1 : -1)];

                    // Nothing above the first row, and nothing below the last.
                    if (!next) { return; }

                    var target = next.querySelector(config.fields[index]);

                    // The same column may not exist on the neighbouring row —
                    // fall back to the nearest one that does.
                    if (!target || target.disabled || target.readOnly) {
                        target = fieldIn(next, index, -1) || fieldIn(next, index, 1);
                    }

                    if (focusField(target)) { event.preventDefault(); }

                    return;
                }

                // Left / Right: only at the edge of the text, so ordinary
                // editing inside a field is untouched.
                if (!atBoundary(field, key === KEYS.left)) { return; }

                var sibling = key === KEYS.left
                    ? fieldIn(row, index - 1, -1)
                    : fieldIn(row, index + 1, 1);

                if (!sibling || !focusField(sibling)) { return; }

                // The list the cursor just left must not stay open over the
                // field it moved to.
                if (leaving) { leaving.close(); }

                event.preventDefault();
            });
        }

        /* ------------------------------------------------------------------
         * Numeric text fields
         * ------------------------------------------------------------------
         *
         * Qty and Unit Price are <input type="text"> so that Left/Right can read
         * a caret position — a number input reports none, which is why arrow
         * navigation did nothing on them. Everything type="number" was doing FOR
         * those fields is put back here, so the change is to the widget and not
         * to what the form accepts:
         *
         *   can't type letters   a keystroke that would not leave a plain figure
         *                        behind is refused before it lands, so the field
         *                        cannot hold "12a.5" even for a moment. Paste is
         *                        filtered the same way.
         *   min / step           carried as data-min and data-step and checked
         *                        through setCustomValidity, so the browser
         *                        reports them in its own bubble on submit,
         *                        exactly where min= used to.
         *   required             untouched; still native.
         *
         * THE SERVER IS UNCHANGED and is still what decides — StockPurchase and
         * StockIssue validate these fields exactly as they did. This is the
         * courtesy layer, the same one the markup used to get for free.
         *
         * Delegated from the document: rows are added after load, and a filter
         * bound per row would miss every one of them.
         */
        var NUMERIC = '.js-numeric';

        /** A plain figure: digits, at most one point, nothing else. */
        function isFigure(text, decimals) {
            if (text === '') { return true; }

            if (!/^\d*\.?\d*$/.test(text)) { return false; }

            var point = text.indexOf('.');

            return point === -1 || (text.length - point - 1) <= decimals;
        }

        /** How many decimal places data-step allows. */
        function decimalsOf(field) {
            var step = String(field.dataset.step || '');
            var point = step.indexOf('.');

            return point === -1 ? 0 : step.length - point - 1;
        }

        /** What the field would hold if this text were inserted now. */
        function projected(field, text) {
            var value = field.value || '';
            var start = field.selectionStart;
            var end = field.selectionEnd;

            if (start === null || start === undefined) { return value + text; }

            return value.slice(0, start) + text + value.slice(end);
        }

        document.addEventListener('beforeinput', function (event) {
            var field = event.target;

            if (!field || !field.matches || !field.matches(NUMERIC)) { return; }

            // Deleting can only ever shorten a figure, and the composition and
            // history types carry nothing to inspect.
            var type = event.inputType || '';
            if (type.indexOf('delete') === 0 || type.indexOf('history') === 0) { return; }

            var text = event.data;

            if ((text === null || text === undefined) && event.dataTransfer) {
                text = event.dataTransfer.getData('text');
            }

            if (text === null || text === undefined) { return; }

            if (!isFigure(projected(field, text), decimalsOf(field))) {
                event.preventDefault();
            }
        });

        /**
         * min and step, reported the way the browser used to report them.
         *
         * Re-checked on every keystroke rather than only on submit, so a message
         * clears itself the moment the figure is corrected.
         */
        function checkNumeric(field) {
            var value = (field.value || '').trim();

            if (value === '') { field.setCustomValidity(''); return; }

            var min = field.dataset.min;
            var decimals = decimalsOf(field);

            // Shape and precision are reported apart, so a value the keystroke
            // filter could never have produced — a pasted "-5", a restored
            // draft — is not explained as a decimal-places problem.
            if (!isFigure(value, 20)) {
                field.setCustomValidity('Enter a number.');

                return;
            }

            if (!isFigure(value, decimals)) {
                field.setCustomValidity('Enter a number with at most ' + decimals + ' decimal places.');

                return;
            }

            if (min !== undefined && parseFloat(value) < parseFloat(min)) {
                field.setCustomValidity('Enter a number of ' + min + ' or more.');

                return;
            }

            field.setCustomValidity('');
        }

        document.addEventListener('input', function (event) {
            if (event.target && event.target.matches && event.target.matches(NUMERIC)) {
                checkNumeric(event.target);
            }
        });

        window.gxItemPicker = itemPicker;
        window.gxArrowNav = arrowNav;
    })();
</script>
