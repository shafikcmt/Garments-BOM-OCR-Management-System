/**
 * Excel-style rectangular cell selection for the Workspace sheet, copy only.
 *
 * Phase 1a of the multi-select work, and deliberately READ-ONLY: it selects
 * cells and it copies them to the clipboard. It never writes a cell value
 * (it only tells the sheet page which rectangle is selected, see
 * table.sheetSelection, so Ctrl+V can fill it), so
 * it cannot interact with the save path, the draft/restore system, or the
 * unchanged-cell pruning that stops a save being truncated. Pasting INTO the
 * sheet is untouched and still belongs entirely to handleMultiPaste() in
 * show.blade.php.
 *
 * WHY THIS NEEDS NO GRID LIBRARY. The sheet already is a coordinate system:
 * the Blade renders data-row-index / data-col-index onto every cell <td>,
 * editable and read-only alike. A selection is therefore just a rectangle of
 * those indices plus a class, and none of the rendering has to change.
 *
 * THE ONE HARD PART is that an editable cell holds a native <input>, which
 * takes mousedown for its own text caret. Painting a range on mousedown would
 * break ordinary editing. So a drag does not begin on mousedown — the anchor
 * is merely remembered, and the input keeps the event and behaves exactly as
 * it always has. Range mode engages only once the pointer reaches a DIFFERENT
 * cell with the button still down, which is a gesture that previously meant
 * nothing at all. A plain click, a caret drag inside one input, typing, Tab
 * and the arrow-key navigation are all untouched by design.
 *
 * HIGHLIGHTING IS PER-CELL, NOT AN OVERLAY. The sheet's first column and its
 * header are position:sticky inside a scrolling wrapper, so one absolutely
 * positioned rectangle would misalign against the frozen column and need
 * recomputing on every scroll. A class on each <td> tracks sticky and scroll
 * for free. It paints through an ::after layer because the role tints and the
 * calculated-cell background are !important and would win over any background
 * set here.
 *
 * Opt in from Blade with [data-cell-select] on the table. Absent, nothing here
 * runs.
 */

/** Cells live in the body only; the header and filter rows are not selectable. */
const CELL = 'tbody td[data-row-index][data-col-index]';

export function initSheetRangeSelect() {
    document.querySelectorAll('table[data-cell-select]').forEach(setup);
}

function setup(table) {
    // Anchor is where the gesture began; focus is where it currently ends.
    // Null anchor means nothing is selected.
    let anchor = null;
    let focus = null;

    // Set between mousedown and mouseup. Painting has not necessarily started:
    // that only happens once the pointer leaves the anchor cell.
    let pending = null;
    let dragging = false;

    // The cells currently wearing the highlight, so each move can repaint the
    // difference instead of walking all ~6,400 <td> on the page.
    let painted = new Set();

    const cellAt = (row, col) => table.querySelector(
        `tbody td[data-row-index="${row}"][data-col-index="${col}"]`
    );

    const coordsOf = (td) => ({
        row: Number(td.dataset.rowIndex),
        col: Number(td.dataset.colIndex),
    });

    /** The inclusive rectangle between the two corners, in any drag direction. */
    function rect() {
        if (!anchor || !focus) return null;

        return {
            top: Math.min(anchor.row, focus.row),
            bottom: Math.max(anchor.row, focus.row),
            left: Math.min(anchor.col, focus.col),
            right: Math.max(anchor.col, focus.col),
        };
    }

    /** The selected rectangle when it spans more than one cell, else null. Read by the paste handler in the sheet page. */
    table.sheetSelection = () => (painted.size > 1 ? rect() : null);

    function paint() {
        const box = rect();
        const next = new Set();

        if (box) {
            for (let row = box.top; row <= box.bottom; row++) {
                for (let col = box.left; col <= box.right; col++) {
                    const td = cellAt(row, col);
                    if (td) next.add(td);
                }
            }
        }

        painted.forEach((td) => {
            if (!next.has(td)) td.classList.remove('cell-selected', 'cell-selected-anchor');
        });

        next.forEach((td) => td.classList.add('cell-selected'));
        painted = next;

        // A single selected cell gets no chrome at all — that is just a focused
        // input, and outlining it would be noise on every ordinary click.
        if (box && next.size > 1) {
            const anchorCell = cellAt(anchor.row, anchor.col);
            if (anchorCell) anchorCell.classList.add('cell-selected-anchor');
        }

        announce(box, next.size);
    }

    function clear() {
        anchor = null;
        focus = null;
        paint();
    }

    /* ---- Gesture ---------------------------------------------------------
     * mousedown remembers the anchor and returns. It does NOT preventDefault
     * and it does NOT paint, so the <input> underneath receives the event
     * untouched and ordinary editing is unaffected.
     */
    table.addEventListener('mousedown', (event) => {
        if (event.button !== 0) return;

        const td = event.target.closest(CELL);
        if (!td || !table.contains(td)) return;

        // Shift extends the existing selection from its anchor, as in Excel.
        // There is no drag to start, and the default is suppressed so the
        // browser does not also extend a text selection across the sheet.
        if (event.shiftKey && anchor) {
            event.preventDefault();
            focus = coordsOf(td);
            paint();
            return;
        }

        pending = coordsOf(td);
        dragging = false;

        // A fresh click collapses any previous range to this one cell, so the
        // old highlight does not linger while the user edits somewhere else.
        anchor = pending;
        focus = pending;
        paint();
    });

    // mousemove, not mouseover. mouseover only fires when the topmost element
    // under the pointer CHANGES, so a fast drag across the sheet can skip cells
    // entirely and, worse, a drag that never lands cleanly on a new cell never
    // engages range mode at all — leaving the browser to paint a native text
    // selection straight across the header instead. mousemove fires throughout
    // the gesture, and closest() resolves whichever cell is under the pointer.
    table.addEventListener('mousemove', (event) => {
        if (!pending) return;

        // The button was released somewhere we never saw it — outside the
        // window, or over a native control that swallowed the mouseup.
        if (event.buttons === 0) {
            endGesture();
            return;
        }

        const td = event.target.closest(CELL);
        if (!td || !table.contains(td)) return;

        const at = coordsOf(td);

        // Cheap early-out: the pointer moves many times within one cell, and
        // repainting ~6,400 <td> on each of those would be the whole cost of
        // this feature.
        if (focus && at.row === focus.row && at.col === focus.col) return;
        if (at.row === pending.row && at.col === pending.col) return;

        // Crossed into another cell with the button held: this is a range drag,
        // not a caret drag. Take over now — drop whatever text selection the
        // input started, and stop it holding the caret.
        if (!dragging) {
            dragging = true;
            table.classList.add('is-range-dragging');
            window.getSelection()?.removeAllRanges();
            if (document.activeElement?.closest?.(CELL)) document.activeElement.blur();
        }

        focus = at;
        paint();
    });

    // While dragging, the browser would otherwise try to select the page text
    // under the pointer.
    table.addEventListener('selectstart', (event) => {
        if (dragging) event.preventDefault();
    });

    function endGesture() {
        pending = null;
        dragging = false;
        table.classList.remove('is-range-dragging');
    }

    // On window, not the table: a drag very often ends with the pointer outside
    // the sheet, and a missed mouseup would leave the gesture stuck on.
    window.addEventListener('mouseup', endGesture);
    window.addEventListener('blur', endGesture);

    /* ---- Copy ------------------------------------------------------------
     * Only when a real range is selected. With one cell — the ordinary case of
     * a focused input — Ctrl+C is left entirely alone, so copying part of a
     * cell's text still works exactly as before.
     */
    document.addEventListener('copy', (event) => {
        const box = rect();
        if (!box || painted.size < 2) return;
        if (!table.contains(document.activeElement) && document.activeElement !== document.body) return;

        const text = toTsv(box);
        if (text === null) return;

        event.preventDefault();
        event.clipboardData?.setData('text/plain', text);
    });

    /** The selection as tab/newline text, which is what Excel reads. */
    function toTsv(box) {
        const rows = [];

        for (let row = box.top; row <= box.bottom; row++) {
            const cols = [];

            for (let col = box.left; col <= box.right; col++) {
                cols.push(valueOf(cellAt(row, col)));
            }

            rows.push(cols.join('\t'));
        }

        return rows.length ? rows.join('\n') : null;
    }

    /**
     * A cell's text, from whichever of the two shapes it is. Read-only and
     * calculated columns carry their value on the display div, so a copied
     * block includes them and lines up with what is on screen — a range that
     * silently dropped its formula columns would be worse than useless.
     */
    function valueOf(td) {
        if (!td) return '';

        const input = td.querySelector('input.excel-editable-cell');
        if (input) return input.value ?? '';

        const display = td.querySelector('[data-cell-value]');
        if (display) return display.dataset.cellValue ?? '';

        return '';
    }

    /* ---- Escape and click-away ------------------------------------------- */
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && painted.size > 1) clear();
    });

    document.addEventListener('mousedown', (event) => {
        if (!event.target.closest(CELL) && painted.size) clear();
    });

    /* ---- Status ----------------------------------------------------------
     * Non-technical users need to be told the block can be copied; the gesture
     * is not discoverable on its own.
     */
    const status = document.querySelector('[data-cell-select-status]');

    function announce(box, count) {
        if (!status) return;

        if (!box || count < 2) {
            status.hidden = true;
            status.textContent = '';
            return;
        }

        const rows = box.bottom - box.top + 1;
        const cols = box.right - box.left + 1;

        status.hidden = false;
        status.textContent = `${rows} row${rows === 1 ? '' : 's'} x ${cols} column${cols === 1 ? '' : 's'} selected — Ctrl+C to copy, Ctrl+V to paste into them`;
    }
}
