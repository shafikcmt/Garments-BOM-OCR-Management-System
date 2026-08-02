{{-- New / Edit Bulk Issue form.

     Rendered by two shells: the slide-in panel on the Bulk Issuing index, and
     the full-page /bulk-issues/create + /bulk-issues/{id}/edit route. Both use
     the same element ids, so one JavaScript module drives either — and there is
     one form to maintain rather than a page and a panel drifting apart.

     Expects: $requisitions, $sections. --}}
        {{-- Draft restore. Offered rather than applied, since the panel may well
             be a fresh entry that should not inherit yesterday's typing. --}}
        <div class="bi-notice" x-show="draftAvailable" x-cloak x-transition.opacity role="status">
            <span class="bi-notice-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
            <div class="bi-notice-body">
                <div class="bi-notice-title">Unsaved draft found</div>
                <p class="bi-notice-text">From an earlier entry on this device.</p>
            </div>
            <div class="bi-notice-actions">
                {{-- Icon-free, like every other labelled action on this screen:
                     components.css collapses a .btn with a direct-child bi icon
                     to an unlabelled square. See the Filters dialog footer. --}}
                <button type="button" class="btn btn-primary bi-btn-xs" @click="restoreDraft()">Restore</button>
                <button type="button" class="btn btn-outline-secondary bi-btn-xs" @click="discardDraft()">Discard</button>
            </div>
        </div>

        <form method="POST" id="biForm" action="{{ route('store.material.bulk-issues.store') }}">
            @csrf
            <input type="hidden" name="_method" id="biMethod" value="">
            <input type="hidden" name="booking_po_id" id="biPoId" value="" required>

            {{-- THE ITEM GRID — one screen, not a wizard.
                 Filters (which include the PO itself) → items → Indent Details
                 → Save. There is no "select a PO" step and no item-picker
                 dialog: choosing a PO in the Filters dialog loads its items
                 straight into the grid below, and the quantities are typed
                 where the items already are.

                 Not gated on hasPo, unlike the Indent section further down: this
                 card holds the empty state whose button opens the Filters
                 dialog, so it has to stay reachable before a PO exists.

                 No heading of its own: the page title directly above already
                 names this screen, and the line under it already says what to
                 do. Saying it a third time here was the clutter, not the
                 information. --}}
            <section class="bi-sect" data-bi-section="2">

            <div class="bi-card">

            {{-- Choosing the PO is the one required first action, so it is the
                 first thing on the card rather than a button that opens a
                 dialog to reveal it. One box: the term goes to all eleven
                 fields the matrix shows (SMART_SEARCH_GROUPS), so a SAP Code or
                 an Art. No finds a PO as readily as a style does.

                 Replaced a 200px dashed empty-state panel whose only content
                 was a button to open the Filters dialog — a box, a click and a
                 modal standing between the user and a text field. --}}
            <div id="biPoPicker">
                <div class="bi-search" id="biSearchWrap">
                    <div class="bi-mx-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="text" class="form-control" id="biPoSearch" autocomplete="off"
                               placeholder="Search PO, style, buyer or season to start"
                               role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="biPoList">
                        <span class="bi-pick-status">
                            <span class="spinner-border spinner-border-sm text-primary d-none" id="biPoSpin" role="status" aria-hidden="true"></span>
                            <button type="button" class="btn-close d-none" id="biPoClear" aria-label="Clear search"></button>
                        </span>
                    </div>

                    <div id="biPoPanel" class="bi-search-panel d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                        <div class="bi-po-hint" id="biPoHint"></div>
                        <div class="list-group list-group-flush" id="biPoList" role="listbox"></div>
                    </div>
                </div>
                <p class="bi-po-help mb-0">Type a PO number, style, buyer or material to begin.</p>
            </div>

            {{-- The loaded PO, stated above the grid. Every row carries the same
                 PO — one issue is recorded against one booking — so it is said
                 once here rather than read off 55 identical cells. --}}
            <div id="biSelectedRow" class="d-none">
                <div id="biSummaryGrid" class="bi-po-summary">
                    <span class="bi-chip-sel"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span id="biSelectedText">—</span></span>
                    <div class="bi-sum-facts">
                        <div><div class="bi-sum-label">Buyer</div><div class="bi-sum-value" data-sum="buyer_name">—</div></div>
                        <div><div class="bi-sum-label">Season</div><div class="bi-sum-value" data-sum="season_name">—</div></div>
                        <div><div class="bi-sum-label">PO Number</div><div class="bi-sum-value" data-sum="po_no">—</div></div>
                        <div><div class="bi-sum-label">Styles / Items</div><div class="bi-sum-value" id="biSumCounts">—</div></div>
                    </div>
                    {{-- Labelled, not a bare ✕: on a chip that already shows a PO
                         number, a lone ✕ reads as "delete this PO" rather than
                         "pick a different one". --}}
                    {{-- Brings the search box back rather than opening the
                         Filters dialog: the dialog narrows the rows of a PO,
                         and the point of this button is to not have one. --}}
                    <button type="button" class="btn btn-sm btn-outline-secondary bi-btn-xs" id="biClearPo"
                            title="Load a different PO">Change PO</button>
                </div>
            </div>

            {{-- Loading / failure state for the PO lookup. Without this a failed
                 or slow fetch just left the summary reading "—" with no
                 explanation of why. --}}
            <div id="biPoLoading" class="d-none d-flex align-items-center gap-2 text-muted small mb-3">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <span>Loading items…</span>
            </div>
            <div id="biPoError" class="alert alert-warning py-2 px-3 small d-none mb-3" role="alert"></div>

            {{-- Search + the Filters dialog over the rows already in the matrix.
                 Both are display-only — a row hidden here keeps whatever was
                 typed in it and still saves, which is why the hidden count stays
                 on screen rather than being left for the user to work out.
                 Excluding a row from the issue is the checkbox, not the filter,
                 and the two look different for exactly that reason. --}}
            <div class="bi-mx-bar" x-show="itemCount > 0" x-cloak>
                <div class="bi-mx-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="text" id="biMatrixSearch" class="form-control" autocomplete="off"
                           placeholder="Search style, material, SAP code…"
                           aria-label="Search the items">
                </div>
                <span class="bi-mx-showing d-none" id="biMatrixShowing" aria-live="polite"></span>
                <button type="button" class="btn bi-mx-filterbtn" id="biMatrixFilterBtn"
                        data-bs-toggle="modal" data-bs-target="#biMatrixFilterModal">Filters</button>
            </div>

            {{-- The matrix scrolls inside its own box, both ways: the page
                 itself must never grow a second horizontal scrollbar. --}}
            <div class="bi-mx-card" x-show="itemCount > 0" x-cloak>
                <table class="bi-mx-table">
                    <thead>
                        <tr>
                            {{-- Include / exclude. The whole PO loads, so this is
                                 how a line the user is not issuing today is kept
                                 out of the save without clearing what is typed
                                 in the lines they are. Hidden in edit mode,
                                 which corrects exactly one row. --}}
                            <th class="bi-mx-check">
                                <input type="checkbox" class="form-check-input" id="biIncludeAll"
                                       aria-label="Include or exclude every row on screen">
                            </th>
                            <th class="bi-mx-po">Season</th>
                            <th class="bi-mx-po">Buyer Name</th>
                            <th class="bi-mx-po">Style Number</th>
                            <th class="bi-mx-po">PO Number</th>
                            <th class="bi-mx-po">GMTS Color Name</th>
                            <th>Material Name</th>
                            <th class="bi-mx-desc">Material Description</th>
                            <th class="bi-mx-art">Art. No</th>
                            <th>SAP Code</th>
                            <th>Material Color</th>
                            <th>Size</th>
                            <th class="text-center">Unit</th>
                            <th class="text-end">Available</th>
                            <th class="text-center bi-mx-h-bulk">Bulk Issued Qty</th>
                            <th class="text-center bi-mx-h-sample">Sample Issued Qty</th>
                            <th class="text-center bi-mx-h-liability">Liability Stock Qty</th>
                            <th class="text-center bi-mx-h-dead">Dead Stock Qty</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody id="biItemRows"></tbody>
                </table>
            </div>

            {{-- The empty state is the search box above, not a panel down here:
                 an instruction to "select a PO" is redundant next to the field
                 that selects one. This is only for the case a PO loaded and
                 turned out to carry nothing. --}}
            <div id="biNoItems" class="bi-empty d-none">
                <div class="bi-empty-title">This PO has no items to issue</div>
                <p class="bi-empty-text mb-0">Try a different PO.</p>
            </div>
            <div class="alert alert-warning py-2 px-3 small d-none mt-3 mb-0" id="biOverWarn"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><span id="biOverText"></span></div>
            </div>{{-- /card --}}
            </section>{{-- /matrix --}}

            {{-- SECTION 3 + the action bar. On the full-page shell these are
                 pinned to the bottom of the window (see .bi-page .bi-bottom in
                 the styles partial), so the indent header and Save stay in
                 reach however far down the matrix is scrolled. Inside the
                 slide-in panel the same markup stays in normal flow. --}}
            <div class="bi-bottom">
            <section class="bi-sect" data-bi-section="3"
                     :class="{ 'is-locked': !hasPo }" :inert="!hasPo">

            <div class="bi-card bi-indent-card" :class="{ 'bi-locked': !hasPo }">
            <div class="bi-indent-title">
                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Indent Details
                <span class="bi-indent-note">Applies to all items</span>
            </div>

            <div class="row g-2 bi-indent-grid">
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="biIssueDate">Issue Date <span class="text-danger">*</span></label>
                    <input type="date" name="issue_date" id="biIssueDate" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required @input="syncIssueDate()" @change="syncIssueDate()">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="biSection">Indent Section</label>
                    <select name="indent_section" id="biSection" class="form-select" @change="queueDraft()">
                        <option value="">Select…</option>
                        @foreach($sections as $section)<option value="{{ $section }}">{{ $section }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-2"><label class="form-label" for="biPerson">Indent Person</label><input name="indent_person" id="biPerson" class="form-control" maxlength="100" placeholder="Name of requester" @input="queueDraft()"></div>
                <div class="col-6 col-lg-2"><label class="form-label" for="biReqNo">Requisition Number</label><input name="requisition_number" id="biReqNo" class="form-control" maxlength="100" placeholder="Optional reference" @input="queueDraft()"></div>
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="biIssueNo">Issue No</label>
                    {{-- Pre-filled with the generated number. bi-suggested renders it
                         lighter/italic until the user types, so it reads as a
                         suggestion rather than a value they entered. --}}
                    <input name="issue_no" id="biIssueNo" class="form-control bi-suggested" autocomplete="off"
                           title="Auto-suggested — you can edit" @input="queueDraft()">
                </div>
                {{-- Remarks describes the issue as a whole, so it sits on the
                     same shared header rather than in a card of its own. --}}
                <div class="col-6 col-lg-2">
                    <label class="form-label" for="biRemarks">Remarks</label>
                    <div class="bi-remarks">
                        <textarea name="remarks" id="biRemarks" rows="1" class="form-control" maxlength="1000"
                                  placeholder="Gate pass, collected by, or reason"
                                  @input="syncRemarks(); queueDraft()"></textarea>
                        <span class="bi-remarks-count" :class="remarksLength > remarksMax - 50 ? 'is-warn' : ''" aria-hidden="true">
                            <span x-text="remarksLength"></span>/<span x-text="remarksMax"></span>
                        </span>
                    </div>
                </div>
                @if($requisitions->isNotEmpty())
                    <div class="col-12">
                        <label class="form-label" for="biReq">Fulfil Requisition</label>
                        <select name="material_requisition_id" id="biReq" class="form-select">
                            <option value="">None</option>
                            @foreach($requisitions as $req)
                                <option value="{{ $req->id }}">#{{ $req->id }} · {{ $req->po_no }} · {{ $req->material_description }} ({{ ucfirst($req->status) }})</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
            </div>{{-- /card --}}
            </section>{{-- /section 3 --}}

            {{-- Status + actions. No step counter: the whole form is on one
                 page, so what matters is how far the data is from saveable —
                 items chosen, and anything currently blocking Save. --}}
            <div class="bi-wizard-bar">
                <div class="bi-bar-meta">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="bi-bar-step" x-text="statusText()"></span>
                        {{-- Written by the matrix filter so the count also says
                             how many rows the current filter is hiding — a
                             hidden row still saves whatever was typed in it. --}}
                        <span class="bi-bar-chip" id="biFooterCount" x-show="itemCount > 0" x-cloak></span>
                        <span class="bi-bar-chip is-error" x-show="errorCount > 0" x-cloak>
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <span x-text="errorCount"></span> to fix
                        </span>
                    </div>
                    <p class="bi-bar-hint mb-0" x-show="hasPo && itemCount > 0" x-cloak>
                        <i class="bi bi-lightbulb" aria-hidden="true"></i>Enter at least one quantity per item.
                    </p>
                </div>

                <div class="bi-bar-actions">
                    {{-- Not data-bs-dismiss: a part-filled form is confirmed
                         before it is thrown away. --}}
                    <button type="button" class="btn btn-link text-decoration-none text-secondary" @click="requestClose()">Cancel</button>

                    {{-- "Record Issue" for a new one; the JS swaps it to "Update"
                         when an existing one is being corrected. Matches
                         Receiving's "Record Receiving" — the two screens are
                         the same shape and should read the same way.
                         Disabled until a PO, an item and at least one quantity
                         are in place — the same rules the server enforces. --}}
                    {{-- The one action the whole screen exists for. It keeps its
                         words: a blank square here is the worst possible place
                         for the icon-only collapse in components.css. --}}
                    <button type="submit" class="btn btn-primary" :disabled="!canSave()" :title="saveBlocker() || ''">
                        <span id="biSaveLabel">Record Issue</span>
                    </button>
                </div>
            </div>
            </div>{{-- /bi-bottom --}}
        </form>

        {{-- Toasts. Fixed to the viewport so they clear the panel and are not
             clipped by its scroll container. --}}
        <div class="bi-toasts" aria-live="polite" aria-atomic="true">
            <template x-for="t in toasts" :key="t.id">
                <div class="bi-toast shadow-sm" :class="toastClass(t.type)" x-transition.opacity role="status">
                    <i class="bi" :class="toastIcon(t.type)" aria-hidden="true"></i>
                    <span class="flex-grow-1" x-text="t.message"></span>
                    <button type="button" class="btn-close btn-close-sm" @click="dismiss(t.id)" aria-label="Dismiss"></button>
                </div>
            </template>
        </div>
