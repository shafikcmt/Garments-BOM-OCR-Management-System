@extends('layouts.app')

@section('title', 'Material Stock — Receiving')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-arrow-in-down"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Material Receiving</h3>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-clipboard-data me-1"></i>Closing Stock</a>
                <a href="{{ route('store.material.bulk-issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1"></i>Bulk Issue</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h5 class="mb-3">Record Receiving</h5>
            @if(! $hasBookingPos)
                <p class="text-muted small mb-0">No Booking POs available to receive against.</p>
            @else
            <form method="POST" action="{{ route('store.material.receivings.store') }}" id="rcvForm">
                @csrf
                <input type="hidden" name="booking_po_id" id="rcvPoId" value="{{ old('booking_po_id') }}">

                {{-- Step 1 — find the PO. Store may know the delivery by its PO
                     number, the vendor's PI number, or the material's SAP code;
                     all three resolve to the same booking record. --}}
                <div class="row g-3 align-items-end mb-3">
                    <div class="col-12 col-lg-3">
                        <label class="form-label fw-semibold">Search by</label>
                        <select class="form-select" id="rcvFilterType">
                            <option value="po_no" selected>PO Number</option>
                            <option value="sap_code">SAP Code</option>
                            <option value="pi_number">PI Number</option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label fw-semibold" id="rcvSearchLabel">PO Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control" id="rcvSearch" autocomplete="off"
                                   placeholder="Type to search, or leave blank to list recent POs">
                        </div>
                    </div>
                    <div class="col-12 col-lg-3">
                        <button type="button" class="btn btn-outline-secondary w-100" id="rcvFindBtn">
                            <i class="bi bi-arrow-right-circle me-1"></i>Find PO
                        </button>
                    </div>
                </div>

                {{-- A search may match more than one PO (one SAP code can appear
                     under several), so the PO is always confirmed explicitly. --}}
                <div id="rcvResults" class="mb-3 d-none">
                    <div class="small text-muted mb-2" id="rcvResultsHint"></div>
                    <div class="list-group" id="rcvResultsList"></div>
                </div>

                <div id="rcvSelectedPo" class="d-none border rounded-3 bg-body-secondary p-3 mb-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                        <div>
                            <div class="small text-uppercase text-muted fw-semibold">Selected PO</div>
                            <div class="fw-semibold" id="rcvSelectedPoNo">—</div>
                            <div class="small text-muted" id="rcvSelectedPoMeta">—</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary" id="rcvChangePo">Change PO</button>
                            <button type="button" class="btn btn-primary" id="rcvPickBtn"
                                    data-bs-toggle="modal" data-bs-target="#rcvItemsModal">
                                <i class="bi bi-list-check me-1"></i>Select Items
                            </button>
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

                <div id="rcvEmpty" class="border rounded-3 bg-body-secondary text-center text-muted py-5">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:24px;"></i>
                    Find a PO, then choose the styles and items received in this delivery.
                </div>

                {{-- Shared header. These describe the delivery as a whole, or are
                     PO-level identity that is identical on every line, so they are
                     entered once instead of being repeated per row. Their values
                     are mirrored into hidden per-row inputs on submit, so the
                     server still receives (and validates) one complete row each. --}}
                <div id="rcvShared" class="d-none">
                    <div class="border rounded-3 bg-body-secondary p-3 mb-3">
                        <div class="small fw-semibold text-uppercase text-muted mb-2">
                            <i class="bi bi-magic me-1"></i>PO Details <span class="text-muted">— read-only</span>
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
                            <i class="bi bi-truck me-1"></i>Delivery Details <span class="text-muted">— applies to every item below</span>
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
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold">Source</label>
                                <select id="shSourceType" data-shared="source_type" class="form-select">
                                    <option value="booking" selected>Booking-wise</option>
                                    <option value="internal_po">Internal PO-wise</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-3">
                                <label class="form-label fw-semibold">GRN No</label>
                                <input type="text" class="form-control bg-light text-muted" value="Auto-generated" readonly disabled>
                                <div class="form-text text-muted"><i class="bi bi-magic me-1"></i>One GRN per item, on save</div>
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

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="text-muted small"><span id="rcvCount">0</span> item(s) — one GRN will be generated per item.</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rcvItemsModal">
                                <i class="bi bi-plus-lg me-1"></i>Add More Items
                            </button>
                            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Save Receivings</button>
                        </div>
                    </div>
                </div>
            </form>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h5 class="mb-3">Receiving History <span class="badge bg-primary-subtle text-primary ms-1">{{ $receivings->total() }}</span></h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
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
                                    @if($r->invoice_no)<span class="text-muted">Inv: {{ $r->invoice_no }}</span>@endif
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $r->po_no }} · {{ $r->material_description }}</div>
                                    <div class="small text-muted">{{ collect([$r->buyer_name.' / '.$r->style_name, $r->material_color, $r->size])->filter()->implode(' · ') }}</div>
                                </td>
                                <td><span class="badge bg-info-subtle text-info">{{ $r->source_type=='internal_po' ? 'Internal PO' : 'Booking' }}</span></td>
                                <td class="text-end small">{{ $r->invoice_qty !== null ? rtrim(rtrim(number_format((float)$r->invoice_qty, 4), '0'), '.') : '—' }}</td>
                                <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$r->qty, 4), '0'), '.') }}</td>
                                <td class="text-end small">
                                    {{ $r->unit_price !== null ? number_format((float)$r->unit_price, 4) : '—' }}
                                    @if($r->invoice_value !== null)<div class="text-muted">Val: {{ number_format((float)$r->invoice_value, 2) }}</div>@endif
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('store.material.receivings.destroy', $r) }}" onsubmit="return confirm('Remove this receiving? Closing stock will update.');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                    </form>
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

{{-- Two-level item picker: Style first, then the item(s) under each style. A
     style can carry one item or several, which a flat list made hard to read. --}}
<div class="modal fade" id="rcvItemsModal" tabindex="-1" aria-labelledby="rcvItemsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="rcvItemsModalLabel">Select Items</h5>
                    <div class="small text-muted" id="rcvModalPo">—</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <ol class="breadcrumb small mb-3">
                    <li class="breadcrumb-item" id="rcvCrumb1"><span class="fw-semibold">1. Choose Style</span></li>
                    <li class="breadcrumb-item text-muted" id="rcvCrumb2">2. Choose Items</li>
                </ol>

                <div id="rcvModalLoading" class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
                </div>
                <div id="rcvModalError" class="alert alert-warning d-none mb-0"></div>

                {{-- Level 1: styles --}}
                <div id="rcvStep1" class="d-none">
                    <div class="table-responsive" style="max-height:50vh;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="sticky-top bg-body">
                                <tr class="text-muted small text-uppercase">
                                    <th style="width:38px;">
                                        <input type="checkbox" class="form-check-input" id="rcvStyleAll"
                                               title="Select all styles" aria-label="Select all styles">
                                    </th>
                                    <th>Style Number</th>
                                    <th class="text-end">Items under this style</th>
                                    <th class="text-end">Already added</th>
                                </tr>
                            </thead>
                            <tbody id="rcvStyleBody"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Level 2: items within the chosen styles --}}
                <div id="rcvStep2" class="d-none">
                    <div class="table-responsive" style="max-height:50vh;">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="sticky-top bg-body">
                                <tr class="text-muted small text-uppercase">
                                    <th style="width:38px;">
                                        <input type="checkbox" class="form-check-input" id="rcvItemAll"
                                               title="Select all available items" aria-label="Select all available items">
                                    </th>
                                    <th>Material Name</th>
                                    <th>Material Description</th>
                                    <th>GMTS Color</th>
                                    <th>Art. No</th>
                                    <th>SAP Code</th>
                                    <th>Material Color</th>
                                    <th>Size</th>
                                    <th>Unit</th>
                                    <th class="text-end">Internal PO Qty</th>
                                </tr>
                            </thead>
                            <tbody id="rcvItemBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <span class="me-auto small text-muted"><span id="rcvSelCount">0</span> selected</span>
                <button type="button" class="btn btn-outline-secondary d-none" id="rcvBackBtn"><i class="bi bi-arrow-left me-1"></i>Back to Styles</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="rcvNextBtn">Next: Choose Items<i class="bi bi-arrow-right ms-1"></i></button>
                <button type="button" class="btn btn-primary d-none" id="rcvAddSelected"><i class="bi bi-plus-lg me-1"></i>Add Selected</button>
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
        const findBtn = document.getElementById('rcvFindBtn');
        const resultsWrap = document.getElementById('rcvResults');
        const resultsList = document.getElementById('rcvResultsList');
        const resultsHint = document.getElementById('rcvResultsHint');
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

        const LABELS = { po_no: 'PO Number', sap_code: 'SAP Code', pi_number: 'PI Number' };

        // esc() normalises a value for logic; h() escapes it for HTML/attribute
        // interpolation. BOM values come from uploaded workbooks, so anything
        // built into markup below must go through h()/dash().
        const esc = (v) => (v === null || v === undefined || v === '') ? '' : String(v);
        const h = (v) => esc(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const dash = (v) => esc(v) === '' ? '—' : h(v);

        let items = [];         // every line under the loaded PO
        let loadedPoId = null;
        let uid = 0;

        const addedRowIds = () => Array.from(rowsWrap.querySelectorAll('[data-row-id]'))
            .map(el => String(el.dataset.rowId));

        // --- PO search --------------------------------------------------------
        filterType.addEventListener('change', function () {
            searchLabel.textContent = LABELS[filterType.value];
            searchEl.placeholder = filterType.value === 'po_no'
                ? 'Type to search, or leave blank to list recent POs'
                : 'Type the ' + LABELS[filterType.value];
            searchEl.value = '';
            resultsWrap.classList.add('d-none');
        });

        searchEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
        });
        findBtn.addEventListener('click', runSearch);

        function runSearch() {
            const type = filterType.value;
            const term = searchEl.value.trim();

            resultsWrap.classList.remove('d-none');
            resultsHint.textContent = 'Searching…';
            resultsList.innerHTML = '';

            const url = SEARCH_URL + '?type=' + encodeURIComponent(type) + '&term=' + encodeURIComponent(term);

            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => renderResults(data.results || [], type, term))
                .catch(() => {
                    resultsHint.textContent = '';
                    resultsList.innerHTML =
                        '<div class="list-group-item text-muted">Could not run the search. Please try again.</div>';
                });
        }

        function renderResults(results, type, term) {
            if (!results.length) {
                resultsHint.textContent = '';
                resultsList.innerHTML = '<div class="list-group-item text-muted">No PO found for this ' +
                    h(LABELS[type]) + (term ? ' (“' + h(term) + '”)' : '') + '.</div>';
                return;
            }

            resultsHint.textContent = results.length === 1
                ? '1 matching PO — selected automatically.'
                : results.length + ' POs match. Choose the one you are receiving against.';

            resultsList.innerHTML = results.map(r =>
                '<button type="button" class="list-group-item list-group-item-action rcv-result"' +
                    ' data-id="' + h(r.id) + '" data-po="' + h(r.po_no) + '"' +
                    ' data-meta="' + h([r.buyer_name, r.season_name, r.vendor_name].filter(Boolean).join(' · ')) + '">' +
                    '<span class="fw-semibold">' + dash(r.po_no) + '</span>' +
                    '<span class="small text-muted ms-2">' +
                        dash([r.buyer_name, r.season_name, r.vendor_name].filter(Boolean).join(' · ')) +
                    '</span>' +
                '</button>').join('');

            if (results.length === 1) selectPo(resultsList.querySelector('.rcv-result'));
        }

        resultsList.addEventListener('click', function (e) {
            const btn = e.target.closest('.rcv-result');
            if (btn) selectPo(btn);
        });

        function selectPo(btn) {
            const newId = btn.dataset.id;

            if (rowsWrap.children.length && String(newId) !== String(poIdEl.value)) {
                if (!confirm('Changing the PO will clear the items already added. Continue?')) return;
                rowsWrap.innerHTML = '';
            }

            poIdEl.value = newId;
            document.getElementById('rcvSelectedPoNo').textContent = btn.dataset.po || '—';
            document.getElementById('rcvSelectedPoMeta').textContent = btn.dataset.meta || '—';
            selectedWrap.classList.remove('d-none');
            resultsWrap.classList.add('d-none');
            refreshState();
        }

        document.getElementById('rcvChangePo').addEventListener('click', function () {
            resultsWrap.classList.remove('d-none');
            runSearch();
        });

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
            crumb1.classList.toggle('text-muted', step !== 1);
            crumb2.classList.toggle('text-muted', step !== 2);
            crumb1.innerHTML = step === 1 ? '<span class="fw-semibold">1. Choose Style</span>' : '1. Choose Style';
            crumb2.innerHTML = step === 2 ? '<span class="fw-semibold">2. Choose Items</span>' : '2. Choose Items';

            if (step === 1) renderStyles(); else renderItems();
        }

        function renderStyles() {
            const already = addedRowIds();
            styleBody.innerHTML = styleNames().map((name, i) => {
                const under = items.filter(it => styleKey(it) === name);
                const addedCount = under.filter(it => already.includes(String(it.excel_row_id))).length;
                const allAdded = addedCount === under.length;
                const cbId = 'rcvStyle' + i;

                return '<tr' + (allAdded ? ' class="opacity-50"' : '') + '>' +
                    '<td><input type="checkbox" class="form-check-input rcv-style-cb" id="' + cbId + '"' +
                        ' value="' + h(name) + '"' + (allAdded ? ' disabled title="Every item under this style is already added"' : '') +
                        ' aria-label="Select this style"></td>' +
                    '<td><label for="' + cbId + '" class="mb-0 fw-semibold" style="cursor:pointer;">' + dash(name) + '</label></td>' +
                    '<td class="text-end small">' + under.length + '</td>' +
                    '<td class="text-end small text-muted">' + addedCount + ' / ' + under.length + '</td>' +
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

            styles.forEach(name => {
                const under = items.filter(it => styleKey(it) === name);
                html += '<tr class="table-light"><td colspan="10" class="fw-semibold small">' +
                            '<i class="bi bi-tag me-1"></i>Style ' + dash(name) +
                            ' <span class="text-muted fw-normal">· ' + under.length + ' item(s)</span>' +
                        '</td></tr>';

                under.forEach((item, i) => {
                    const isAdded = already.includes(String(item.excel_row_id));
                    const cbId = 'rcvItem' + h(name).replace(/\W/g, '') + i;

                    html += '<tr' + (isAdded ? ' class="opacity-50"' : '') + '>' +
                        '<td><input type="checkbox" class="form-check-input rcv-item-cb" id="' + cbId + '"' +
                            ' value="' + h(item.excel_row_id) + '"' +
                            (isAdded ? ' checked disabled title="Already added below"' : '') +
                            ' aria-label="Select this material line"></td>' +
                        '<td class="small"><label for="' + cbId + '" class="mb-0" style="cursor:pointer;">' + dash(item.material_name) + '</label></td>' +
                        '<td class="small">' + dash(item.material_description) + '</td>' +
                        '<td class="small">' + dash(item.gmts_color_name) + '</td>' +
                        '<td class="small">' + dash(item.art_no) + '</td>' +
                        '<td class="small">' + dash(item.sap_code) + '</td>' +
                        '<td class="small">' + dash(item.material_color) + '</td>' +
                        '<td class="small">' + dash(item.size) + '</td>' +
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

        function updateSelCount() {
            const boxes = activeBoxes();
            const checked = boxes.filter(cb => cb.checked);
            selCount.textContent = checked.length;
            nextBtn.disabled = step1.classList.contains('d-none') ? false : checked.length === 0;
            addBtn.disabled = checked.length === 0;

            const master = step1.classList.contains('d-none') ? itemAll : styleAll;
            master.disabled = boxes.length === 0;
            master.checked = boxes.length > 0 && boxes.every(cb => cb.checked);
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

            const tr = document.createElement('tr');
            tr.dataset.rowId = item.excel_row_id;
            tr.innerHTML =
                '<td class="small fw-semibold">' + dash(item.style_name) +
                    '<input type="hidden" name="' + n('excel_row_id') + '" value="' + h(item.excel_row_id) + '">' +
                    // Shared header values are mirrored here on submit so the
                    // server still receives one complete row per item.
                    '<input type="hidden" name="' + n('receive_date') + '" data-mirror="receive_date">' +
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
                '<td><input type="number" step="0.0001" min="0" name="' + n('invoice_qty') + '" data-field="invoice_qty"' +
                    ' value="' + h(preset.invoice_qty) + '" class="form-control form-control-sm"></td>' +
                '<td><input type="number" step="0.0001" min="0" name="' + n('qty') + '"' +
                    ' value="' + h(preset.qty) + '" class="form-control form-control-sm" required></td>' +
                '<td><input type="number" step="0.0001" min="0" name="' + n('unit_price') + '" data-field="unit_price"' +
                    ' value="' + h(unitPrice) + '" class="form-control form-control-sm"></td>' +
                '<td class="text-end small text-muted" data-field="invoice_value">—</td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rcv-remove"' +
                    ' title="Remove this item" aria-label="Remove this item"><i class="bi bi-trash"></i></button></td>';

            rowsWrap.appendChild(tr);
            recalcRow(tr);
            return tr;
        }

        // Display only; the server recomputes this on save.
        function recalcRow(tr) {
            const qty = parseFloat(tr.querySelector('[data-field="invoice_qty"]').value);
            const price = parseFloat(tr.querySelector('[data-field="unit_price"]').value);
            tr.querySelector('[data-field="invoice_value"]').textContent =
                (isNaN(qty) || isNaN(price)) ? '—' : (qty * price).toFixed(4);
        }

        rowsWrap.addEventListener('input', function (e) {
            const field = e.target.dataset.field;
            if (field === 'invoice_qty' || field === 'unit_price') recalcRow(e.target.closest('tr'));
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
            countEl.textContent = n;
            sharedWrap.classList.toggle('d-none', n === 0);
            emptyBox.classList.toggle('d-none', n > 0);

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
                    document.getElementById('rcvSelectedPoMeta').textContent =
                        [first.buyer_name, first.season_name, first.supplier_name].filter(Boolean).join(' · ') || '—';
                    selectedWrap.classList.remove('d-none');
                }
                refreshState();
            });
        } else {
            // Nothing selected yet — list the recent POs so the field stays as
            // browsable as the old dropdown was.
            runSearch();
        }
    })();
</script>
@endsection
