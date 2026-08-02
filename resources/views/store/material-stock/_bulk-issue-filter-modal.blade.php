{{-- Narrows the items of the PO already loaded. Nothing else.

     Finding the PO is the search box on the form itself, so this dialog no
     longer does double duty — it is only reachable once there are rows to
     narrow, and every field here means the same thing every time.

     Ten fields, not eleven: PO Number is what the form's search box answers,
     and under one PO every row carries the same one, so a dropdown for it would
     offer a single option. MATRIX_FIELDS marks it `server: true` to keep it out
     of this grid while still stamping the row dataset the matrix search reads.

     Each field is a searchable dropdown whose options are the values the loaded
     rows actually carry, read from the rows themselves — no request, no server.

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
                        <span class="bi-fx-sect-title">Narrow the list</span>
                        <span class="bi-fx-opt">Hidden items are still saved</span>
                    </div>

                    {{-- Filled by buildFilterGrid(); one .bi-fx-card per field. --}}
                    <div class="bi-fx-grid" id="biMatrixFilterGrid"></div>
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
