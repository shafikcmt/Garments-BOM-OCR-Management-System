{{-- "Add New Order" — the single-row duplicate entry point.

     This replaces the Duplicate button that used to sit on every row. That
     button repeated 50+ times on a normal page for an action used
     occasionally, and it competed with the row number in the one column that
     has to stay scannable. The bulk actions are unchanged: "Duplicate
     Selected" still reads the row checkboxes, and "Duplicate All Matching
     Rows" still takes the exact style group.

     Three steps in one dialog:

       1  FIND    a source row, searched SERVER-SIDE. The grid is paginated, so
                  searching what happens to be rendered would quietly offer only
                  the current page and look like the older rows were gone.
       2  EDIT    the columns a new order usually changes, pre-filled from the
                  source. Two tiers, the SAME two the grid marks — amber for
                  "cannot be reused", grey for "worth a glance" — so the dialog
                  and the sheet teach one visual language rather than two.
       3  SAVE    through the same gate, lineage and placement as every other
                  duplication. Nothing is written before this button.

     The form carries the SOURCE ROW ID plus the edited values only. Everything
     else is copied server-side from the source, so the dialog never has to hold
     — or could ever corrupt — the ~60 columns it does not show. --}}
<div class="modal fade" id="duplicateRowsModal" tabindex="-1" aria-labelledby="addOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="addOrderModalLabel">Duplicate Rows</h5>
                    <div class="text-muted small" id="addOrderSubtitle">Copies of the rows you have selected</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" action="{{ route('uploaded-files.rows.add_order', $excelFile->id) }}" id="addOrderForm">
                @csrf
                <input type="hidden" name="source_row_id" id="addOrderSourceRowId" value="">
                {{-- Carries the step-1 choice into the edit path, so picking
                     "end of file" and then "edit key fields first" does not
                     quietly insert below the source instead. --}}
                <input type="hidden" name="placement" id="addOrderPlacement" value="below_source">

                <div class="modal-body">

                    {{-- ---- STEP 1: where do the copies go? ---------------
                         This step used to be a row FINDER: four filter boxes, a
                         Search button and a results table, to locate a row the
                         merchant had very often already ticked on the grid
                         behind this dialog. It is gone. The rows are whatever
                         is selected, and the only thing left to ask is the one
                         thing the sheet cannot already tell us. --}}
                    <div id="addOrderStepSearch">
                        <div class="ao-panel">
                            <div class="ao-step-head">
                                <span class="ao-step-chip">
                                    <span class="ao-step-num">1</span>
                                    <span id="duplicateSelectionSummary">Nothing selected</span>
                                </span>
                            </div>

                            <fieldset class="ao-place">
                                <legend class="ao-label mb-2">Where should the copies go?</legend>

                                <label class="ao-place-option">
                                    <input type="radio" name="ao_placement" value="below_source" checked
                                           class="form-check-input" id="duplicatePlaceBelow">
                                    <span>
                                        <span class="ao-place-title">Below each source row</span>
                                        <span class="ao-place-hint">Each copy stays with the row it came from. Everything under it shifts down.</span>
                                    </span>
                                </label>

                                <label class="ao-place-option">
                                    <input type="radio" name="ao_placement" value="end_of_file"
                                           class="form-check-input" id="duplicatePlaceEnd">
                                    <span>
                                        <span class="ao-place-title">At the end of the file</span>
                                        <span class="ao-place-hint">All copies together as one block at the bottom, ready to edit. Nothing already in the sheet moves.</span>
                                    </span>
                                </label>
                            </fieldset>

                            <div class="ao-actions">
                                <button type="button" class="btn btn-primary btn-sm" id="duplicateNowButton">
                                    <i class="bi bi-copy me-1" aria-hidden="true"></i>Duplicate now
                                </button>
                                {{-- performRowDuplication() discards overrides
                                     for more than one source row — there would
                                     be no saying which copy the typed values
                                     belonged to. So this is disabled rather
                                     than accepting edits it would then throw
                                     away, and the note below says why. --}}
                                <button type="button" class="btn btn-outline-primary btn-sm" id="duplicateEditFirstButton">
                                    Edit key fields first
                                </button>
                            </div>

                            <p class="ao-note-inline d-none mb-0" id="duplicateEditFirstNote">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                Editing before the copy is written works on one row at a time. Duplicate now, then edit the copies in the sheet.
                            </p>
                        </div>
                    </div>

                    {{-- ---- STEP 2: edit the new order ------------------- --}}
                    <div id="addOrderStepEdit" class="d-none">
                        <div class="ao-step-head">
                            <span class="ao-step-num">2</span>
                            <span class="ao-step-title">Check the values for the new order</span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="addOrderBackButton">
                                Back
                            </button>
                        </div>

                        <div class="ao-source-strip" id="addOrderSourceStrip"></div>

                        @if($addOrderHeaders->isEmpty())
                            {{-- No editable column among the thirteen. The order
                                 can still be created — every value is copied —
                                 there is simply nothing here for this user to
                                 change, and saying so is better than an empty
                                 panel that looks broken. --}}
                            <div class="alert alert-light border small mb-0">
                                All values will be copied from the source row. You do not own any of the
                                columns a new order usually changes, so there is nothing to edit here.
                            </div>
                        @else
                            @php
                                $alwaysHeaders = $addOrderHeaders->where('tier', 'always');
                                $likelyHeaders = $addOrderHeaders->where('tier', 'likely');
                            @endphp

                            @if($alwaysHeaders->isNotEmpty())
                                <div class="ao-group">
                                    <div class="ao-group-head">
                                        <span class="ao-tier-dot ao-tier-always" aria-hidden="true"></span>
                                        Must be changed for a new order
                                    </div>
                                    <div class="row g-2">
                                        @foreach($alwaysHeaders as $header)
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1" for="ao-field-{{ $header['id'] }}">{{ $header['name'] }}</label>
                                                <input
                                                    type="{{ $header['field_type'] === 'date' ? 'date' : ($header['field_type'] === 'number' ? 'number' : 'text') }}"
                                                    class="form-control form-control-sm ao-input ao-input-always"
                                                    id="ao-field-{{ $header['id'] }}"
                                                    name="overrides[{{ $header['id'] }}]"
                                                    data-header-id="{{ $header['id'] }}">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if($likelyHeaders->isNotEmpty())
                                <div class="ao-group">
                                    <div class="ao-group-head">
                                        <span class="ao-tier-dot ao-tier-likely" aria-hidden="true"></span>
                                        Worth checking — often correct as copied
                                    </div>
                                    <div class="row g-2">
                                        @foreach($likelyHeaders as $header)
                                            <div class="col-md-4">
                                                <label class="form-label small mb-1" for="ao-field-{{ $header['id'] }}">{{ $header['name'] }}</label>
                                                <input
                                                    type="{{ $header['field_type'] === 'date' ? 'date' : ($header['field_type'] === 'number' ? 'number' : 'text') }}"
                                                    class="form-control form-control-sm ao-input ao-input-likely"
                                                    id="ao-field-{{ $header['id'] }}"
                                                    name="overrides[{{ $header['id'] }}]"
                                                    data-header-id="{{ $header['id'] }}">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <p class="text-muted small mb-0 mt-2">
                                Every other column is copied from the source row and can be edited in the sheet afterwards.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addOrderSubmitButton" disabled>Duplicate row</button>
                </div>
            </form>
        </div>
    </div>
</div>
