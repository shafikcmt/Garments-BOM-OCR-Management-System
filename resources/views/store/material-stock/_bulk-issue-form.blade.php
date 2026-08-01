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
                <p class="bi-notice-text">Details from an earlier entry are still saved on this device.</p>
            </div>
            <div class="bi-notice-actions">
                <button type="button" class="btn btn-primary bi-btn-xs" @click="restoreDraft()">
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore
                </button>
                <button type="button" class="btn btn-outline-secondary bi-btn-xs" @click="discardDraft()">Discard</button>
            </div>
        </div>

        <form method="POST" id="biForm" action="{{ route('store.material.bulk-issues.store') }}">
            @csrf
            <input type="hidden" name="_method" id="biMethod" value="">
            <input type="hidden" name="booking_po_id" id="biPoId" value="" required>

            {{-- SECTION 1 — find the paperwork. Every section below is on this
                 same page: the numbers are a reading order, not navigation. --}}
            <section class="bi-sect" data-bi-section="1">

            <div class="bi-card">
            <div class="bi-sec">
                <div>
                    <h6 class="bi-sec-title"><span class="bi-sec-num">01</span>Select Purchase Order</h6>
                    <p class="bi-sec-sub">Find the booking by its order details, its PO number, or the material on it.</p>
                </div>
            </div>

            {{-- One control, not two: the type selector sits inside the search
                 field so there is a single label, a single magnifier and a
                 single place to type. --}}
            <label class="form-label" for="biPoSearch">Find Purchase Order <span class="text-danger">*</span></label>
            <div class="bi-search mb-3" id="biSearchWrap">
                <div class="input-group">
                    <select class="form-select bi-filter-type" id="biFilterType" aria-label="Search by">
                        {{-- One flat list in the order Store asked for. The first
                             option is the default: the reset in
                             bulk-issue-table.js selects index 0 rather than a
                             hard-coded value, so reordering here is enough. --}}
                        <option value="season" selected>Season</option>
                        <option value="buyer">Buyer Name</option>
                        <option value="style">Style Number</option>
                        {{-- The buyer's order/contract PO, carried by the GMNTS
                             PO Number and Initial Contract Number columns
                             (identical values on every row). NOT the material PO
                             this system generates. --}}
                        <option value="contract_po">PO Number</option>
                        <option value="gmts_color">GMTS Color Name</option>
                        <option value="material_name">Material Name</option>
                        <option value="material_description">Material Description</option>
                        {{-- The workbooks carry no separate Art. No column yet,
                             so this searches the same supplier article SAP Code
                             does. Kept as its own handle: a sheet that adds the
                             column starts working with no code change. --}}
                        <option value="art_no">Art. No</option>
                        <option value="sap_code">SAP Code</option>
                        <option value="material_color">Material Color</option>
                        <option value="size">Size</option>
                    </select>
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="text" class="form-control" id="biPoSearch" autocomplete="off"
                           placeholder="Click or type to browse…"
                           role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="biPoList">
                    <span class="bi-pick-status">
                        <span class="spinner-border spinner-border-sm text-primary d-none" id="biPoSpin" role="status" aria-hidden="true"></span>
                        <button type="button" class="btn-close d-none" id="biPoClear" aria-label="Clear search"></button>
                    </span>
                </div>
                {{-- The value chosen at step 1, shown so the list below is
                     never a mystery — and clearable, which is how the user gets
                     back to step 1 for a different value or field. --}}
                <div id="biPoChip" class="d-none mt-2"></div>

                <div id="biPoPanel" class="bi-search-panel d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                    {{-- Sticky: after a scroll this line is the only thing
                         saying which field's values are on screen. --}}
                    <div class="px-3 py-2 border-bottom" id="biPoHint"></div>
                    <div class="list-group list-group-flush" id="biPoList" role="listbox"></div>
                </div>
            </div>

            {{-- Loading / failure state for the PO lookup. Without this a failed
                 or slow fetch just left the summary card reading "—" with no
                 explanation of why. --}}
            <div id="biPoLoading" class="d-none d-flex align-items-center gap-2 text-muted small mb-3">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <span>Loading PO details…</span>
            </div>
            <div id="biPoError" class="alert alert-warning py-2 px-3 small d-none mb-3" role="alert"></div>

            {{-- Step 2 — the selection locks into a chip with the PO-level
                 summary beside it, and opens the item picker. --}}
            <div id="biSelectedRow" class="d-none">
                <div id="biSummaryGrid" class="p-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <span class="bi-chip-sel"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span id="biSelectedText">—</span>
                            {{-- Labelled, not a bare ✕: on a chip that already
                                 shows a PO number, a lone ✕ reads as "delete
                                 this PO" rather than "pick a different one". --}}
                            <button type="button" class="btn btn-sm btn-link p-0 ms-2 text-decoration-none bi-btn-inline" id="biClearPo" title="Pick a different PO, PI or Invoice"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Change</button>
                        </span>
                        <span class="badge bg-success-subtle text-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Selected</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">Buyer</div><div class="bi-sum-value" data-sum="buyer_name">—</div></div>
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">Season</div><div class="bi-sum-value" data-sum="season_name">—</div></div>
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">PO Number</div><div class="bi-sum-value" data-sum="po_no">—</div></div>
                        <div class="col-6 col-xl-3"><div class="bi-sum-label">Styles / Items</div><div class="bi-sum-value" id="biSumCounts">—</div></div>
                    </div>
                </div>
            </div>
            </div>{{-- /card --}}
            </section>{{-- /section 1 --}}

            {{-- SECTION 2 — one quantity block per selected item. Kept above the
                 indent header because this is what the stock balance can block,
                 and there is no point typing indent details for an issue the
                 ledger will not allow. The four fields, their colour coding and
                 the per-item rules are unchanged.

                 Locked until a PO is chosen: the item list can only be built
                 from a booking, so acting here first is not a valid order. --}}
            <section class="bi-sect" data-bi-section="2"
                     :class="{ 'is-locked': !hasPo }" :inert="!hasPo">

            <div class="bi-card" :class="{ 'bi-locked': !hasPo }">
            <div class="bi-sec">
                <div>
                    <h6 class="bi-sec-title"><span class="bi-sec-num">02</span>Issue Quantities</h6>
                    <p class="bi-sec-sub">Choose the item(s) to issue against this PO, then split each one into Bulk / Sample / Liability / Dead.</p>
                </div>
                <button type="button" class="btn btn-primary btn-sm bi-btn-xs" id="biPickBtn" data-bs-toggle="modal" data-bs-target="#biItemsModal">
                    <i class="bi bi-list-check me-1" aria-hidden="true"></i>Select Items
                </button>
            </div>

            <div class="bi-lock-note" x-show="!hasPo">
                <i class="bi bi-lock" aria-hidden="true"></i>Select a Purchase Order above to choose items.
            </div>

            <div id="biItemRows" class="d-flex flex-column gap-2"></div>
            <div id="biNoItems" class="bi-empty">
                <div class="bi-empty-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
                <div class="bi-empty-title">No items selected yet</div>
                <p class="bi-empty-text">Pick the material lines to issue against this PO. Each one splits into Bulk, Sample, Liability and Dead.</p>
                {{-- The action, in the place the user is already looking. Same
                     modal hook as the header button — no new JS. --}}
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#biItemsModal">
                    <i class="bi bi-list-check me-1" aria-hidden="true"></i>Select Items
                </button>
            </div>
            <div class="d-flex justify-content-end mt-3 d-none" id="biAddMoreWrap">
                <button type="button" class="btn btn-sm btn-outline-primary bi-btn-xs" data-bs-toggle="modal" data-bs-target="#biItemsModal">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add More Items
                </button>
            </div>
            <div class="alert alert-warning py-2 px-3 small d-none mt-3 mb-0" id="biOverWarn"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><span id="biOverText"></span></div>
            </div>{{-- /card --}}
            </section>{{-- /section 2 --}}

            {{-- SECTION 3 — indent header. Shared by every item in the
                 submission. Locked with section 2 on the same dependency. --}}
            <section class="bi-sect" data-bi-section="3"
                     :class="{ 'is-locked': !hasPo }" :inert="!hasPo">

            <div class="bi-card" :class="{ 'bi-locked': !hasPo }">
            <div class="bi-sec">
                <div>
                    <h6 class="bi-sec-title"><span class="bi-sec-num">03</span>Indent Details</h6>
                    <p class="bi-sec-sub">These details apply to every item in this issue.</p>
                </div>
            </div>

            @if($requisitions->isNotEmpty())
                <div class="mb-3">
                    <label class="form-label">Fulfil Requisition <span class="text-muted small fw-normal">(optional)</span></label>
                    <select name="material_requisition_id" id="biReq" class="form-select">
                        <option value="">None</option>
                        @foreach($requisitions as $req)
                            <option value="{{ $req->id }}">#{{ $req->id }} · {{ $req->po_no }} · {{ $req->material_description }} ({{ ucfirst($req->status) }})</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="row g-3">
                <div class="col-12 col-sm-6 col-xl-3">
                    <label class="form-label">Indent Section</label>
                    <select name="indent_section" id="biSection" class="form-select" @change="queueDraft()">
                        <option value="">Select…</option>
                        @foreach($sections as $section)<option value="{{ $section }}">{{ $section }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="biPerson">Indent Person</label><input name="indent_person" id="biPerson" class="form-control" maxlength="100" placeholder="Name of requester" @input="queueDraft()"></div>
                <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="biReqNo">Requisition No</label><input name="requisition_number" id="biReqNo" class="form-control" maxlength="100" placeholder="Optional reference" @input="queueDraft()"></div>
                <div class="col-12 col-sm-6 col-xl-3"><label class="form-label" for="biIssueDate">Issue Date <span class="text-danger">*</span></label><input type="date" name="issue_date" id="biIssueDate" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="form-control" required @input="syncIssueDate()" @change="syncIssueDate()"></div>
                <div class="col-12 col-xl-6">
                    <label class="form-label" for="biIssueNo">Issue No</label>
                    {{-- Pre-filled with the generated number. bi-suggested renders it
                         lighter/italic until the user types, so it reads as a
                         suggestion rather than a value they entered. --}}
                    <input name="issue_no" id="biIssueNo" class="form-control bi-suggested" autocomplete="off" @input="queueDraft()">
                    <div class="form-text"><i class="bi bi-magic me-1" aria-hidden="true"></i>Auto-suggested · editable</div>
                </div>
            </div>
            </div>{{-- /card --}}
            </section>{{-- /section 3 --}}

            {{-- Remarks — last, because it describes the issue as a whole. --}}
            <section class="bi-sect" data-bi-section="4"
                     :class="{ 'is-locked': !hasPo }" :inert="!hasPo">
                <div class="bi-card" :class="{ 'bi-locked': !hasPo }">
                    <label class="form-label" for="biRemarks">Remarks <span class="text-muted small fw-normal">(optional)</span></label>
                    <div class="bi-remarks">
                        <textarea name="remarks" id="biRemarks" rows="3" class="form-control" maxlength="1000"
                                  placeholder="Gate pass number, who collected the material, or why it was issued."
                                  @input="syncRemarks(); queueDraft()"></textarea>
                        <span class="bi-remarks-count" :class="remarksLength > remarksMax - 50 ? 'is-warn' : ''" aria-hidden="true">
                            <span x-text="remarksLength"></span>/<span x-text="remarksMax"></span>
                        </span>
                    </div>
                </div>
            </section>

            {{-- Sticky status + actions. No step counter: the whole form is on
                 one page, so what matters is how far the data is from saveable —
                 items chosen, and anything currently blocking Save. --}}
            <div class="bi-wizard-bar">
                <div class="bi-bar-meta">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="bi-bar-step" x-text="statusText()"></span>
                        <span class="bi-bar-chip" x-show="itemCount > 0" x-cloak>
                            <i class="bi bi-check2-square" aria-hidden="true"></i>
                            <span x-text="itemCount"></span> item(s) selected
                        </span>
                        <span class="bi-bar-chip is-error" x-show="errorCount > 0" x-cloak>
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <span x-text="errorCount"></span> to fix
                        </span>
                    </div>
                    <p class="bi-bar-hint mb-0" x-show="hasPo && itemCount > 0" x-cloak>
                        <i class="bi bi-lightbulb" aria-hidden="true"></i>Enter at least one of the four quantities per item.
                    </p>
                </div>

                <div class="bi-bar-actions">
                    {{-- Not data-bs-dismiss: a part-filled form is confirmed
                         before it is thrown away. --}}
                    <button type="button" class="btn btn-link text-decoration-none text-secondary" @click="requestClose()">Cancel</button>

                    {{-- "Confirm" for a new issue; the JS swaps it to "Update"
                         when an existing one is being corrected. Disabled until
                         a PO, an item and at least one quantity are in place —
                         the same rules the server enforces on save. --}}
                    <button type="submit" class="btn btn-primary" :disabled="!canSave()" :title="saveBlocker() || ''">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i><span id="biSaveLabel">Confirm</span>
                    </button>
                </div>
            </div>
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
