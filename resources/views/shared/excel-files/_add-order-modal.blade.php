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
<div class="modal fade" id="addOrderModal" tabindex="-1" aria-labelledby="addOrderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="addOrderModalLabel">Add New Order</h5>
                    <div class="text-muted small" id="addOrderSubtitle">Based on an existing row in this file</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" action="{{ route('uploaded-files.rows.add_order', $excelFile->id) }}" id="addOrderForm">
                @csrf
                <input type="hidden" name="source_row_id" id="addOrderSourceRowId" value="">

                <div class="modal-body">

                    {{-- ---- STEP 1: find the source row ------------------ --}}
                    <div id="addOrderStepSearch">
                        @php
                            // header_key => the <select> id it fills. Kept here so
                            // the three dropdowns are rendered from one loop rather
                            // than three near-identical blocks.
                            $aoFilterFields = [
                                'buyer_name' => ['id' => 'addOrderFilterBuyer', 'label' => 'Buyer', 'any' => 'Any buyer'],
                                'season_name' => ['id' => 'addOrderFilterSeason', 'label' => 'Season', 'any' => 'Any season'],
                            ];
                            $aoStyleOptions = $addOrderFilterOptions['style_name'] ?? [];
                        @endphp

                        {{-- The search area is its own tinted panel, so the
                             controls and the results below read as two things
                             rather than one long white column. --}}
                        <div class="ao-panel">
                            <div class="ao-step-head">
                                <span class="ao-step-chip">
                                    <span class="ao-step-num">1</span>
                                    Find the order to copy
                                </span>
                            </div>

                            {{-- STYLE IS THE PRIMARY FILTER, full width and first.
                                 Duplication here is nearly always "another order
                                 of the same style, a few fields different", so
                                 style is the question the merchant already has an
                                 answer to. Buyer and Season narrow what it
                                 returns; they are not how the search starts.

                                 Selecting a style searches immediately — having
                                 answered the main question, being made to press
                                 Search is a step that tells the user nothing. --}}
                            <div class="ao-field ao-field-primary">
                                <label class="ao-label" for="addOrderFilterStyle">
                                    Style <span class="ao-label-hint">— start here</span>
                                </label>
                                <select class="form-select form-select-sm ao-filter ao-filter-auto"
                                        id="addOrderFilterStyle" data-filter="style_name">
                                    <option value="">Any style</option>
                                    @foreach($aoStyleOptions as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="row g-2 mt-1">
                                @foreach($aoFilterFields as $headerKey => $field)
                                    <div class="col-md-4">
                                        <div class="ao-field">
                                            <label class="ao-label" for="{{ $field['id'] }}">{{ $field['label'] }}</label>
                                            <select class="form-select form-select-sm ao-filter ao-filter-auto"
                                                    id="{{ $field['id'] }}" data-filter="{{ $headerKey }}">
                                                <option value="">{{ $field['any'] }}</option>
                                                @foreach($addOrderFilterOptions[$headerKey] ?? [] as $option)
                                                    <option value="{{ $option }}">{{ $option }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endforeach

                                {{-- Stays free text. A contract number or a row
                                     number is inherently open-ended, and typing
                                     has no natural "done" moment — so this one
                                     keeps the explicit Search button rather than
                                     firing on every keystroke. --}}
                                <div class="col-md-4">
                                    <div class="ao-field">
                                        <label class="ao-label" for="addOrderFilterAny">Any column / row no.</label>
                                        <input type="text" class="form-control form-control-sm ao-filter"
                                               id="addOrderFilterAny" data-filter="q" placeholder="Contract no., colour…">
                                    </div>
                                </div>
                            </div>

                            <div class="ao-actions">
                                <button type="button" class="btn btn-primary btn-sm" id="addOrderSearchButton">
                                    <i class="bi bi-search me-1" aria-hidden="true"></i>Search
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="addOrderResetButton">
                                    Clear filters
                                </button>
                            </div>
                        </div>

                        {{-- Guidance, not body text: a merchant who cannot find
                             their row needs to notice that the list was cut off. --}}
                        <div class="ao-note d-none" id="addOrderResultNote" role="status">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span id="addOrderResultNoteText"></span>
                        </div>

                        <div class="ao-results-card">
                            <div class="ao-results">
                                <table class="table table-sm align-middle mb-0" id="addOrderResultsTable">
                                    <thead>
                                        <tr>
                                            <th class="ao-col-row">Row</th>
                                            <th>Buyer</th>
                                            <th>Style</th>
                                            <th>Season</th>
                                            <th>Colour</th>
                                            <th>Contract No.</th>
                                            <th class="ao-col-action"><span class="visually-hidden">Choose</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="addOrderResultsBody">
                                        <tr class="ao-empty">
                                            <td colspan="7">
                                                <div class="ao-empty-state">
                                                    <i class="bi bi-search" aria-hidden="true"></i>
                                                    <span>Pick a style above to see the orders you can copy.</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- ---- STEP 2: edit the new order ------------------- --}}
                    <div id="addOrderStepEdit" class="d-none">
                        <div class="ao-step-head">
                            <span class="ao-step-num">2</span>
                            <span class="ao-step-title">Check the values for the new order</span>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="addOrderBackButton">
                                Back to search
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
                    <button type="submit" class="btn btn-primary" id="addOrderSubmitButton" disabled>Add Order</button>
                </div>
            </form>
        </div>
    </div>
</div>
