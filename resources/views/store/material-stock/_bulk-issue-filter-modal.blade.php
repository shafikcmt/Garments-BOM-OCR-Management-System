{{-- The one dialog that decides which rows are in the matrix.

     Eleven equal fields, every one a searchable dropdown. There is no separate
     free-text search box above them: PO, Style, Buyer, Season and Material were
     already fields in this grid, so a second search mechanism on top was one
     mechanism too many.

     The grid does two jobs, decided by whether a booking is loaded yet:

     A. Nothing loaded — IDENTIFY. Each field's options are the distinct values
        that field holds across every booking, fetched from po-search
        (?type=<group>) the first time the field is focused, never before —
        eleven eager scans of ExcelCell on open is exactly the cost this avoids.
        Choosing a value asks po-search (?type=<group>&value=<v>) which bookings
        carry it. One match loads straight into the matrix; several are listed
        below the grid to pick from.

     B. A booking loaded — NARROW. The same eleven fields switch to the row
        filter: their options are now the values the loaded rows actually carry,
        read from the rows themselves, and choosing one hides the rows that do
        not match. No request, no server involved.

     Narrowing is display-only. A row hidden here keeps whatever quantity was
     typed into it and is still submitted, which is why the bar above the matrix
     and the footer both report how many rows are hidden. Leaving a row out of
     the issue is the row's checkbox, not this dialog.

     The grid markup is built once by bulk-issue-table.js (buildFilterGrid) from
     MATRIX_FIELDS, so the field list has one definition rather than one here
     and one there. --}}
<div class="modal fade" id="biMatrixFilterModal" tabindex="-1" aria-labelledby="biMatrixFilterLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content bi-fx-modal">
            <div class="modal-header">
                {{-- No subtitle. The section heading below is rewritten per mode
                     and already says what the dialog is for; a second line
                     saying it again is the clutter this pass removed. --}}
                <h5 class="modal-title mb-0" id="biMatrixFilterLabel">Filters</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="bi-fx-sect">
                    <div class="bi-fx-sect-head">
                        <span class="bi-fx-sect-title" id="biFxSectTitle">Find a PO</span>
                        <span class="bi-fx-opt" id="biFxSectNote">Click a field and type to search</span>
                    </div>

                    {{-- Filled by buildFilterGrid(); one .bi-fx-card per field. --}}
                    <div class="bi-fx-grid" id="biMatrixFilterGrid"></div>
                </div>

                {{-- Bookings matching the value picked above, when more than one
                     carries it. Sits under the grid rather than floating over
                     it: the dialog body scrolls, and an absolutely positioned
                     panel inside a scrolling box is clipped by it. --}}
                <div id="biPoPanel" class="bi-search-panel bi-po-results d-none mt-3 bg-body border rounded-3">
                    <div class="bi-po-hint" id="biPoHint"></div>
                    <div class="list-group list-group-flush" id="biPoList" role="listbox"></div>
                </div>
            </div>

            {{-- No icons on these two. components.css turns any .btn with a
                 direct-child <i class="bi"> into a 38px icon-only square and
                 hides its label — which is fine for a row of repeated table
                 actions, but it would put a wide "Reset filters" pill next to
                 an unlabelled blue square in the same footer. A dialog's two
                 decisions have to read as two decisions. --}}
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="biMatrixFilterReset">Reset filters</button>
                <button type="button" class="btn btn-primary" id="biMatrixFilterApply" data-bs-dismiss="modal">Apply</button>
            </div>
        </div>
    </div>
</div>
