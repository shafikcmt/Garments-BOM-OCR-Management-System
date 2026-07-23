@extends('layouts.app')

@section('title', 'Material Stock — Receiving')

{{-- Scoped to this page only. Everything below leans on the existing --gx-*
     tokens (slate/blue/emerald) rather than introducing a second palette, so
     Receiving stays consistent with the rest of Store. --}}
@section('styles')
<style>
    /* Page-scoped aliases onto the existing --gx-* palette. Declared here so the
       rules below read as one system without redefining the global theme. */
    #rcvReceiving, #rcvItemsModal {
        --rcv-primary: var(--gx-secondary-600, #2563EB);
        --rcv-border: var(--bs-border-color, #E2E8F0);
        --rcv-text: var(--gx-primary, #0F172A);
        --rcv-text-2: var(--gx-text-muted, #64748B);
        --rcv-text-3: #94A3B8;
        --rcv-surface: #F1F5F9;
        --rcv-radius-sm: 6px;
        --rcv-radius-md: 8px;
        --rcv-radius-lg: 12px;
        --rcv-shadow-dropdown: 0 4px 6px -1px rgba(0,0,0,.05), 0 2px 4px -2px rgba(0,0,0,.03);
        --rcv-transition: 150ms cubic-bezier(.4, 0, .2, 1);
    }

    /* Spinner and clear button ride inside the search field, so neither one
       changes the input-group's width when it appears. */
    .rcv-search .rcv-search-status {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 5;
        display: flex;
        align-items: center;
    }
    .rcv-search .form-control { padding-right: 2.25rem; }
    .rcv-search .btn-close { font-size: .7rem; opacity: .5; transition: opacity 150ms ease; }
    .rcv-search .btn-close:hover { opacity: 1; }

    /* Compact inline hint — one line, no card, no reserved height. It fades
       rather than unmounting, so the row below never jumps as the menu opens. */
    .rcv-hint {
        font-size: .875rem;
        color: var(--rcv-text-3);
        padding: .25rem .125rem;
        opacity: 1;
        transition: opacity var(--rcv-transition);
    }
    .rcv-hint .bi { font-size: 14px; }
    .rcv-hint.is-faded { opacity: 0; }

    .rcv-menu {
        z-index: 1056;
        overflow: hidden;
        border-radius: var(--rcv-radius-lg) !important;
        box-shadow: var(--rcv-shadow-dropdown) !important;
    }
    .rcv-menu-scroll {
        max-height: 280px;
        overflow-y: auto;
        scroll-behavior: smooth;
    }
    .rcv-menu-label {
        font-size: .6875rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #94A3B8;
        padding: .5rem .75rem;
        border-bottom: 1px solid var(--bs-border-color, #E2E8F0);
    }

    /* Two-line option. The left border is always present but transparent, so
       highlighting one does not shift the text by 2px. */
    .rcv-option {
        border: 0;
        border-left: 2px solid transparent;
        padding: .5rem .75rem;
        cursor: pointer;
        transition: background-color 150ms ease, border-color 150ms ease;
    }
    .rcv-option:hover { background: var(--gx-bg, #F8FAFC); }
    .rcv-option.active {
        background: var(--gx-secondary-bg, #DBEAFE);
        border-left-color: var(--gx-secondary, #3B82F6);
    }
    .rcv-option-primary {
        font-weight: 500;
        color: var(--gx-primary, #0F172A);
        line-height: 1.35;
    }
    .rcv-option-meta {
        font-size: .75rem;
        color: var(--gx-text-muted, #64748B);
        line-height: 1.35;
    }

    /* Selected value as a removable chip, so the search row stays a search row
       instead of turning into a second panel. */
    .rcv-chip {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        background: var(--gx-secondary-bg, #DBEAFE);
        color: var(--gx-secondary-700, #1D4ED8);
        border-radius: var(--rcv-radius-md);
        padding: .3rem .4rem .3rem .7rem;
        font-weight: 500;
        font-size: .875rem;
        max-width: 100%;
    }
    .rcv-chip-x {
        border: 0;
        background: transparent;
        color: inherit;
        line-height: 1;
        padding: .15rem .3rem;
        border-radius: var(--rcv-radius-sm);
        opacity: .65;
        transition: opacity var(--rcv-transition), background-color var(--rcv-transition);
    }
    .rcv-chip-x:hover, .rcv-chip-x:focus-visible { opacity: 1; background: rgba(255,255,255,.6); }

    /* Summary bar: one row of figures describing the whole PO. */
    .rcv-summary {
        background: var(--rcv-surface);
        border: 1px solid var(--rcv-border);
        border-radius: var(--rcv-radius-lg);
    }
    .rcv-summary-cell { min-width: 0; }
    .rcv-summary-label {
        font-size: .6875rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: var(--rcv-text-3);
        margin-bottom: .15rem;
    }
    .rcv-summary-value {
        font-weight: 600;
        color: var(--rcv-text);
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    /* Already-received figures are reference data, not inputs — kept visually
       quieter than the qty fields Store actually types into. */
    .rcv-prior { color: var(--rcv-text-2); font-variant-numeric: tabular-nums; }
    .rcv-prior-none { color: var(--rcv-text-3); }

    /* --- Item picker modal ------------------------------------------------ */

    /* Stepper: numbered dots joined by a rule, current one filled. */
    .rcv-steps {
        display: flex;
        align-items: center;
        gap: .75rem;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .rcv-step {
        display: flex;
        align-items: center;
        gap: .5rem;
        color: var(--rcv-text-3);
        font-size: .875rem;
        transition: color var(--rcv-transition);
    }
    /* The connector belongs to the step that follows it. */
    .rcv-step + .rcv-step::before {
        content: '';
        width: 2.5rem;
        height: 1px;
        background: var(--rcv-border);
        margin-right: .25rem;
    }
    .rcv-step-dot {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: .8125rem;
        font-weight: 600;
        background: var(--rcv-surface);
        color: var(--rcv-text-3);
        border: 1px solid var(--rcv-border);
        transition: background-color var(--rcv-transition), color var(--rcv-transition), border-color var(--rcv-transition);
    }
    .rcv-step.is-current { color: var(--rcv-text); font-weight: 600; }
    .rcv-step.is-current .rcv-step-dot {
        background: var(--rcv-primary);
        border-color: var(--rcv-primary);
        color: #fff;
    }
    /* A finished step keeps its colour but gives the fill back to the current one. */
    .rcv-step.is-done { color: var(--rcv-text-2); }
    .rcv-step.is-done .rcv-step-dot {
        background: var(--gx-secondary-bg, #DBEAFE);
        border-color: var(--gx-secondary-border, #BFDBFE);
        color: var(--gx-secondary-700, #1D4ED8);
    }

    .rcv-pick {
        max-height: 52vh;
        border: 1px solid var(--rcv-border);
        border-radius: var(--rcv-radius-lg);
    }
    .rcv-pick thead th {
        background: var(--rcv-surface);
        font-size: .6875rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 600;
        color: var(--rcv-text-2);
        border-bottom: 1px solid var(--rcv-border);
        white-space: nowrap;
    }

    /* Whole row is the hit target, so the checkbox stops being the only way in. */
    .rcv-pick tbody tr.rcv-row {
        cursor: pointer;
        transition: background-color var(--rcv-transition);
    }
    .rcv-pick tbody tr.rcv-row:hover { background: var(--rcv-surface); }
    .rcv-pick tbody tr.rcv-row.is-checked { background: var(--gx-secondary-bg, #DBEAFE); }
    /* Already added: visible for reference, but not selectable again. */
    .rcv-pick tbody tr.is-added {
        cursor: default;
        background: transparent;
        color: var(--rcv-text-3);
    }
    .rcv-pick tbody tr.is-added:hover { background: transparent; }

    /* Deliberately not sticky — the thead already occupies top:0, and a second
       sticky layer underneath it overlaps rather than stacking. */
    .rcv-group-row td {
        background: var(--rcv-surface);
        font-weight: 600;
        font-size: .8125rem;
        color: var(--rcv-text-2);
        border-top: 1px solid var(--rcv-border);
    }

    .rcv-cell-primary { color: var(--rcv-text); line-height: 1.35; }
    .rcv-cell-sub { font-size: .75rem; color: var(--rcv-text-2); line-height: 1.35; }
    .rcv-pick tbody tr.is-added .rcv-cell-primary { color: var(--rcv-text-3); }

    /* Status column reads as a state, not a fraction. */
    .rcv-state { display: inline-flex; align-items: center; gap: .4rem; font-size: .75rem; }
    .rcv-state-bar {
        width: 46px;
        height: 4px;
        border-radius: 999px;
        background: var(--rcv-border);
        overflow: hidden;
        flex: none;
    }
    .rcv-state-fill { display: block; height: 100%; background: var(--gx-accent, #10B981); }

    .rcv-modal-footer {
        background: var(--rcv-surface);
        border-top: 1px solid var(--rcv-border);
        gap: .5rem;
    }
    .rcv-selcount {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        font-size: .8125rem;
        color: var(--rcv-text-2);
    }
    .rcv-selcount-badge {
        min-width: 1.5rem;
        padding: .1rem .4rem;
        border-radius: var(--rcv-radius-sm);
        background: var(--rcv-border);
        color: var(--rcv-text);
        font-weight: 600;
        text-align: center;
        transition: background-color var(--rcv-transition), color var(--rcv-transition);
    }
    .rcv-selcount.is-active .rcv-selcount-badge {
        background: var(--rcv-primary);
        color: #fff;
    }

    .rcv-history thead th {
        background: var(--gx-bg, #F8FAFC);
        font-size: .75rem;
        font-weight: 600;
        letter-spacing: .02em;
        color: var(--gx-text-muted, #64748B);
        border-bottom: 1px solid var(--bs-border-color, #E2E8F0);
    }
    .rcv-history tbody tr { transition: background-color 150ms ease; }
    .rcv-history tbody tr:hover { background: var(--gx-bg, #F8FAFC); }

    /* Ghost delete: quiet until hovered, so a table of many rows is not a wall
       of red outlines. */
    .rcv-ghost-danger {
        border: 0;
        color: var(--gx-text-muted, #64748B);
        background: transparent;
        border-radius: var(--gx-radius-sm, 10px);
        transition: background-color 150ms ease, color 150ms ease;
    }
    .rcv-ghost-danger:hover,
    .rcv-ghost-danger:focus-visible {
        background: var(--gx-danger-bg, #FEE2E2);
        color: var(--gx-danger-700, #B91C1C);
    }
    .rcv-ghost-danger:active { transform: scale(.95); }
</style>
@endsection

@section('content')
<div class="container-fluid" id="rcvReceiving">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'Buyer / Style Stock'],
        ['label' => 'Material Receiving'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Material Receiving</h3>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Closing Stock</a>
                <a href="{{ route('store.material.bulk-issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Bulk Issue</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <h5 class="mb-3">Record Receiving</h5>
            @if(! $hasBookingPos)
                <p class="text-muted small mb-0">No Booking POs available to receive against.</p>
            @else
            <form method="POST" action="{{ route('store.material.receivings.store') }}" id="rcvForm">
                @csrf
                <input type="hidden" name="booking_po_id" id="rcvPoId" value="{{ old('booking_po_id') }}">

                {{-- Step 1 — find the PO. Store may know the delivery by its PO
                     number, the vendor's PI number, or the invoice it arrived
                     against; all three resolve to the same booking record.

                     SAP Code was removed as a handle: it identifies a material,
                     not the paperwork a delivery arrives under, so it fanned out
                     to POs unrelated to the shipment. "Independent" is the way
                     in when none of the three match anything yet. --}}
                <div class="row g-3 align-items-end mb-3">
                    <div class="col-12 col-lg-3">
                        <label class="form-label fw-semibold" for="rcvFilterType">Search by</label>
                        <select class="form-select" id="rcvFilterType">
                            <option value="po_no" selected>PO Number</option>
                            <option value="pi_number">PI Number</option>
                            <option value="invoice_no">Invoice No</option>
                            {{-- A failed Independent save must come back to the
                                 Independent form, not drop the user into the PO
                                 search with their entry gone. --}}
                            <option value="independent" @selected($errors->hasAny(['style_name', 'material_name', 'qty']))>Independent</option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-9" id="rcvPoSearchCol">
                        <label class="form-label fw-semibold" id="rcvSearchLabel">PO Number</label>
                        {{-- Suggestions drop over the page rather than pushing it
                             down, and a search may match more than one PO (one SAP
                             code can appear under several), so the PO is always
                             confirmed by picking from the list. --}}
                        <div class="position-relative rcv-search" id="rcvSearchWrap">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                                <input type="text" class="form-control" id="rcvSearch" autocomplete="off"
                                       role="combobox" aria-expanded="false" aria-autocomplete="list"
                                       aria-controls="rcvResultsList"
                                       placeholder="Click or type to see available PO Numbers…">
                                {{-- Spinner and clear share the same slot inside the
                                     field; only one is ever shown at a time. --}}
                                <span class="rcv-search-status">
                                    <span class="spinner-border spinner-border-sm text-primary d-none" id="rcvSearchSpinner" role="status" aria-hidden="true"></span>
                                    <button type="button" class="btn-close d-none" id="rcvSearchClear" aria-label="Clear search"></button>
                                </span>
                            </div>
                            <div id="rcvResults" class="rcv-menu d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                                <div class="rcv-menu-label" id="rcvResultsHint"></div>
                                {{-- Only the options scroll; the label above stays put. --}}
                                <div class="list-group list-group-flush rcv-menu-scroll" id="rcvResultsList" role="listbox"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Once a PO is chosen the search row keeps its place and the
                     selection sits beside it as a chip; removing the chip goes
                     straight back to browsing. --}}
                <div id="rcvSelectedPo" class="d-none mb-3">
                    <div class="rcv-summary p-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <span class="rcv-chip">
                                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                <span id="rcvSelectedPoNo">—</span>
                                <button type="button" class="rcv-chip-x" id="rcvClearPo"
                                        title="Clear selection" aria-label="Clear selected PO">
                                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                                </button>
                            </span>
                            <button type="button" class="btn btn-primary" id="rcvPickBtn"
                                    data-bs-toggle="modal" data-bs-target="#rcvItemsModal">
                                <i class="bi bi-list-check me-1" aria-hidden="true"></i>Select Items
                            </button>
                        </div>

                        {{-- Figures describe the whole PO. Ordered/pending come
                             from the BOM's own ordered qty, so a line that never
                             carried one reads "—" rather than a wrong total. --}}
                        <div class="row g-3">
                            <div class="col-6 col-lg-3 rcv-summary-cell">
                                <div class="rcv-summary-label">Buyer</div>
                                <div class="rcv-summary-value" id="rcvSumBuyer">—</div>
                            </div>
                            <div class="col-6 col-lg-3 rcv-summary-cell">
                                <div class="rcv-summary-label">Supplier</div>
                                <div class="rcv-summary-value" id="rcvSumSupplier">—</div>
                            </div>
                            <div class="col-4 col-lg-2 rcv-summary-cell">
                                <div class="rcv-summary-label">Ordered Qty</div>
                                <div class="rcv-summary-value" id="rcvSumOrdered">—</div>
                            </div>
                            <div class="col-4 col-lg-2 rcv-summary-cell">
                                <div class="rcv-summary-label">Pending Qty</div>
                                <div class="rcv-summary-value" id="rcvSumPending">—</div>
                            </div>
                            <div class="col-4 col-lg-2 rcv-summary-cell">
                                <div class="rcv-summary-label">Status</div>
                                <div id="rcvSumStatus"><span class="badge bg-secondary-subtle text-secondary-emphasis">—</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                @if($errors->has('rows') || $errors->hasAny(['rows.*.qty', 'rows.*.receive_date', 'rows.*.excel_row_id']))
                    <div class="alert alert-danger py-2">
                        <div class="fw-semibold small mb-1">Please correct the following:</div>
                        <ul class="small mb-0 ps-3">
                            @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                {{-- One quiet line instead of a full-height placeholder card —
                     it says what to do without pushing the form off-screen. --}}
                <div id="rcvEmpty" class="rcv-hint d-flex align-items-center gap-2">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span>Click or type to see available <span id="rcvHintType">PO Number</span>s</span>
                </div>

                {{-- Shared header. These describe the delivery as a whole, or are
                     PO-level identity that is identical on every line, so they are
                     entered once instead of being repeated per row. Their values
                     are mirrored into hidden per-row inputs on submit, so the
                     server still receives (and validates) one complete row each. --}}
                <div id="rcvShared" class="d-none">
                    <div class="border rounded-3 bg-body-secondary p-3 mb-3">
                        <div class="small fw-semibold text-uppercase text-muted mb-2">
                            <i class="bi bi-magic me-1" aria-hidden="true"></i>PO Details <span class="text-muted">— read-only</span>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-lg-3">
                                <label class="form-label small text-muted mb-1">Supplier Name</label>
                                <input type="text" id="shSupplier" class="form-control-plaintext form-control-sm border rounded px-2 bg-light text-muted" value="—" readonly tabindex="-1">
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label small text-muted mb-1">Buyer Name</label>
                                <input type="text" id="shBuyer" class="form-control-plaintext form-control-sm border rounded px-2 bg-light text-muted" value="—" readonly tabindex="-1">
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label small text-muted mb-1">Booking Season</label>
                                <input type="text" id="shSeason" class="form-control-plaintext form-control-sm border rounded px-2 bg-light text-muted" value="—" readonly tabindex="-1">
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label small text-muted mb-1">PO Number</label>
                                <input type="text" id="shPoNo" class="form-control-plaintext form-control-sm border rounded px-2 bg-light text-muted" value="—" readonly tabindex="-1">
                            </div>
                            <div class="col-6 col-lg-2">
                                <label class="form-label small text-muted mb-1">Unit</label>
                                <input type="text" id="shUom" class="form-control-plaintext form-control-sm border rounded px-2 bg-light text-muted" value="—" readonly tabindex="-1">
                            </div>
                        </div>

                        <div class="small fw-semibold text-uppercase text-muted mb-2">
                            <i class="bi bi-truck me-1" aria-hidden="true"></i>Delivery Details <span class="text-muted">— applies to every item below</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold">Receive Date <span class="text-danger">*</span></label>
                                <input type="date" id="shReceiveDate" data-shared="receive_date" value="{{ now()->toDateString() }}" class="form-control" required>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold">Invoice No</label>
                                <input type="text" id="shInvoiceNo" data-shared="invoice_no" class="form-control" maxlength="100">
                            </div>
                            <div class="col-12 col-sm-6 col-lg-2">
                                <label class="form-label fw-semibold">Source</label>
                                <select id="shSourceType" data-shared="source_type" class="form-select">
                                    <option value="booking" selected>Booking-wise</option>
                                    <option value="internal_po">Internal PO-wise</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-2">
                                <label class="form-label fw-semibold">GRN No</label>
                                <input type="text" class="form-control bg-light text-muted" value="Auto-generated" readonly disabled>
                                <div class="form-text text-muted"><i class="bi bi-magic me-1" aria-hidden="true"></i>One GRN per item, on save</div>
                            </div>
                            {{-- The date the GRN itself is booked. Left blank it
                                 follows Receive Date, which is what the server
                                 stores when nothing is entered here. --}}
                            <div class="col-12 col-sm-6 col-lg-2">
                                <label class="form-label fw-semibold" for="shGrnDate">GRN Date</label>
                                <input type="date" id="shGrnDate" data-shared="grn_date" value="{{ now()->toDateString() }}" class="form-control">
                                <div class="form-text text-muted">Defaults to Receive Date</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Remarks</label>
                                <textarea id="shRemarks" data-shared="remarks" rows="2" class="form-control" maxlength="1000"></textarea>
                            </div>
                        </div>
                    </div>

                    {{-- Only the columns that genuinely differ per material line. --}}
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="rcvTable">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Style</th><th>Material Name</th><th>Description</th>
                                    <th>GMTS Color</th><th>Art. No</th><th>SAP Code</th>
                                    <th>Mat. Color</th><th>Size</th>
                                    <th class="text-end">Internal PO Qty</th>
                                    {{-- What earlier GRNs already booked against this
                                         line — reference only, never submitted. --}}
                                    <th class="text-end">Already Rcvd</th>
                                    <th style="min-width:110px;">Invoice Qty</th>
                                    <th style="min-width:130px;">Physical Rcv Qty <span class="text-danger">*</span></th>
                                    <th style="min-width:110px;">Unit Price</th>
                                    <th class="text-end">Invoice Value</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="rcvRows"></tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                        <div class="small">
                            <span class="text-muted"><span id="rcvCount">0</span> item(s) — one GRN will be generated per item.</span>
                            {{-- Preview only; the server recomputes every value on
                                 save, so this never decides what is stored. --}}
                            <span class="ms-2">Total Invoice Value:
                                <span class="fw-semibold" id="rcvTotalValue">—</span>
                            </span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rcvItemsModal">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add More Items
                            </button>
                            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Receivings</button>
                        </div>
                    </div>
                </div>
            </form>

            {{-- Independent entry — material that physically arrived but whose
                 paperwork matches no PO, PI or Invoice yet.

                 A separate form rather than a mode of the one above: that one is
                 built entirely around a Booking PO (auto-filled identity, the
                 item picker, per-line BOM rows), none of which exists here. The
                 fields Store fills are the same ones, just typed instead of
                 resolved. --}}
            <div id="rcvIndependent" class="d-none">
                <form method="POST" action="{{ route('store.material.receivings.independent') }}" id="rcvIndForm">
                    @csrf
                    <input type="hidden" name="buyer_name" id="rcvIndBuyer" value="{{ old('buyer_name') }}">
                    <input type="hidden" name="season_name" id="rcvIndSeason" value="{{ old('season_name') }}">
                    <input type="hidden" name="style_name" id="rcvIndStyle" value="{{ old('style_name') }}">

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="rcvIndSearch">Style <span class="text-danger">*</span></label>
                        <div class="position-relative rcv-search" id="rcvIndSearchWrap">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                                <input type="text" class="form-control" id="rcvIndSearch" autocomplete="off"
                                       role="combobox" aria-expanded="false" aria-autocomplete="list"
                                       aria-controls="rcvIndResultsList"
                                       placeholder="Click or type to see available styles…">
                            </div>
                            <div id="rcvIndResults" class="rcv-menu d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                                <div class="rcv-menu-label" id="rcvIndResultsHint"></div>
                                <div class="list-group list-group-flush rcv-menu-scroll" id="rcvIndResultsList" role="listbox"></div>
                            </div>
                        </div>
                        <div class="form-text">Pick the style this material belongs to. The PO can be attached later.</div>
                    </div>

                    {{-- Everything below stays out of reach until a style is
                         chosen, so the form cannot be filled against nothing. --}}
                    <div id="rcvIndBody" class="d-none">
                        <div class="rcv-summary p-3 mb-3">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <span class="rcv-chip">
                                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                                    <span id="rcvIndChip">—</span>
                                    <button type="button" class="rcv-chip-x" id="rcvIndClear" title="Choose a different style" aria-label="Choose a different style">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                                    </button>
                                </span>
                                <span class="badge bg-warning-subtle text-warning-emphasis">
                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Not linked to a PO
                                </span>
                            </div>
                        </div>

                        <div class="alert alert-warning py-2 px-3 small" role="alert">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            This entry is recorded for the record only. It does not change closing stock until it is linked to a PO from Receiving History.
                        </div>

                        <div class="small fw-semibold text-uppercase text-muted mb-2">
                            <i class="bi bi-box-seam me-1" aria-hidden="true"></i>Material
                        </div>
                        {{-- Each field stays an ordinary text input — same name,
                             same value, nothing about the submission changes. A
                             suggestion list is attached on top, offering what the
                             chosen style already carries on its BOM so Store picks
                             a known material name instead of retyping it from
                             memory. Typing something new is always allowed: this
                             flow exists precisely for what the system does not
                             recognise yet. --}}
                        <div class="row g-3 mb-3" id="rcvIndMaterialGrid">
                            @php
                                $bomFields = [
                                    ['key' => 'material_name', 'name' => 'material_name', 'id' => 'rcvIndMaterialName', 'label' => 'Material Name', 'required' => true, 'max' => 255, 'col' => 'col-12 col-lg-4'],
                                    ['key' => 'material_description', 'name' => 'material_description', 'id' => 'rcvIndDescription', 'label' => 'Description', 'required' => false, 'max' => 1000, 'col' => 'col-12 col-lg-4'],
                                    ['key' => 'supplier_name', 'name' => 'supplier_name', 'id' => 'rcvIndSupplier', 'label' => 'Supplier', 'required' => false, 'max' => 255, 'col' => 'col-12 col-lg-4'],
                                    ['key' => 'material_color', 'name' => 'material_color', 'id' => 'rcvIndColor', 'label' => 'Material Colour', 'required' => false, 'max' => 255, 'col' => 'col-6 col-lg-4'],
                                    ['key' => 'size', 'name' => 'size', 'id' => 'rcvIndSize', 'label' => 'Size', 'required' => false, 'max' => 255, 'col' => 'col-6 col-lg-4'],
                                    ['key' => 'uom', 'name' => 'uom', 'id' => 'rcvIndUom', 'label' => 'Unit', 'required' => false, 'max' => 50, 'col' => 'col-6 col-lg-4'],
                                ];
                            @endphp

                            @foreach($bomFields as $field)
                                <div class="{{ $field['col'] }}">
                                    <label class="form-label fw-semibold" for="{{ $field['id'] }}">
                                        {{ $field['label'] }}
                                        @if($field['required'])<span class="text-danger">*</span>@endif
                                    </label>
                                    {{-- Plain position-relative, not .rcv-search:
                                         that class reserves right padding for a
                                         spinner/clear slot these fields have no
                                         use for. --}}
                                    <div class="position-relative" data-bom-field="{{ $field['key'] }}">
                                        <input type="text" name="{{ $field['name'] }}" id="{{ $field['id'] }}"
                                               class="form-control" maxlength="{{ $field['max'] }}" autocomplete="off"
                                               role="combobox" aria-expanded="false" aria-autocomplete="list"
                                               @if($field['required']) required @endif
                                               value="{{ old($field['name']) }}">
                                        <div class="rcv-menu d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow" data-bom-panel>
                                            <div class="rcv-menu-label" data-bom-hint></div>
                                            <div class="list-group list-group-flush rcv-menu-scroll" data-bom-list role="listbox"></div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Says which of the two states the fields are in, so a
                             style with no BOM data does not look broken. --}}
                        <p class="form-text mt-0 mb-3" id="rcvIndBomNote"></p>

                        <div class="small fw-semibold text-uppercase text-muted mb-2">
                            <i class="bi bi-truck me-1" aria-hidden="true"></i>Delivery Details
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndReceiveDate">Receive Date <span class="text-danger">*</span></label>
                                <input type="date" name="receive_date" id="rcvIndReceiveDate" value="{{ now()->toDateString() }}" class="form-control" required>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndGrnDate">GRN Date</label>
                                <input type="date" name="grn_date" id="rcvIndGrnDate" value="{{ now()->toDateString() }}" class="form-control">
                                <div class="form-text text-muted">Defaults to Receive Date</div>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndInvoiceNo">Invoice No</label>
                                <input type="text" name="invoice_no" id="rcvIndInvoiceNo" class="form-control" maxlength="100" value="{{ old('invoice_no') }}">
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndSourceType">Source</label>
                                <select name="source_type" id="rcvIndSourceType" class="form-select">
                                    <option value="booking" selected>Booking-wise</option>
                                    <option value="internal_po">Internal PO-wise</option>
                                </select>
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndInvoiceQty">Invoice Qty</label>
                                <input type="number" step="0.0001" min="0" name="invoice_qty" id="rcvIndInvoiceQty" class="form-control" value="{{ old('invoice_qty') }}">
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndQty">Physical Rcv Qty <span class="text-danger">*</span></label>
                                <input type="number" step="0.0001" min="0.0001" name="qty" id="rcvIndQty" class="form-control" required value="{{ old('qty') }}">
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label fw-semibold" for="rcvIndUnitPrice">Unit Price</label>
                                <input type="number" step="0.0001" min="0" name="unit_price" id="rcvIndUnitPrice" class="form-control" value="{{ old('unit_price') }}">
                            </div>
                            <div class="col-6 col-lg-3">
                                <label class="form-label fw-semibold">GRN No</label>
                                <input type="text" class="form-control bg-light text-muted" value="Auto-generated" readonly disabled>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="rcvIndRemarks">Remarks</label>
                                <textarea name="remarks" id="rcvIndRemarks" rows="2" class="form-control" maxlength="1000"></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Independent Receiving
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <h5 class="mb-3">Receiving History <span class="badge bg-primary-subtle text-primary ms-1">{{ $receivings->total() }}</span></h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0 rcv-history">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th>Date</th><th>GRN No</th><th>PO / Material</th><th>Source</th><th class="text-end">Inv Qty</th><th class="text-end">Rcv Qty</th><th class="text-end">Price</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($receivings as $r)
                            <tr>
                                <td class="small">{{ optional($r->receive_date)->format('d-M-Y') ?? '—' }}</td>
                                <td class="small">
                                    <span class="fw-semibold text-nowrap d-block">{{ $r->grn_no ?: '—' }}</span>
                                    {{-- Only worth showing when it differs from the
                                         Date column already on the left. --}}
                                    @if($r->grn_date && optional($r->receive_date)->toDateString() !== $r->grn_date->toDateString())
                                        <span class="text-muted d-block">GRN dt: {{ $r->grn_date->format('d-M-Y') }}</span>
                                    @endif
                                    @if($r->invoice_no)<span class="text-muted">Inv: {{ $r->invoice_no }}</span>@endif
                                </td>
                                <td>
                                    <div class="fw-semibold">
                                        @if($r->isIndependent())
                                            {{-- No PO number to show yet, so the badge
                                                 takes its place rather than leaving a
                                                 bare separator. --}}
                                            <span class="badge bg-warning-subtle text-warning-emphasis me-1">Independent</span>
                                        @else
                                            {{ $r->po_no }} ·
                                        @endif
                                        {{ $r->material_name ?: $r->material_description }}
                                    </div>
                                    <div class="small text-muted">{{ collect([$r->buyer_name.' / '.$r->style_name, $r->material_color, $r->size])->filter()->implode(' · ') }}</div>
                                    @if($r->isIndependent())
                                        <div class="small text-warning-emphasis">Not counted in closing stock until linked to a PO.</div>
                                    @endif
                                </td>
                                {{-- Booking vs Internal PO read as two different
                                     routes into stock, so they get two tones
                                     rather than one shared badge colour. --}}
                                <td>
                                    @if($r->source_type == 'internal_po')
                                        <span class="badge bg-success-subtle text-success-emphasis">Internal PO</span>
                                    @else
                                        <span class="badge bg-primary-subtle text-primary-emphasis">Booking</span>
                                    @endif
                                </td>
                                <td class="text-end small">{{ $r->invoice_qty !== null ? rtrim(rtrim(number_format((float)$r->invoice_qty, 4), '0'), '.') : '—' }}</td>
                                <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$r->qty, 4), '0'), '.') }}</td>
                                <td class="text-end small">
                                    {{ $r->unit_price !== null ? number_format((float)$r->unit_price, 4) : '—' }}
                                    @if($r->invoice_value !== null)<div class="text-muted">Val: {{ number_format((float)$r->invoice_value, 2) }}</div>@endif
                                </td>
                                <td class="text-end">
                                    {{-- Corrections are an Admin / Management right
                                         (store.edit / store.delete). The buttons are
                                         absent for everyone else rather than disabled,
                                         and the controller re-checks server-side. --}}
                                    <div class="d-flex justify-content-end align-items-center gap-2">
                                        @if($canEdit && $r->isIndependent())
                                            {{-- The deliberate, human-confirmed re-match.
                                                 There is no automatic OCR matcher in this
                                                 app, and guessing the BOM line would book
                                                 stock onto the wrong row. --}}
                                            <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                                                    data-rcv-link="{{ $r->id }}"
                                                    data-rcv-grn="{{ $r->grn_no }}"
                                                    data-rcv-style="{{ $r->buyer_name }} / {{ $r->style_name }}">
                                                <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Link to PO
                                            </button>
                                        @endif
                                        @if($canDelete)
                                            <form method="POST" action="{{ route('store.material.receivings.destroy', $r) }}" onsubmit="return confirm('Remove this receiving? Closing stock will update.');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                            </form>
                                        @endif
                                        @if(! $canEdit && ! $canDelete)
                                            <span class="text-muted small">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-5">No receiving recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $receivings->links() }}</div>
        </div>
    </div>
</div>

{{-- Link an Independent receiving to the PO it turned out to be for. Reuses the
     same po-search and po-items endpoints the normal flow uses, so there is one
     matching path in the application rather than two. --}}
<div class="modal fade" id="rcvLinkModal" tabindex="-1" aria-labelledby="rcvLinkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--gx-radius);">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="rcvLinkModalLabel">Link Receiving to PO</h5>
                    <div class="small text-muted" id="rcvLinkSubtitle">—</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" id="rcvLinkForm">
                @csrf
                <input type="hidden" name="booking_po_id" id="rcvLinkPoId">
                <input type="hidden" name="excel_row_id" id="rcvLinkRowId">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="rcvLinkSearch">Find the PO</label>
                        <div class="position-relative rcv-search" id="rcvLinkSearchWrap">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                                <input type="text" class="form-control" id="rcvLinkSearch" autocomplete="off"
                                       role="combobox" aria-expanded="false" aria-autocomplete="list"
                                       aria-controls="rcvLinkResultsList"
                                       placeholder="Click or type to see available PO Numbers…">
                            </div>
                            <div id="rcvLinkResults" class="rcv-menu d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                                <div class="rcv-menu-label" id="rcvLinkResultsHint"></div>
                                <div class="list-group list-group-flush rcv-menu-scroll" id="rcvLinkResultsList" role="listbox"></div>
                            </div>
                        </div>
                    </div>

                    {{-- The PO alone is not enough: stock is booked against one
                         BOM line, so the material line has to be chosen too. --}}
                    <div id="rcvLinkItemsWrap" class="d-none">
                        <label class="form-label fw-semibold">Material line <span class="text-danger">*</span></label>
                        <div class="table-responsive border rounded">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr class="text-muted small text-uppercase">
                                        <th style="width:42px;"></th>
                                        <th>Material</th><th>Style</th><th>Colour / Size</th>
                                        <th class="text-end">Available</th>
                                    </tr>
                                </thead>
                                <tbody id="rcvLinkItemBody"></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="rcvLinkLoading" class="d-none text-center text-muted py-3">
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading items…
                    </div>
                    <div id="rcvLinkError" class="alert alert-warning py-2 px-3 small d-none mb-0" role="alert"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="rcvLinkSubmit" disabled>
                        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Confirm Link
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Two-level item picker: Style first, then the item(s) under each style. A
     style can carry one item or several, which a flat list made hard to read. --}}
<div class="modal fade" id="rcvItemsModal" tabindex="-1" aria-labelledby="rcvItemsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--gx-radius);">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="rcvItemsModalLabel">Select Items</h5>
                    <div class="small text-muted" id="rcvModalPo">—</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                {{-- Visual stepper. The numbers carry the state, so the label
                     underneath never has to say "you are here". --}}
                <ol class="rcv-steps mb-4" id="rcvSteps">
                    <li class="rcv-step is-current" id="rcvCrumb1">
                        <span class="rcv-step-dot">1</span>
                        <span class="rcv-step-text">Choose Style</span>
                    </li>
                    <li class="rcv-step" id="rcvCrumb2">
                        <span class="rcv-step-dot">2</span>
                        <span class="rcv-step-text">Choose Items</span>
                    </li>
                </ol>

                <div id="rcvModalLoading" class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
                </div>
                <div id="rcvModalError" class="alert alert-warning d-none mb-0"></div>

                {{-- Level 1: styles --}}
                <div id="rcvStep1" class="d-none">
                    <div class="rcv-pick table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:42px;">
                                        <input type="checkbox" class="form-check-input" id="rcvStyleAll"
                                               title="Select all styles" aria-label="Select all styles">
                                    </th>
                                    <th>Style Number</th>
                                    <th style="width:120px;" class="text-end">Items</th>
                                    <th style="width:190px;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="rcvStyleBody"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Level 2: items within the chosen styles. The identity columns
                     are paired into two-line cells — the same nine values, but
                     six columns instead of ten so nothing is squeezed. --}}
                <div id="rcvStep2" class="d-none">
                    <div class="rcv-pick table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="sticky-top">
                                <tr>
                                    <th style="width:42px;">
                                        <input type="checkbox" class="form-check-input" id="rcvItemAll"
                                               title="Select all available items" aria-label="Select all available items">
                                    </th>
                                    <th>Material</th>
                                    <th>Art. No / SAP Code</th>
                                    <th>Colour / Size</th>
                                    <th style="width:70px;">Unit</th>
                                    <th style="width:110px;" class="text-end">Internal PO Qty</th>
                                </tr>
                            </thead>
                            <tbody id="rcvItemBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- The count sits in the bar as a live badge rather than loose text,
                 so the footer reads as part of the picker instead of a separate
                 strip of buttons. --}}
            <div class="modal-footer rcv-modal-footer">
                <span class="rcv-selcount me-auto" id="rcvSelCountWrap">
                    <span class="rcv-selcount-badge" id="rcvSelCount">0</span>
                    <span id="rcvSelCountLabel">selected</span>
                </span>
                <button type="button" class="btn btn-outline-secondary d-none" id="rcvBackBtn"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="rcvNextBtn">Next: Choose Items<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-primary d-none" id="rcvAddSelected"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Selected</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const form = document.getElementById('rcvForm');
        if (!form) return;

        const poIdEl = document.getElementById('rcvPoId');
        const filterType = document.getElementById('rcvFilterType');
        const searchEl = document.getElementById('rcvSearch');
        const searchLabel = document.getElementById('rcvSearchLabel');
        const resultsWrap = document.getElementById('rcvResults');
        const resultsList = document.getElementById('rcvResultsList');
        const resultsHint = document.getElementById('rcvResultsHint');
        const searchSpinner = document.getElementById('rcvSearchSpinner');
        const searchClear = document.getElementById('rcvSearchClear');
        const selectedWrap = document.getElementById('rcvSelectedPo');
        const sharedWrap = document.getElementById('rcvShared');
        const emptyBox = document.getElementById('rcvEmpty');
        const rowsWrap = document.getElementById('rcvRows');
        const countEl = document.getElementById('rcvCount');

        const modalEl = document.getElementById('rcvItemsModal');
        const step1 = document.getElementById('rcvStep1');
        const step2 = document.getElementById('rcvStep2');
        const styleBody = document.getElementById('rcvStyleBody');
        const itemBody = document.getElementById('rcvItemBody');
        const styleAll = document.getElementById('rcvStyleAll');
        const itemAll = document.getElementById('rcvItemAll');
        const backBtn = document.getElementById('rcvBackBtn');
        const nextBtn = document.getElementById('rcvNextBtn');
        const addBtn = document.getElementById('rcvAddSelected');
        const selCount = document.getElementById('rcvSelCount');
        const modalLoading = document.getElementById('rcvModalLoading');
        const modalError = document.getElementById('rcvModalError');
        const modalPo = document.getElementById('rcvModalPo');
        const crumb1 = document.getElementById('rcvCrumb1');
        const crumb2 = document.getElementById('rcvCrumb2');

        const SEARCH_URL = @json(route('store.material.receivings.po-search'));
        const ITEMS_URL = @json(route('store.material.receivings.po-items', ['bookingPo' => '__ID__']));
        const TODAY = @json(now()->toDateString());

        const LABELS = { po_no: 'PO Number', pi_number: 'PI Number', invoice_no: 'Invoice No' };
        const STYLE_SEARCH_URL = @json(route('store.material.receivings.style-search'));
        const STYLE_BOM_URL = @json(route('store.material.receivings.style-bom'));
        const LINK_URL = @json(route('store.material.receivings.link', ['materialReceiving' => '__ID__']));

        // esc() normalises a value for logic; h() escapes it for HTML/attribute
        // interpolation. BOM values come from uploaded workbooks, so anything
        // built into markup below must go through h()/dash().
        const esc = (v) => (v === null || v === undefined || v === '') ? '' : String(v);
        const h = (v) => esc(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const dash = (v) => esc(v) === '' ? '—' : h(v);

        // Quantities are stored to 4dp but rarely use them; drop trailing zeros
        // so "104.0000" reads as "104".
        const trimNum = (n) => {
            if (!isFinite(n)) return '—';
            return String(parseFloat(Number(n).toFixed(4)));
        };

        let items = [];         // every line under the loaded PO
        let loadedPoId = null;
        let uid = 0;

        const addedRowIds = () => Array.from(rowsWrap.querySelectorAll('[data-row-id]'))
            .map(el => String(el.dataset.rowId));

        // --- PO search --------------------------------------------------------
        // "an Invoice No" vs "a PO Number" — the article follows the label so the
        // placeholder reads correctly for every filter type.
        const article = (label) => (/^[AEIOU]/i.test(label) ? 'an ' : 'a ');

        const hintType = document.getElementById('rcvHintType');

        // "Independent" is not another way of finding a PO — it is the path for a
        // delivery that has none. So it swaps the whole PO form out for the
        // independent one rather than just relabelling the search box.
        const independentWrap = document.getElementById('rcvIndependent');
        const poSearchCol = document.getElementById('rcvPoSearchCol');
        const poFormEl = document.getElementById('rcvForm');

        function applyMode() {
            const independent = filterType.value === 'independent';

            independentWrap.classList.toggle('d-none', !independent);
            poSearchCol.classList.toggle('d-none', independent);
            selectedWrap.classList.toggle('d-none', independent || !poIdEl.value);
            emptyBox.classList.toggle('d-none', independent);
            sharedWrap.classList.toggle('d-none', independent || !rowsWrap.children.length);

            // A hidden required input blocks submit with an error the user cannot
            // see, so the PO form's own requirements stand down while it is away.
            poFormEl.querySelectorAll('[required]').forEach((el) => {
                if (independent) {
                    el.dataset.rcvRequired = '1';
                    el.required = false;
                } else if (el.dataset.rcvRequired) {
                    el.required = true;
                }
            });

            if (independent) closeSuggest();
        }

        filterType.addEventListener('change', function () {
            if (filterType.value === 'independent') {
                applyMode();
                return;
            }

            const label = LABELS[filterType.value];
            searchLabel.textContent = label;
            searchEl.placeholder = 'Click or type to see available ' + label + 's…';
            hintType.textContent = label;
            searchEl.value = '';
            closeSuggest();
            syncSearchStatus();
            applyMode();
        });

        const DEBOUNCE_MS = 300;
        const BLUR_CLOSE_MS = 200;
        let searchTimer = null;
        let searchTicket = 0;
        let activeIndex = -1;

        // The hint duplicates what the open menu is already showing, so it fades
        // out while the menu is up and returns only if nothing was selected.
        function closeSuggest() {
            resultsWrap.classList.add('d-none');
            searchEl.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
            emptyBox.classList.remove('is-faded');
        }

        function openSuggest() {
            resultsWrap.classList.remove('d-none');
            searchEl.setAttribute('aria-expanded', 'true');
            emptyBox.classList.add('is-faded');
        }

        // The spinner replaces the clear button while a lookup is in flight, so
        // the two never occupy the slot at the same time.
        let searching = false;

        function syncSearchStatus() {
            const hasText = searchEl.value !== '';
            searchSpinner.classList.toggle('d-none', !searching);
            searchClear.classList.toggle('d-none', searching || !hasText);
        }

        // Browse list per filter type, fetched once and kept for the page's life.
        // `complete` means the server sent the whole dataset, so typing can be
        // filtered here instead of going back for every keystroke.
        const browseCache = {};
        // Focus and click both open the menu, so the in-flight promise is shared
        // rather than the request being made twice before the cache fills.
        const browseInFlight = {};

        function loadBrowse(type) {
            if (browseCache[type]) return Promise.resolve(browseCache[type]);
            if (browseInFlight[type]) return browseInFlight[type];

            const url = SEARCH_URL + '?type=' + encodeURIComponent(type);

            browseInFlight[type] = fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    browseCache[type] = {
                        results: data.results || [],
                        complete: !!data.complete,
                    };
                    return browseCache[type];
                })
                .finally(() => { delete browseInFlight[type]; });

            return browseInFlight[type];
        }

        // Opening the field shows what exists — no typing required.
        function showBrowse() {
            const type = filterType.value;
            const ticket = ++searchTicket;
            const cached = browseCache[type];

            openSuggest();

            if (!cached) {
                searching = true;
                syncSearchStatus();
                resultsHint.textContent = 'Loading…';
                resultsList.innerHTML = '';
            }

            return loadBrowse(type)
                .then(data => {
                    if (ticket !== searchTicket) return;   // superseded meanwhile
                    searching = false;
                    syncSearchStatus();
                    renderResults(filterLocally(data.results, searchEl.value.trim()), type, searchEl.value.trim(), data);
                })
                .catch(() => {
                    if (ticket !== searchTicket) return;
                    searching = false;
                    syncSearchStatus();
                    showListError();
                });
        }

        // Substring match across the value and its PO/buyer meta, so typing a
        // buyer name narrows the list too.
        function filterLocally(results, term) {
            const needle = term.toLowerCase();
            if (needle === '') return results;

            return results.filter(r =>
                [r.value, r.po_no, r.buyer_name, r.season_name, r.vendor_name]
                    .some(field => esc(field).toLowerCase().includes(needle))
            );
        }

        function showListError() {
            resultsHint.textContent = '';
            resultsList.innerHTML =
                '<div class="list-group-item text-muted">Could not load the list. Please try again.</div>';
        }

        searchEl.addEventListener('focus', showBrowse);
        searchEl.addEventListener('click', showBrowse);

        searchEl.addEventListener('input', function () {
            clearTimeout(searchTimer);
            const term = searchEl.value.trim();
            const type = filterType.value;
            const cached = browseCache[type];

            syncSearchStatus();

            // Whole dataset already in hand — filter it here, no request, no
            // debounce, no minimum length.
            if (cached && cached.complete) {
                searchTicket++;
                searching = false;
                syncSearchStatus();
                openSuggest();
                renderResults(filterLocally(cached.results, term), type, term, cached);
                return;
            }

            // Dataset was capped, so the server has to do the narrowing. An empty
            // box falls back to the browse list.
            if (term === '') {
                showBrowse();
                return;
            }

            searchTimer = setTimeout(runSearch, DEBOUNCE_MS);
        });

        searchClear.addEventListener('click', function () {
            clearTimeout(searchTimer);
            searchTicket++;
            searching = false;
            searchEl.value = '';
            syncSearchStatus();
            showBrowse();
            searchEl.focus();
        });

        // Closing is delayed so a click landing on an option still registers as
        // a selection rather than being cancelled by the blur.
        let blurTimer = null;

        searchEl.addEventListener('blur', function () {
            clearTimeout(blurTimer);
            blurTimer = setTimeout(closeSuggest, BLUR_CLOSE_MS);
        });

        // Keep the menu open when the pointer is heading for an option.
        resultsWrap.addEventListener('mousedown', function (e) {
            e.preventDefault();          // never steal focus from the input
            clearTimeout(blurTimer);
        });

        function runSearch() {
            const type = filterType.value;
            const term = searchEl.value.trim();
            const ticket = ++searchTicket;

            searching = true;
            syncSearchStatus();
            openSuggest();
            resultsHint.textContent = 'Searching…';
            resultsList.innerHTML = '';

            const url = SEARCH_URL + '?type=' + encodeURIComponent(type) + '&term=' + encodeURIComponent(term);

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    // Ignore a slow reply that a newer keystroke has superseded.
                    if (ticket !== searchTicket) return;
                    searching = false;
                    syncSearchStatus();
                    renderResults(data.results || [], type, term, data);
                })
                .catch(() => {
                    if (ticket !== searchTicket) return;
                    searching = false;
                    syncSearchStatus();
                    showListError();
                });
        }

        function renderResults(results, type, term, source) {
            activeIndex = -1;

            if (!results.length) {
                resultsHint.textContent = '';
                resultsList.innerHTML = '<div class="list-group-item text-center text-muted py-3">' +
                    '<i class="bi bi-inbox d-block mb-1" style="font-size:18px;opacity:.5;" aria-hidden="true"></i>' +
                    '<div class="small">No matching records' +
                        (term ? ' for “' + h(term) + '”' : '') + '</div>' +
                '</div>';
                return;
            }

            // Browsing the untouched list is labelled by what it is; anything
            // narrowed is labelled by how much is left.
            const browsing = term === '' && source && source.complete;
            resultsHint.textContent = browsing
                ? 'All ' + LABELS[type] + 's (' + results.length + ')'
                : results.length + (results.length === 1 ? ' match' : ' matches');

            resultsList.innerHTML = results.map(r => {
                // The PO number is already the primary line when browsing by PO,
                // so it is not repeated in the meta.
                const meta = [
                    type === 'po_no' ? null : r.po_no,
                    r.buyer_name,
                    r.vendor_name,
                ].filter(Boolean).join(' · ');

                return '<div class="list-group-item rcv-option" role="option" tabindex="-1"' +
                    ' data-id="' + h(r.id) + '" data-po="' + h(r.po_no) + '"' +
                    ' data-meta="' + h([r.buyer_name, r.season_name, r.vendor_name].filter(Boolean).join(' · ')) + '">' +
                    '<div class="rcv-option-primary">' + dash(r.value || r.po_no) + '</div>' +
                    '<div class="rcv-option-meta">' + dash(meta) + '</div>' +
                '</div>';
            }).join('');
        }

        // --- Keyboard navigation ---------------------------------------------
        const options = () => Array.from(resultsList.querySelectorAll('.rcv-option'));

        function highlight(index) {
            const list = options();
            if (!list.length) return;

            activeIndex = (index + list.length) % list.length;
            list.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
            list[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        searchEl.addEventListener('keydown', function (e) {
            const open = !resultsWrap.classList.contains('d-none');

            if (e.key === 'Escape') { closeSuggest(); return; }

            if (e.key === 'ArrowDown' && open) { e.preventDefault(); highlight(activeIndex + 1); return; }
            if (e.key === 'ArrowUp' && open) { e.preventDefault(); highlight(activeIndex - 1); return; }

            if (e.key === 'Enter') {
                // Never submit the form from the search box.
                e.preventDefault();
                const list = options();
                if (!open || !list.length) return;
                selectPo(activeIndex >= 0 ? list[activeIndex] : list[0]);
            }
        });

        // Clicking anywhere else dismisses the suggestions.
        document.addEventListener('click', function (e) {
            if (!document.getElementById('rcvSearchWrap').contains(e.target)) closeSuggest();
        });

        resultsList.addEventListener('click', function (e) {
            const option = e.target.closest('.rcv-option');
            if (option) selectPo(option);
        });

        function selectPo(btn) {
            const newId = btn.dataset.id;

            if (rowsWrap.children.length && String(newId) !== String(poIdEl.value)) {
                if (!confirm('Changing the PO will clear the items already added. Continue?')) return;
                rowsWrap.innerHTML = '';
            }

            poIdEl.value = newId;
            document.getElementById('rcvSelectedPoNo').textContent = btn.dataset.po || '—';
            selectedWrap.classList.remove('d-none');
            searchEl.value = '';
            syncSearchStatus();
            closeSuggest();
            loadSummary(newId);
            refreshState();
        }

        // Removing the chip clears the selection and reopens the browse list,
        // rather than leaving an empty box to type into again.
        document.getElementById('rcvClearPo').addEventListener('click', function () {
            if (rowsWrap.children.length &&
                !confirm('Clearing the PO will remove the items already added. Continue?')) return;

            rowsWrap.innerHTML = '';
            poIdEl.value = '';
            selectedWrap.classList.add('d-none');
            items = [];
            loadedPoId = null;
            searchEl.value = '';
            syncSearchStatus();
            refreshState();
            searchEl.focus();
            showBrowse();
        });

        // --- Summary bar ------------------------------------------------------
        // Reuses the item feed the picker already loads, so opening a PO costs
        // one request whether or not the modal is opened afterwards.
        function loadSummary(poId) {
            setSummary(null);

            fetch(ITEMS_URL.replace('__ID__', encodeURIComponent(poId)), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    if (String(poIdEl.value) !== String(poId)) return;   // changed meanwhile
                    items = data.items || [];
                    loadedPoId = poId;
                    setSummary(items);
                })
                .catch(() => {
                    if (String(poIdEl.value) === String(poId)) setSummary(null);
                });
        }

        function setSummary(list) {
            const buyer = document.getElementById('rcvSumBuyer');
            const supplier = document.getElementById('rcvSumSupplier');
            const ordered = document.getElementById('rcvSumOrdered');
            const pending = document.getElementById('rcvSumPending');
            const status = document.getElementById('rcvSumStatus');

            if (!list || !list.length) {
                [buyer, supplier, ordered, pending].forEach(el => { el.textContent = '—'; });
                status.innerHTML = '<span class="badge bg-secondary-subtle text-secondary-emphasis">—</span>';
                return;
            }

            buyer.textContent = esc(list[0].buyer_name) || '—';
            supplier.textContent = esc(list[0].supplier_name) || '—';

            // Only lines that actually carry an ordered qty are totalled. If none
            // do, the figures stay "—" instead of implying a zero-qty order.
            const known = list.filter(it => !isNaN(parseFloat(it.internal_po_qty)));
            const totalOrdered = known.reduce((sum, it) => sum + parseFloat(it.internal_po_qty), 0);
            const totalReceived = list.reduce((sum, it) => sum + (parseFloat(it.received_qty) || 0), 0);

            if (!known.length) {
                ordered.textContent = '—';
                pending.textContent = '—';
                status.innerHTML = '<span class="badge bg-secondary-subtle text-secondary-emphasis">Qty not set</span>';
                return;
            }

            const outstanding = totalOrdered - totalReceived;

            // Some BOM lines carry no ordered qty at all. The total is still the
            // best available figure, but it is marked so nobody reads a partial
            // sum as the full order.
            const partial = known.length < list.length;
            ordered.textContent = trimNum(totalOrdered) + (partial ? ' *' : '');
            ordered.title = partial
                ? (list.length - known.length) + ' of ' + list.length +
                  ' item(s) have no ordered qty in the BOM, so this total covers only the rest.'
                : '';
            pending.textContent = trimNum(Math.max(outstanding, 0));

            status.innerHTML = totalReceived <= 0
                ? '<span class="badge bg-secondary-subtle text-secondary-emphasis">Not received</span>'
                : (outstanding > 0
                    ? '<span class="badge bg-warning-subtle text-warning-emphasis">Partial</span>'
                    : '<span class="badge bg-success-subtle text-success-emphasis">Complete</span>');
        }

        // --- Modal: styles then items ----------------------------------------
        function loadItems() {
            modalLoading.classList.remove('d-none');
            step1.classList.add('d-none');
            step2.classList.add('d-none');
            modalError.classList.add('d-none');

            const poId = poIdEl.value;

            return fetch(ITEMS_URL.replace('__ID__', encodeURIComponent(poId)), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    if (poIdEl.value !== poId) return;   // PO changed meanwhile
                    items = data.items || [];
                    loadedPoId = poId;
                    modalPo.textContent = 'PO ' + (esc(data.po_no) || '—') +
                        ' · ' + styleNames().length + ' style(s) · ' + items.length + ' item(s)';
                    showStep(1);
                })
                .catch(status => {
                    modalLoading.classList.add('d-none');
                    modalError.classList.remove('d-none');
                    modalError.textContent = status === 423
                        ? 'This file/style is locked. Stock entry is not allowed.'
                        : 'Could not load the items for this PO. Please try again.';
                });
        }

        const styleKey = (item) => esc(item.style_name) === '' ? '—' : esc(item.style_name);
        const styleNames = () => [...new Set(items.map(styleKey))];

        function showStep(step) {
            modalLoading.classList.add('d-none');
            step1.classList.toggle('d-none', step !== 1);
            step2.classList.toggle('d-none', step !== 2);
            backBtn.classList.toggle('d-none', step !== 2);
            nextBtn.classList.toggle('d-none', step !== 1);
            addBtn.classList.toggle('d-none', step !== 2);
            crumb1.classList.toggle('is-current', step === 1);
            crumb1.classList.toggle('is-done', step === 2);
            crumb2.classList.toggle('is-current', step === 2);

            if (step === 1) renderStyles(); else renderItems();
        }

        // "Added" as a state with a progress bar, rather than a bare "2 / 5" the
        // reader has to interpret.
        function addedState(addedCount, total) {
            if (addedCount === 0) {
                return '<span class="rcv-state text-muted">' +
                    '<span class="rcv-state-bar"><span class="rcv-state-fill" style="width:0"></span></span>' +
                    'Not added yet</span>';
            }

            if (addedCount >= total) {
                return '<span class="rcv-state" style="color:var(--gx-accent-700,#047857);">' +
                    '<i class="bi bi-check-circle-fill" aria-hidden="true"></i>All added</span>';
            }

            const pct = Math.round((addedCount / total) * 100);
            return '<span class="rcv-state text-muted">' +
                '<span class="rcv-state-bar"><span class="rcv-state-fill" style="width:' + pct + '%"></span></span>' +
                addedCount + ' of ' + total + ' added</span>';
        }

        function renderStyles() {
            const already = addedRowIds();
            styleBody.innerHTML = styleNames().map((name, i) => {
                const under = items.filter(it => styleKey(it) === name);
                const addedCount = under.filter(it => already.includes(String(it.excel_row_id))).length;
                const allAdded = addedCount === under.length;
                const cbId = 'rcvStyle' + i;

                return '<tr class="' + (allAdded ? 'is-added' : 'rcv-row') + '">' +
                    '<td><input type="checkbox" class="form-check-input rcv-style-cb" id="' + cbId + '"' +
                        ' value="' + h(name) + '"' + (allAdded ? ' disabled title="Every item under this style is already added"' : '') +
                        ' aria-label="Select style ' + h(name) + '"></td>' +
                    '<td class="rcv-cell-primary fw-semibold">' + dash(name) + '</td>' +
                    '<td class="text-end small">' + under.length + '</td>' +
                    '<td>' + addedState(addedCount, under.length) + '</td>' +
                '</tr>';
            }).join('');

            updateSelCount();
        }

        function chosenStyles() {
            return Array.from(styleBody.querySelectorAll('.rcv-style-cb:checked')).map(cb => cb.value);
        }

        function renderItems() {
            const already = addedRowIds();
            const styles = chosenStyles();
            let html = '';

            // Pairs a value with its label, dropping the pair entirely when the
            // value is blank so empty BOM cells do not print stray labels.
            const sub = (label, value) => esc(value) === '' ? '' : label + ' ' + h(value);
            const joinSub = (parts) => parts.filter(Boolean).join(' · ') || '—';

            styles.forEach(name => {
                const under = items.filter(it => styleKey(it) === name);
                html += '<tr class="rcv-group-row"><td colspan="6">' +
                            '<i class="bi bi-tag me-1" aria-hidden="true"></i>Style ' + dash(name) +
                            ' <span class="fw-normal">· ' + under.length + ' item(s)</span>' +
                        '</td></tr>';

                under.forEach((item, i) => {
                    const isAdded = already.includes(String(item.excel_row_id));
                    const cbId = 'rcvItem' + h(name).replace(/\W/g, '') + i;

                    html += '<tr class="' + (isAdded ? 'is-added' : 'rcv-row') + '">' +
                        '<td><input type="checkbox" class="form-check-input rcv-item-cb" id="' + cbId + '"' +
                            ' value="' + h(item.excel_row_id) + '"' +
                            (isAdded ? ' checked disabled title="Already added below"' : '') +
                            ' aria-label="Select material line ' + h(item.material_name) + '"></td>' +

                        '<td><div class="rcv-cell-primary">' + dash(item.material_name) + '</div>' +
                            '<div class="rcv-cell-sub">' + dash(item.material_description) + '</div></td>' +

                        '<td><div class="rcv-cell-primary small">' + dash(item.art_no) + '</div>' +
                            '<div class="rcv-cell-sub">' + (esc(item.sap_code) === '' ? '—' : 'SAP ' + h(item.sap_code)) + '</div></td>' +

                        // GMTS colour is the garment's, material colour is the
                        // trim's — labelled so the two are never confused.
                        '<td><div class="rcv-cell-primary small">' +
                                joinSub([sub('GMTS', item.gmts_color_name), sub('Mat', item.material_color)]) +
                            '</div>' +
                            '<div class="rcv-cell-sub">' + (esc(item.size) === '' ? '—' : 'Size ' + h(item.size)) + '</div></td>' +

                        '<td class="small">' + dash(item.uom) + '</td>' +
                        '<td class="small text-end">' + dash(item.internal_po_qty) + '</td>' +
                    '</tr>';
                });
            });

            itemBody.innerHTML = html;
            updateSelCount();
        }

        const activeBoxes = () => Array.from(
            (step1.classList.contains('d-none') ? itemBody : styleBody)
                .querySelectorAll(step1.classList.contains('d-none') ? '.rcv-item-cb:not(:disabled)' : '.rcv-style-cb:not(:disabled)')
        );

        const selCountWrap = document.getElementById('rcvSelCountWrap');
        const selCountLabel = document.getElementById('rcvSelCountLabel');

        function updateSelCount() {
            const boxes = activeBoxes();
            const checked = boxes.filter(cb => cb.checked);
            const onItems = step1.classList.contains('d-none');

            selCount.textContent = checked.length;
            selCountLabel.textContent = onItems
                ? (checked.length === 1 ? 'item selected' : 'items selected')
                : (checked.length === 1 ? 'style selected' : 'styles selected');
            selCountWrap.classList.toggle('is-active', checked.length > 0);

            nextBtn.disabled = onItems ? false : checked.length === 0;
            addBtn.disabled = checked.length === 0;

            const master = onItems ? itemAll : styleAll;
            master.disabled = boxes.length === 0;
            master.checked = boxes.length > 0 && boxes.every(cb => cb.checked);

            // Keep the row tint in step with its checkbox.
            boxes.forEach(cb => {
                const tr = cb.closest('tr');
                if (tr) tr.classList.toggle('is-checked', cb.checked);
            });
        }

        [[styleAll, styleBody], [itemAll, itemBody]].forEach(([master, body]) => {
            master.addEventListener('change', function () {
                activeBoxes().forEach(cb => { cb.checked = master.checked; });
                updateSelCount();
            });
            body.addEventListener('change', function (e) {
                if (e.target.classList.contains('rcv-style-cb') || e.target.classList.contains('rcv-item-cb')) {
                    updateSelCount();
                }
            });

            // Clicking anywhere on a selectable row toggles it, so the checkbox
            // is an affordance rather than the only target. Clicks on the box
            // itself are left alone or they would toggle twice.
            body.addEventListener('click', function (e) {
                const tr = e.target.closest('tr.rcv-row');
                if (!tr || e.target.matches('input[type="checkbox"]')) return;

                const cb = tr.querySelector('input[type="checkbox"]:not(:disabled)');
                if (!cb) return;

                cb.checked = !cb.checked;
                updateSelCount();
            });
        });

        nextBtn.addEventListener('click', () => showStep(2));
        backBtn.addEventListener('click', () => showStep(1));

        modalEl.addEventListener('show.bs.modal', function () {
            if (loadedPoId === poIdEl.value) showStep(1); else loadItems();
        });

        addBtn.addEventListener('click', function () {
            activeBoxes().filter(cb => cb.checked).forEach(cb => {
                const item = items.find(it => String(it.excel_row_id) === String(cb.value));
                if (item) addRow(item);
            });
            refreshState();
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        });

        // --- Entry rows -------------------------------------------------------
        function addRow(item, preset) {
            preset = preset || {};
            const i = uid++;
            const n = (field) => 'rows[' + i + '][' + field + ']';
            const unitPrice = preset.unit_price !== undefined ? preset.unit_price : esc(item.suggested_unit_price);
            const received = parseFloat(item.received_qty) || 0;

            const tr = document.createElement('tr');
            tr.dataset.rowId = item.excel_row_id;
            tr.innerHTML =
                '<td class="small fw-semibold">' + dash(item.style_name) +
                    '<input type="hidden" name="' + n('excel_row_id') + '" value="' + h(item.excel_row_id) + '">' +
                    // Shared header values are mirrored here on submit so the
                    // server still receives one complete row per item.
                    '<input type="hidden" name="' + n('receive_date') + '" data-mirror="receive_date">' +
                    '<input type="hidden" name="' + n('grn_date') + '" data-mirror="grn_date">' +
                    '<input type="hidden" name="' + n('invoice_no') + '" data-mirror="invoice_no">' +
                    '<input type="hidden" name="' + n('source_type') + '" data-mirror="source_type">' +
                    '<input type="hidden" name="' + n('remarks') + '" data-mirror="remarks">' +
                '</td>' +
                '<td class="small">' + dash(item.material_name) + '</td>' +
                '<td class="small">' + dash(item.material_description) + '</td>' +
                '<td class="small">' + dash(item.gmts_color_name) + '</td>' +
                '<td class="small">' + dash(item.art_no) + '</td>' +
                '<td class="small">' + dash(item.sap_code) + '</td>' +
                '<td class="small">' + dash(item.material_color) + '</td>' +
                '<td class="small">' + dash(item.size) + '</td>' +
                '<td class="small text-end">' + dash(item.internal_po_qty) + '</td>' +
                '<td class="small text-end rcv-prior' + (received > 0 ? '' : ' rcv-prior-none') + '">' +
                    (received > 0 ? h(trimNum(received)) : '—') + '</td>' +
                '<td><input type="number" step="0.0001" min="0" name="' + n('invoice_qty') + '" data-field="invoice_qty"' +
                    ' value="' + h(preset.invoice_qty) + '" class="form-control form-control-sm"></td>' +
                '<td><input type="number" step="0.0001" min="0" name="' + n('qty') + '" data-field="qty"' +
                    ' value="' + h(preset.qty) + '" class="form-control form-control-sm" required></td>' +
                '<td><input type="number" step="0.0001" min="0" name="' + n('unit_price') + '" data-field="unit_price"' +
                    ' value="' + h(unitPrice) + '" class="form-control form-control-sm"></td>' +
                '<td class="text-end small text-muted" data-field="invoice_value">—</td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rcv-remove"' +
                    ' title="Remove this item" aria-label="Remove this item"><i class="bi bi-trash" aria-hidden="true"></i></button></td>';

            rowsWrap.appendChild(tr);
            recalcRow(tr);
            return tr;
        }

        // Display only; the server recomputes this on save with the same formula.
        //
        // Valued on PHYSICAL Rcv Qty, not Invoice Qty: the invoice states what the
        // vendor billed, but the value carried into stock has to be what actually
        // arrived. A short delivery invoiced in full must not be valued in full.
        function recalcRow(tr) {
            const qty = parseFloat(tr.querySelector('[data-field="qty"]').value);
            const price = parseFloat(tr.querySelector('[data-field="unit_price"]').value);
            tr.querySelector('[data-field="invoice_value"]').textContent =
                (isNaN(qty) || isNaN(price)) ? '—' : (qty * price).toFixed(4);
            recalcTotal();
        }

        // Sum of the rows that have both a physical qty and a rate. Rows missing
        // either contribute nothing rather than being counted as zero.
        function recalcTotal() {
            const totalEl = document.getElementById('rcvTotalValue');
            let total = 0;
            let counted = 0;

            Array.from(rowsWrap.querySelectorAll('tr')).forEach(tr => {
                const qty = parseFloat(tr.querySelector('[data-field="qty"]').value);
                const price = parseFloat(tr.querySelector('[data-field="unit_price"]').value);
                if (!isNaN(qty) && !isNaN(price)) { total += qty * price; counted++; }
            });

            totalEl.textContent = counted === 0 ? '—' : total.toFixed(2);
        }

        rowsWrap.addEventListener('input', function (e) {
            const field = e.target.dataset.field;
            // Invoice Qty no longer feeds the value, so it no longer triggers it.
            if (field === 'qty' || field === 'unit_price') recalcRow(e.target.closest('tr'));
        });

        rowsWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.rcv-remove');
            if (!btn) return;
            btn.closest('tr').remove();
            refreshState();
        });

        // Copy the shared header values into every row's hidden inputs.
        function syncShared() {
            document.querySelectorAll('[data-shared]').forEach(src => {
                const field = src.dataset.shared;
                rowsWrap.querySelectorAll('[data-mirror="' + field + '"]').forEach(dst => {
                    dst.value = src.value;
                });
            });
        }

        document.querySelectorAll('[data-shared]').forEach(el => {
            el.addEventListener('input', syncShared);
            el.addEventListener('change', syncShared);
        });
        form.addEventListener('submit', syncShared);

        function refreshState() {
            const n = rowsWrap.children.length;
            const independent = filterType.value === 'independent';
            countEl.textContent = n;
            sharedWrap.classList.toggle('d-none', independent || n === 0);
            // The hint only has a job while nothing is chosen yet — once a PO is
            // selected the card above it says what to do next.
            emptyBox.classList.toggle('d-none', independent || n > 0 || poIdEl.value !== '');
            recalcTotal();

            if (n > 0) {
                // PO-level identity is identical on every line, so it is read
                // from the first row's item and shown once.
                const first = items.find(it => String(it.excel_row_id) === String(rowsWrap.children[0].dataset.rowId));
                if (first) {
                    document.getElementById('shSupplier').value = esc(first.supplier_name) || '—';
                    document.getElementById('shBuyer').value = esc(first.buyer_name) || '—';
                    document.getElementById('shSeason').value = esc(first.season_name) || '—';
                    document.getElementById('shPoNo').value = esc(first.po_no) || '—';
                    document.getElementById('shUom').value = esc(first.uom) || '—';
                }
                if (!document.getElementById('shInvoiceNo').value && first && first.suggested_invoice_no) {
                    document.getElementById('shInvoiceNo').value = first.suggested_invoice_no;
                }
            }

            syncShared();
        }

        refreshState();

        // Rebuild after a validation-error redirect so nothing typed is lost.
        const oldRows = @json(old('rows', []));
        const oldPoId = @json(old('booking_po_id'));
        if (oldPoId && Object.keys(oldRows).length) {
            const list = Object.values(oldRows);
            poIdEl.value = oldPoId;
            loadItems().then(() => {
                const shared = list[0] || {};
                if (shared.receive_date) document.getElementById('shReceiveDate').value = shared.receive_date;
                if (shared.grn_date) document.getElementById('shGrnDate').value = shared.grn_date;
                if (shared.invoice_no) document.getElementById('shInvoiceNo').value = shared.invoice_no;
                if (shared.source_type) document.getElementById('shSourceType').value = shared.source_type;
                if (shared.remarks) document.getElementById('shRemarks').value = shared.remarks;

                list.forEach(old => {
                    const item = items.find(it => String(it.excel_row_id) === String(old.excel_row_id));
                    if (item) addRow(item, old);
                });

                const first = items[0];
                if (first) {
                    document.getElementById('rcvSelectedPoNo').textContent = esc(first.po_no) || '—';
                    selectedWrap.classList.remove('d-none');
                    // items is already populated by loadItems() above, so the
                    // summary is filled from it rather than re-fetched.
                    setSummary(items);
                }
                refreshState();
            });
        }

        // --- Independent entry: BOM-backed material fields --------------------
        /**
         * The six Material inputs stay ordinary text inputs — same names, same
         * submitted values. This only hangs a suggestion list off each one,
         * built from the BOM lines the chosen style already carries.
         *
         * The whole lines are kept, not six independent value lists, so choosing
         * a material name can narrow the other fields to combinations that
         * actually exist rather than offering every colour in the style.
         */
        const bomFields = (function initBomFields() {
            const boxes = Array.from(document.querySelectorAll('[data-bom-field]'));
            if (!boxes.length) return null;

            const note = document.getElementById('rcvIndBomNote');
            let lines = [];

            const fields = boxes.map((wrap) => ({
                key: wrap.dataset.bomField,
                wrap,
                input: wrap.querySelector('input'),
                panel: wrap.querySelector('[data-bom-panel]'),
                list: wrap.querySelector('[data-bom-list]'),
                hint: wrap.querySelector('[data-bom-hint]'),
            }));

            const byKey = (key) => fields.find((f) => f.key === key);
            const nameField = byKey('material_name');

            const closeAll = () => fields.forEach((f) => {
                f.panel.classList.add('d-none');
                f.input.setAttribute('aria-expanded', 'false');
            });

            /**
             * Lines still in play. Once a material name has been entered that
             * matches the BOM, the other fields only offer values from that
             * material's lines — a colour that never appears against it is not a
             * useful suggestion.
             */
            function scopedLines(forKey) {
                const chosen = (nameField && nameField.input.value.trim().toLowerCase()) || '';
                if (!chosen || forKey === 'material_name') return lines;

                const narrowed = lines.filter((l) =>
                    esc(l.material_name).trim().toLowerCase() === chosen);

                // A name the user typed themselves matches nothing, so narrowing
                // would leave every other field with no suggestions at all.
                return narrowed.length ? narrowed : lines;
            }

            function optionsFor(field) {
                const term = field.input.value.trim().toLowerCase();

                return scopedLines(field.key)
                    .map((l) => l[field.key])
                    .filter((v) => esc(v) !== '')
                    .filter((v, i, all) => all.indexOf(v) === i)
                    .filter((v) => term === '' || String(v).toLowerCase().includes(term))
                    .sort((a, b) => String(a).localeCompare(String(b)));
            }

            function render(field) {
                const options = optionsFor(field);

                // No BOM value to offer — the field simply behaves as free text.
                if (!options.length) {
                    field.panel.classList.add('d-none');
                    field.input.setAttribute('aria-expanded', 'false');
                    return;
                }

                field.hint.textContent = options.length +
                    (options.length === 1 ? ' value on this style' : ' values on this style');

                field.list.innerHTML = options.map((v) =>
                    '<div class="list-group-item rcv-option" role="option" tabindex="-1" data-value="' + h(v) + '">' +
                        '<div class="rcv-option-primary">' + dash(v) + '</div>' +
                    '</div>').join('');

                field.panel.classList.remove('d-none');
                field.input.setAttribute('aria-expanded', 'true');
            }

            fields.forEach((field) => {
                field.input.addEventListener('focus', () => { closeAll(); render(field); });
                field.input.addEventListener('click', () => { closeAll(); render(field); });
                field.input.addEventListener('input', () => render(field));

                field.input.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') closeAll();
                    // Never submit the form from a suggestion box.
                    if (e.key === 'Enter') e.preventDefault();
                });

                field.list.addEventListener('mousedown', (e) => e.preventDefault());

                field.list.addEventListener('click', function (e) {
                    const option = e.target.closest('.rcv-option');
                    if (!option) return;

                    field.input.value = option.dataset.value || '';
                    closeAll();

                    // Choosing the material name is the one pick that can carry
                    // the rest: only when it resolves to exactly one BOM line is
                    // there a single correct answer for the other fields. With
                    // several lines the options are narrowed instead — filling
                    // one of them would be a guess.
                    if (field.key === 'material_name') autofillFrom(option.dataset.value);
                });
            });

            function autofillFrom(materialName) {
                const matches = lines.filter((l) =>
                    esc(l.material_name).trim().toLowerCase() === String(materialName).trim().toLowerCase());

                if (matches.length !== 1) return;

                const line = matches[0];
                fields.forEach((f) => {
                    if (f.key === 'material_name') return;
                    // Never overwrite something the user already typed.
                    if (f.input.value.trim() !== '') return;
                    if (esc(line[f.key]) !== '') f.input.value = line[f.key];
                });
            }

            document.addEventListener('click', function (e) {
                if (!e.target.closest('[data-bom-field]')) closeAll();
            });

            return {
                /** Swap in the BOM for a newly chosen style. */
                load(styleName) {
                    lines = [];
                    closeAll();

                    if (!styleName) {
                        note.textContent = '';
                        return;
                    }

                    note.textContent = 'Loading material values for this style…';

                    fetch(STYLE_BOM_URL + '?style_name=' + encodeURIComponent(styleName),
                            { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(r => r.ok ? r.json() : Promise.reject(r.status))
                        .then(data => {
                            lines = data.lines || [];
                            note.textContent = lines.length
                                ? 'Click a field to pick from the ' + lines.length +
                                  ' material line(s) already on this style, or type a new value.'
                                : 'This style has no BOM material lines yet — type the values in.';
                        })
                        .catch(() => {
                            lines = [];
                            note.textContent = 'Could not load this style’s BOM values. You can still type them in.';
                        });
                },
            };
        })();

        // --- Independent entry: style picker ----------------------------------
        // A small dedicated picker rather than a branch inside the PO search
        // above: that one carries browse caching, per-type labels and PO
        // selection semantics that mean nothing for a style.
        (function initStylePicker() {
            const box = document.getElementById('rcvIndSearch');
            if (!box) return;

            const wrap = document.getElementById('rcvIndSearchWrap');
            const panel = document.getElementById('rcvIndResults');
            const list = document.getElementById('rcvIndResultsList');
            const hint = document.getElementById('rcvIndResultsHint');
            const body = document.getElementById('rcvIndBody');
            const chip = document.getElementById('rcvIndChip');

            const buyerEl = document.getElementById('rcvIndBuyer');
            const seasonEl = document.getElementById('rcvIndSeason');
            const styleEl = document.getElementById('rcvIndStyle');

            let loaded = null;
            let timer = null;

            const open = () => { panel.classList.remove('d-none'); box.setAttribute('aria-expanded', 'true'); };
            const close = () => { panel.classList.add('d-none'); box.setAttribute('aria-expanded', 'false'); };

            function render(results, term) {
                if (!results.length) {
                    hint.textContent = '';
                    list.innerHTML = '<div class="list-group-item text-center text-muted py-3 small">No matching styles' +
                        (term ? ' for “' + h(term) + '”' : '') + '</div>';
                    return;
                }

                hint.textContent = term === ''
                    ? 'All styles (' + results.length + ')'
                    : results.length + (results.length === 1 ? ' match' : ' matches');

                list.innerHTML = results.map(r =>
                    '<div class="list-group-item rcv-option" role="option" tabindex="-1"' +
                        ' data-style="' + h(r.style_name) + '"' +
                        ' data-buyer="' + h(r.buyer_name) + '"' +
                        ' data-season="' + h(r.season_name) + '">' +
                        '<div class="rcv-option-primary">' + dash(r.style_name) + '</div>' +
                        '<div class="rcv-option-meta">' +
                            dash([r.buyer_name, r.season_name].filter(Boolean).join(' · ')) + '</div>' +
                    '</div>'
                ).join('');
            }

            function load(term) {
                // The full list is cached once; a complete dataset is filtered in
                // the browser rather than re-fetched on every keystroke.
                if (term === '' && loaded) { render(loaded, ''); return Promise.resolve(); }

                hint.textContent = 'Loading…';
                list.innerHTML = '';

                return fetch(STYLE_SEARCH_URL + (term ? '?term=' + encodeURIComponent(term) : ''),
                        { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(data => {
                        const results = data.results || [];
                        if (term === '' && data.complete) loaded = results;
                        render(results, term);
                    })
                    .catch(() => {
                        hint.textContent = '';
                        list.innerHTML = '<div class="list-group-item text-muted small">Could not load styles. Please try again.</div>';
                    });
            }

            const show = () => { open(); load(box.value.trim()); };

            box.addEventListener('focus', show);
            box.addEventListener('click', show);

            box.addEventListener('input', function () {
                clearTimeout(timer);
                const term = box.value.trim();
                open();

                if (loaded) {
                    const needle = term.toLowerCase();
                    render(needle === '' ? loaded : loaded.filter(r =>
                        [r.style_name, r.buyer_name, r.season_name]
                            .some(f => esc(f).toLowerCase().includes(needle))), term);
                    return;
                }

                timer = setTimeout(() => load(term), DEBOUNCE_MS);
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') close();
                // Enter must never submit the form from the search box.
                if (e.key === 'Enter') e.preventDefault();
            });

            document.addEventListener('click', function (e) {
                if (!wrap.contains(e.target)) close();
            });

            list.addEventListener('click', function (e) {
                const option = e.target.closest('.rcv-option');
                if (!option) return;

                styleEl.value = option.dataset.style || '';
                buyerEl.value = option.dataset.buyer || '';
                seasonEl.value = option.dataset.season || '';

                chip.textContent = [option.dataset.buyer, option.dataset.style]
                    .filter(Boolean).join(' / ') || '—';
                body.classList.remove('d-none');
                box.value = '';
                close();

                // Offer this style's own BOM values in the Material fields.
                if (bomFields) bomFields.load(styleEl.value);
            });

            document.getElementById('rcvIndClear').addEventListener('click', function () {
                styleEl.value = ''; buyerEl.value = ''; seasonEl.value = '';
                body.classList.add('d-none');
                if (bomFields) bomFields.load('');
                box.focus();
            });

            // A rejected save comes back with old() already in the fields; the
            // chip and the body have to be reopened to match, or the user is
            // looking at an empty style picker over a form they did fill.
            if (styleEl.value) {
                chip.textContent = [buyerEl.value, styleEl.value].filter(Boolean).join(' / ') || '—';
                body.classList.remove('d-none');
                if (bomFields) bomFields.load(styleEl.value);
            }
        })();

        // Reflect whichever mode the page loaded in, including after a rejected
        // Independent save.
        applyMode();

        // --- Linking an Independent receiving to its PO -----------------------
        (function initLinkModal() {
            const modalNode = document.getElementById('rcvLinkModal');
            if (!modalNode) return;

            const linkForm = document.getElementById('rcvLinkForm');
            const subtitle = document.getElementById('rcvLinkSubtitle');
            const box = document.getElementById('rcvLinkSearch');
            const wrap = document.getElementById('rcvLinkSearchWrap');
            const panel = document.getElementById('rcvLinkResults');
            const optionList = document.getElementById('rcvLinkResultsList');
            const hint = document.getElementById('rcvLinkResultsHint');
            const itemsWrap = document.getElementById('rcvLinkItemsWrap');
            const itemTbody = document.getElementById('rcvLinkItemBody');
            const loading = document.getElementById('rcvLinkLoading');
            const errorBox = document.getElementById('rcvLinkError');
            const submitBtn = document.getElementById('rcvLinkSubmit');
            const linkPoId = document.getElementById('rcvLinkPoId');
            const linkRowId = document.getElementById('rcvLinkRowId');

            let timer = null;

            const open = () => panel.classList.remove('d-none');
            const close = () => panel.classList.add('d-none');

            // The PO lookup reuses the page's existing endpoint and its PO Number
            // browse list — the same search Store already knows.
            function search(term) {
                hint.textContent = term ? 'Searching…' : 'Loading…';
                optionList.innerHTML = '';

                const url = SEARCH_URL + '?type=po_no' + (term ? '&term=' + encodeURIComponent(term) : '');

                return fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(data => {
                        const results = data.results || [];
                        hint.textContent = results.length
                            ? results.length + (results.length === 1 ? ' match' : ' matches')
                            : '';

                        optionList.innerHTML = results.length
                            ? results.map(r =>
                                '<div class="list-group-item rcv-option" role="option" tabindex="-1"' +
                                    ' data-id="' + h(r.id) + '" data-po="' + h(r.po_no) + '">' +
                                    '<div class="rcv-option-primary">' + dash(r.po_no) + '</div>' +
                                    '<div class="rcv-option-meta">' +
                                        dash([r.buyer_name, r.season_name, r.vendor_name].filter(Boolean).join(' · ')) + '</div>' +
                                '</div>').join('')
                            : '<div class="list-group-item text-center text-muted py-3 small">No matching POs</div>';
                    })
                    .catch(() => {
                        hint.textContent = '';
                        optionList.innerHTML = '<div class="list-group-item text-muted small">Could not load POs. Please try again.</div>';
                    });
            }

            box.addEventListener('focus', () => { open(); search(box.value.trim()); });
            box.addEventListener('click', () => { open(); search(box.value.trim()); });
            box.addEventListener('input', function () {
                clearTimeout(timer);
                open();
                timer = setTimeout(() => search(box.value.trim()), DEBOUNCE_MS);
            });
            box.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
            document.addEventListener('click', (e) => { if (!wrap.contains(e.target)) close(); });

            optionList.addEventListener('click', function (e) {
                const option = e.target.closest('.rcv-option');
                if (!option) return;

                linkPoId.value = option.dataset.id;
                linkRowId.value = '';
                submitBtn.disabled = true;
                box.value = option.dataset.po || '';
                close();
                loadLines(option.dataset.id);
            });

            // Stock is booked against one BOM line, so the PO alone is not enough.
            function loadLines(id) {
                itemsWrap.classList.add('d-none');
                errorBox.classList.add('d-none');
                loading.classList.remove('d-none');

                fetch(ITEMS_URL.replace('__ID__', encodeURIComponent(id)),
                        { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(data => {
                        loading.classList.add('d-none');
                        const list = data.items || [];

                        if (!list.length) {
                            errorBox.textContent = 'This PO has no material lines to link against.';
                            errorBox.classList.remove('d-none');
                            return;
                        }

                        itemTbody.innerHTML = list.map(item =>
                            '<tr class="rcv-link-row" style="cursor:pointer;">' +
                                '<td><input type="radio" class="form-check-input rcv-link-cb" name="rcv_link_pick"' +
                                    ' value="' + h(item.excel_row_id) + '"' +
                                    ' aria-label="Select material line ' + h(item.material_name) + '"></td>' +
                                '<td><div class="fw-semibold small">' + dash(item.material_name) + '</div>' +
                                    '<div class="small text-muted">' + dash(item.material_description) + '</div></td>' +
                                '<td class="small">' + dash(item.style_name) + '</td>' +
                                '<td class="small">' + dash([item.material_color, item.size].filter(Boolean).join(' · ')) + '</td>' +
                                '<td class="small text-end">' + trimNum(parseFloat(item.received_qty) || 0) + ' received</td>' +
                            '</tr>').join('');

                        itemsWrap.classList.remove('d-none');
                    })
                    .catch((status) => {
                        loading.classList.add('d-none');
                        errorBox.textContent = status === 423
                            ? 'This file/style is locked. Linking is not allowed.'
                            : 'Could not load the items for this PO. Please try again.';
                        errorBox.classList.remove('d-none');
                    });
            }

            itemTbody.addEventListener('click', function (e) {
                const row = e.target.closest('tr');
                if (!row) return;

                const radio = row.querySelector('.rcv-link-cb');
                if (!radio) return;
                radio.checked = true;
                linkRowId.value = radio.value;
                submitBtn.disabled = false;
            });

            // Opening from a row carries that receiving's id into the form action.
            document.addEventListener('click', function (e) {
                const trigger = e.target.closest('[data-rcv-link]');
                if (!trigger) return;

                linkForm.action = LINK_URL.replace('__ID__', encodeURIComponent(trigger.dataset.rcvLink));
                subtitle.textContent = 'GRN ' + (trigger.dataset.rcvGrn || '—') + ' · ' + (trigger.dataset.rcvStyle || '—');

                linkPoId.value = '';
                linkRowId.value = '';
                box.value = '';
                submitBtn.disabled = true;
                itemsWrap.classList.add('d-none');
                errorBox.classList.add('d-none');
                itemTbody.innerHTML = '';
                close();

                bootstrap.Modal.getOrCreateInstance(modalNode).show();
            });
        })();
    })();
</script>
@endsection
