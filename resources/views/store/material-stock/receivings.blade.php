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
            @if($bookingPos->isEmpty())
                <p class="text-muted small mb-0">No Booking POs available to receive against.</p>
            @else
            <form method="POST" action="{{ route('store.material.receivings.store') }}" id="rcvForm">
                @csrf

                {{-- PO-level selector. The individual materials under the PO are
                     picked in the modal, because one PO covers several
                     styles/materials that live as separate BOM lines. --}}
                <div class="row g-3 align-items-end mb-3">
                    <div class="col-12 col-lg-8">
                        <label class="form-label fw-semibold">Booking PO Number <span class="text-danger">*</span></label>
                        <select name="booking_po_id" id="rcvPo" class="form-select" required>
                            <option value="">Select PO…</option>
                            @foreach($bookingPos as $po)
                                <option value="{{ $po->id }}" {{ old('booking_po_id')==$po->id?'selected':'' }}>
                                    {{ collect([$po->po_no, $po->buyer_name, $po->season_name, $po->vendor_name])->filter()->implode(' · ') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-lg-4">
                        <button type="button" class="btn btn-outline-primary w-100" id="rcvPickBtn" disabled
                                data-bs-toggle="modal" data-bs-target="#rcvItemsModal">
                            <i class="bi bi-list-check me-1"></i>Select Items
                        </button>
                    </div>
                </div>

                @error('rows')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

                <div id="rcvEmpty" class="border rounded-3 bg-body-secondary text-center text-muted py-5 mb-3">
                    <i class="bi bi-inbox d-block mb-2" style="font-size:24px;"></i>
                    Select a PO, then choose the items received in this delivery.
                </div>

                <div id="rcvRows"></div>

                <div class="d-flex justify-content-between align-items-center mt-3 d-none" id="rcvActions">
                    <span class="text-muted small"><span id="rcvCount">0</span> item(s) — one GRN will be generated per item.</span>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Save Receivings</button>
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

{{-- Item picker. Lists every material line under the selected PO. --}}
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
                <div id="rcvModalLoading" class="text-center text-muted py-5">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
                </div>
                <div id="rcvModalError" class="alert alert-warning d-none mb-0"></div>
                <div id="rcvModalWrap" class="table-responsive d-none" style="max-height:55vh;">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="sticky-top bg-body">
                            <tr class="text-muted small text-uppercase">
                                <th style="width:38px;">
                                    <input type="checkbox" class="form-check-input" id="rcvCheckAll"
                                           title="Select all available items" aria-label="Select all available items">
                                </th>
                                <th>Style Number</th>
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
                        <tbody id="rcvModalBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto small text-muted"><span id="rcvSelCount">0</span> selected</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="rcvAddSelected"><i class="bi bi-plus-lg me-1"></i>Add Selected</button>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const po = document.getElementById('rcvPo');
        if (!po) return;

        const pickBtn = document.getElementById('rcvPickBtn');
        const rowsWrap = document.getElementById('rcvRows');
        const emptyBox = document.getElementById('rcvEmpty');
        const actions = document.getElementById('rcvActions');
        const countEl = document.getElementById('rcvCount');
        const modalEl = document.getElementById('rcvItemsModal');
        const modalBody = document.getElementById('rcvModalBody');
        const modalWrap = document.getElementById('rcvModalWrap');
        const modalLoading = document.getElementById('rcvModalLoading');
        const modalError = document.getElementById('rcvModalError');
        const modalPo = document.getElementById('rcvModalPo');
        const checkAll = document.getElementById('rcvCheckAll');
        const selCount = document.getElementById('rcvSelCount');

        const ITEMS_URL = @json(route('store.material.receivings.po-items', ['bookingPo' => '__ID__']));
        const TODAY = @json(now()->toDateString());

        // Read-only identity shown on each row, in Receiving-sheet order.
        const AUTO_FIELDS = [
            ['supplier_name', 'Supplier Name'], ['season_name', 'Booking Season'],
            ['buyer_name', 'Buyer Name'], ['style_name', 'Style Number'],
            ['po_no', 'PO Number'], ['gmts_color_name', 'GMTS Color Name'],
            ['material_name', 'Material Name'], ['material_description', 'Material Description'],
            ['art_no', 'Art. No'], ['sap_code', 'SAP Code'],
            ['material_color', 'Material Color'], ['size', 'Size'],
            ['uom', 'Unit'], ['internal_po_qty', 'Internal PO Qty'],
        ];

        let items = [];          // items of the currently loaded PO
        let loadedPoId = null;   // which PO `items` belongs to
        let uid = 0;             // row name index (gaps are fine for rows.*)

        // esc() normalises a value for logic; h() escapes it for HTML/attribute
        // interpolation. BOM values come from uploaded workbooks, so anything
        // built into markup below must go through h()/dash().
        const esc = (v) => (v === null || v === undefined || v === '') ? '' : String(v);
        const h = (v) => esc(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const dash = (v) => esc(v) === '' ? '—' : h(v);

        function addedRowIds() {
            return Array.from(rowsWrap.querySelectorAll('[data-row-id]'))
                .map(el => String(el.dataset.rowId));
        }

        function refreshState() {
            const n = rowsWrap.children.length;
            countEl.textContent = n;
            emptyBox.classList.toggle('d-none', n > 0);
            actions.classList.toggle('d-none', n === 0);
        }

        // --- Item picker ------------------------------------------------------
        function loadItems() {
            modalLoading.classList.remove('d-none');
            modalWrap.classList.add('d-none');
            modalError.classList.add('d-none');
            modalBody.innerHTML = '';

            const poId = po.value;

            return fetch(ITEMS_URL.replace('__ID__', encodeURIComponent(poId)), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    // Ignore a stale response if the PO changed meanwhile.
                    if (po.value !== poId) return;
                    items = data.items || [];
                    loadedPoId = poId;
                    modalPo.textContent = 'PO ' + dash(data.po_no) + ' · ' + items.length + ' item(s)';
                    renderItems();
                })
                .catch(status => {
                    modalLoading.classList.add('d-none');
                    modalError.classList.remove('d-none');
                    modalError.textContent = status === 423
                        ? 'This file/style is locked. Stock entry is not allowed.'
                        : 'Could not load the items for this PO. Please try again.';
                });
        }

        function renderItems() {
            const already = addedRowIds();
            modalBody.innerHTML = '';

            items.forEach((item, i) => {
                const isAdded = already.includes(String(item.excel_row_id));
                const tr = document.createElement('tr');
                const cbId = 'rcvItem' + i;

                tr.innerHTML =
                    '<td><input type="checkbox" class="form-check-input rcv-item-cb" id="' + cbId + '"' +
                        ' value="' + h(item.excel_row_id) + '"' +
                        (isAdded ? ' checked disabled title="Already added below"' : '') +
                        ' aria-label="Select this material line"></td>' +
                    '<td><label for="' + cbId + '" class="mb-0 fw-semibold" style="cursor:pointer;">' + dash(item.style_name) + '</label></td>' +
                    '<td class="small">' + dash(item.material_name) + '</td>' +
                    '<td class="small">' + dash(item.material_description) + '</td>' +
                    '<td class="small">' + dash(item.gmts_color_name) + '</td>' +
                    '<td class="small">' + dash(item.art_no) + '</td>' +
                    '<td class="small">' + dash(item.sap_code) + '</td>' +
                    '<td class="small">' + dash(item.material_color) + '</td>' +
                    '<td class="small">' + dash(item.size) + '</td>' +
                    '<td class="small">' + dash(item.uom) + '</td>' +
                    '<td class="small text-end">' + dash(item.internal_po_qty) + '</td>';

                if (isAdded) {
                    tr.classList.add('opacity-50');
                }
                modalBody.appendChild(tr);
            });

            modalLoading.classList.add('d-none');
            modalWrap.classList.remove('d-none');
            updateSelCount();
        }

        function selectableBoxes() {
            return Array.from(modalBody.querySelectorAll('.rcv-item-cb:not(:disabled)'));
        }

        function updateSelCount() {
            selCount.textContent = selectableBoxes().filter(cb => cb.checked).length;
            const boxes = selectableBoxes();
            checkAll.disabled = boxes.length === 0;
            checkAll.checked = boxes.length > 0 && boxes.every(cb => cb.checked);
        }

        checkAll.addEventListener('change', function () {
            selectableBoxes().forEach(cb => { cb.checked = checkAll.checked; });
            updateSelCount();
        });

        modalBody.addEventListener('change', function (e) {
            if (e.target.classList.contains('rcv-item-cb')) updateSelCount();
        });

        modalEl.addEventListener('show.bs.modal', function () {
            // Re-fetch only when the PO changed; otherwise just refresh which
            // lines are already added so existing rows stay untouched.
            if (loadedPoId === po.value) {
                renderItems();
            } else {
                loadItems();
            }
        });

        document.getElementById('rcvAddSelected').addEventListener('click', function () {
            const chosen = selectableBoxes().filter(cb => cb.checked).map(cb => cb.value);
            chosen.forEach(rowId => {
                const item = items.find(it => String(it.excel_row_id) === String(rowId));
                if (item) addRow(item);
            });
            refreshState();
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        });

        // --- Rows -------------------------------------------------------------
        // New rows inherit Receive Date / Invoice No from the row above, so a
        // multi-item delivery is entered once and only adjusted where it differs
        // (e.g. a partial shipment on one line).
        function lastRowValue(field) {
            const rows = rowsWrap.children;
            if (!rows.length) return null;
            const el = rows[rows.length - 1].querySelector('[data-field="' + field + '"]');
            return el ? el.value : null;
        }

        function addRow(item, preset) {
            preset = preset || {};
            const i = uid++;
            const n = (field) => 'rows[' + i + '][' + field + ']';

            const receiveDate = preset.receive_date || lastRowValue('receive_date') || TODAY;
            const invoiceNo = preset.invoice_no !== undefined
                ? preset.invoice_no
                : (lastRowValue('invoice_no') || esc(item.suggested_invoice_no));
            const unitPrice = preset.unit_price !== undefined
                ? preset.unit_price
                : esc(item.suggested_unit_price);

            const auto = AUTO_FIELDS.map(([key, label]) =>
                '<div class="col-6 col-md-4 col-xl-3">' +
                    '<label class="form-label small text-muted mb-1">' + label + '</label>' +
                    '<input type="text" class="form-control-plaintext form-control-sm border rounded px-2 bg-light text-muted"' +
                           ' value="' + dash(item[key]) + '" readonly tabindex="-1">' +
                '</div>').join('');

            const row = document.createElement('div');
            row.className = 'border rounded-3 p-3 mb-3';
            row.dataset.rowId = item.excel_row_id;
            row.innerHTML =
                '<input type="hidden" name="' + n('excel_row_id') + '" value="' + h(item.excel_row_id) + '">' +
                '<div class="d-flex align-items-start justify-content-between gap-3 mb-3">' +
                    '<div>' +
                        '<span class="badge bg-primary-subtle text-primary mb-1">' + dash(item.style_name) + '</span>' +
                        '<div class="fw-semibold">' + dash(item.material_description) + '</div>' +
                        '<div class="small text-muted">' + [dash(item.material_color), dash(item.size)].join(' · ') + '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 rcv-remove"' +
                            ' title="Remove this item"><i class="bi bi-trash me-1"></i>Remove</button>' +
                '</div>' +
                '<div class="border rounded-3 bg-body-secondary p-3 mb-3">' +
                    '<div class="d-flex align-items-center justify-content-between mb-2">' +
                        '<span class="small fw-semibold text-uppercase text-muted"><i class="bi bi-magic me-1"></i>Auto-filled from BOM / PO</span>' +
                        '<span class="small text-muted">Read-only</span>' +
                    '</div>' +
                    '<div class="row g-3">' + auto + '</div>' +
                '</div>' +
                '<div class="row g-3">' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Source</label>' +
                        '<select name="' + n('source_type') + '" class="form-select">' +
                            '<option value="booking"' + (preset.source_type === 'internal_po' ? '' : ' selected') + '>Booking-wise</option>' +
                            '<option value="internal_po"' + (preset.source_type === 'internal_po' ? ' selected' : '') + '>Internal PO-wise</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Receive Date <span class="text-danger">*</span></label>' +
                        '<input type="date" name="' + n('receive_date') + '" data-field="receive_date" value="' + h(receiveDate) + '" class="form-control" required>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">GRN No</label>' +
                        '<input type="text" class="form-control bg-light text-muted" value="Auto-generated" readonly disabled>' +
                        '<div class="form-text text-muted"><i class="bi bi-magic me-1"></i>Auto-generated on save</div>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Invoice No</label>' +
                        '<input name="' + n('invoice_no') + '" data-field="invoice_no" value="' + h(invoiceNo) + '" class="form-control">' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Invoice Qty</label>' +
                        '<input type="number" step="0.0001" min="0" name="' + n('invoice_qty') + '" data-field="invoice_qty" value="' + h(preset.invoice_qty) + '" class="form-control">' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Physical Rcv Qty <span class="text-danger">*</span></label>' +
                        '<input type="number" step="0.0001" min="0" name="' + n('qty') + '" value="' + h(preset.qty) + '" class="form-control" required>' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Unit Price</label>' +
                        '<input type="number" step="0.0001" min="0" name="' + n('unit_price') + '" data-field="unit_price" value="' + h(unitPrice) + '" class="form-control">' +
                    '</div>' +
                    '<div class="col-12 col-sm-6 col-lg-3">' +
                        '<label class="form-label fw-semibold">Invoice Value</label>' +
                        '<input type="text" data-field="invoice_value" class="form-control bg-light text-muted" value="" placeholder="—" readonly tabindex="-1">' +
                        '<div class="form-text text-muted">Invoice Qty × Unit Price</div>' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label fw-semibold">Remarks</label>' +
                        '<textarea name="' + n('remarks') + '" rows="2" class="form-control" maxlength="1000">' + h(preset.remarks) + '</textarea>' +
                    '</div>' +
                '</div>';

            rowsWrap.appendChild(row);
            recalcRow(row);
            return row;
        }

        // Display only; the server recomputes this on save.
        function recalcRow(row) {
            const qty = parseFloat(row.querySelector('[data-field="invoice_qty"]').value);
            const price = parseFloat(row.querySelector('[data-field="unit_price"]').value);
            row.querySelector('[data-field="invoice_value"]').value =
                (isNaN(qty) || isNaN(price)) ? '' : (qty * price).toFixed(4);
        }

        rowsWrap.addEventListener('input', function (e) {
            const field = e.target.dataset.field;
            if (field === 'invoice_qty' || field === 'unit_price') {
                recalcRow(e.target.closest('[data-row-id]'));
            }
        });

        rowsWrap.addEventListener('click', function (e) {
            const btn = e.target.closest('.rcv-remove');
            if (!btn) return;
            btn.closest('[data-row-id]').remove();
            refreshState();
        });

        // --- PO selection -----------------------------------------------------
        po.addEventListener('change', function () {
            if (rowsWrap.children.length && loadedPoId !== po.value) {
                if (!confirm('Changing the PO will clear the items already added. Continue?')) {
                    po.value = loadedPoId;
                    return;
                }
                rowsWrap.innerHTML = '';
                refreshState();
            }
            pickBtn.disabled = !po.value;
        });

        pickBtn.disabled = !po.value;
        refreshState();

        // Rebuild the rows after a validation-error redirect so nothing typed is
        // lost (qty is required, so this path is hit in normal use).
        const oldRows = @json(old('rows', []));
        if (po.value && Object.keys(oldRows).length) {
            loadItems().then(() => {
                Object.values(oldRows).forEach(old => {
                    const item = items.find(it => String(it.excel_row_id) === String(old.excel_row_id));
                    if (item) addRow(item, old);
                });
                refreshState();
            });
        }
    })();
</script>
@endsection
