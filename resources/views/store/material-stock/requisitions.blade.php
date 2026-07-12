@extends('layouts.app')

@section('title', 'Material Stock — Requisitions')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-list-check"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Requisitions</h3>
                    <p class="app-hero-copy mb-0">Request → Approve → fulfilled by a Bulk Issue.</p>
                </div>
            </div>
            <a href="{{ route('store.material.bulk-issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1"></i>Bulk Issue</a>
        </div>
    </div>

    @include('store._flash')

    <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
        <div class="card-body p-4">
            <h5 class="mb-3">New Requisition</h5>
            @if($poGroups->isEmpty())
                <p class="text-muted small mb-0">No Booking POs available.</p>
            @else
            <form method="POST" action="{{ route('store.material.requisitions.store') }}" id="requisitionForm">
                @csrf

                <div class="row g-3 mb-3">
                    <div class="col-12 col-lg-4">
                        <label class="form-label fw-semibold">PO Number <span class="text-danger">*</span></label>
                        <select name="po_no" id="poSelect" class="form-select" required>
                            <option value="">Select PO…</option>
                            @foreach($poGroups as $g)
                                <option value="{{ $g['po_no'] }}" {{ old('po_no')==$g['po_no']?'selected':'' }}>
                                    {{ collect([$g['po_no'], $g['style_name'], $g['buyer_name']])->filter()->implode(' · ') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label fw-semibold">Req No</label>
                        <input name="requisition_no" value="{{ old('requisition_no') }}" class="form-control">
                    </div>
                    <div class="col-6 col-lg-6">
                        <label class="form-label fw-semibold">Remarks</label>
                        <input name="remarks" value="{{ old('remarks') }}" class="form-control" maxlength="1000">
                    </div>
                </div>

                {{-- PO-driven header (auto-filled, read-only) — Buyer / Season / Style / Colour --}}
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <label class="form-label fw-semibold text-muted small">Buyer</label>
                        <input type="text" id="hdrBuyer" class="form-control-plaintext border rounded px-2 bg-light" readonly value="—">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label fw-semibold text-muted small">Season</label>
                        <input type="text" id="hdrSeason" class="form-control-plaintext border rounded px-2 bg-light" readonly value="—">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label fw-semibold text-muted small">Style No</label>
                        <input type="text" id="hdrStyle" class="form-control-plaintext border rounded px-2 bg-light" readonly value="—">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label class="form-label fw-semibold text-muted small">Colour</label>
                        <input type="text" id="hdrColour" class="form-control-plaintext border rounded px-2 bg-light" readonly value="—">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="itemsTable">
                        <thead>
                            <tr class="text-muted small text-uppercase">
                                <th style="width:34px;">#</th>
                                <th>Item Description</th>
                                <th>Colour</th>
                                <th>Size</th>
                                <th class="text-end">Required</th>
                                <th style="min-width:200px;">Issued — Stock Item</th>
                                <th class="text-end" style="width:110px;">Issued Qty</th>
                                <th style="min-width:200px;">Received — Stock Item</th>
                                <th class="text-end" style="width:110px;">Received Qty</th>
                                <th style="min-width:140px;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            <tr id="itemsEmptyRow"><td colspan="10" class="text-center text-muted py-4">Select a PO to load its item list.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 text-end">
                    <button type="submit" class="btn btn-primary" id="submitBtn" disabled><i class="bi bi-plus-lg me-1"></i>Create Requisition</button>
                </div>
            </form>
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <h5 class="mb-3">Requisitions <span class="badge bg-primary-subtle text-primary ms-1">{{ $requisitions->total() }}</span></h5>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th>#</th><th>PO / Style</th><th>Season</th><th class="text-center">Items</th><th class="text-end">Issued Qty</th><th>Status</th><th>Requested</th><th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requisitions as $req)
                            @php $variant = ['pending'=>'warning','approved'=>'info','issued'=>'success'][$req->status] ?? 'secondary'; @endphp
                            <tr>
                                <td class="fw-semibold">#{{ $req->id }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $req->po_no ?: '—' }}</div>
                                    <div class="small text-muted">{{ $req->style_name }}@if($req->buyer_name) · {{ $req->buyer_name }}@endif</div>
                                </td>
                                <td class="small">{{ $req->season_name ?: '—' }}</td>
                                <td class="text-center">{{ $req->items_count }}</td>
                                <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$req->qty, 4), '0'), '.') }}</td>
                                <td><span class="badge bg-{{ $variant }}-subtle text-{{ $variant }}">{{ ucfirst($req->status) }}</span></td>
                                <td class="small text-muted">{{ optional($req->requestedBy)->name ?? '—' }}<br>{{ optional($req->requested_at)->format('d-M-Y') }}</td>
                                <td class="text-end text-nowrap">
                                    @if($req->status === 'pending')
                                        <form method="POST" action="{{ route('store.material.requisitions.approve', $req) }}" class="d-inline">
                                            @csrf @method('PATCH')
                                            <button class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="bi bi-check2 me-1"></i>Approve</button>
                                        </form>
                                    @endif
                                    @if($req->status !== 'issued')
                                        <form method="POST" action="{{ route('store.material.requisitions.destroy', $req) }}" class="d-inline" onsubmit="return confirm('Remove this requisition?');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-5">No requisitions yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $requisitions->links() }}</div>
        </div>
    </div>
</div>

@if(!$poGroups->isEmpty())
<script>
(function () {
    const PO_GROUPS = @json($poGroups);
    const STOCK_ITEMS = @json($stockItems);

    const numFmt = (v) => {
        const n = parseFloat(v);
        if (!isFinite(n)) return '';
        return (Math.round(n * 10000) / 10000).toString();
    };

    // Reusable <option> list for the stock item dropdowns.
    let stockOptions = '<option value="">Select item…</option>';
    STOCK_ITEMS.forEach(function (it) {
        const label = [it.name, it.code].filter(Boolean).join(' · ');
        stockOptions += '<option value="' + it.id + '">' + label.replace(/</g, '&lt;') + '</option>';
    });

    const groupByPo = {};
    PO_GROUPS.forEach(function (g) { groupByPo[g.po_no] = g; });

    const poSelect   = document.getElementById('poSelect');
    const body       = document.getElementById('itemsBody');
    const submitBtn  = document.getElementById('submitBtn');
    const hdrBuyer   = document.getElementById('hdrBuyer');
    const hdrSeason  = document.getElementById('hdrSeason');
    const hdrStyle   = document.getElementById('hdrStyle');
    const hdrColour  = document.getElementById('hdrColour');

    const esc = (s) => (s == null ? '' : String(s)).replace(/</g, '&lt;');

    function renderGroup(poNo) {
        const g = groupByPo[poNo];
        body.innerHTML = '';

        if (!g) {
            body.innerHTML = '<tr id="itemsEmptyRow"><td colspan="10" class="text-center text-muted py-4">Select a PO to load its item list.</td></tr>';
            hdrBuyer.value = hdrSeason.value = hdrStyle.value = hdrColour.value = '—';
            submitBtn.disabled = true;
            return;
        }

        hdrBuyer.value  = g.buyer_name  || '—';
        hdrSeason.value = g.season_name || '—';
        hdrStyle.value  = g.style_name  || '—';
        hdrColour.value = g.color       || '—';

        g.items.forEach(function (it, i) {
            const bpid = it.booking_po_id;
            const req  = numFmt(it.required_qty);
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td class="text-muted">' + (i + 1) + '</td>' +
                '<td class="small">' + esc(it.material_description || '—') + '</td>' +
                '<td class="small">' + esc(it.material_color || '—') + '</td>' +
                '<td class="small">' + esc(it.size || '—') + '</td>' +
                '<td class="text-end fw-semibold">' + (req || '0') + '</td>' +
                '<td><select name="items[' + bpid + '][issued_stock_item_id]" class="form-select form-select-sm js-issued-item">' + stockOptions + '</select></td>' +
                '<td><input type="number" step="0.0001" min="0" name="items[' + bpid + '][issued_qty]" value="' + req + '" class="form-control form-control-sm text-end js-issued-qty" data-required="' + req + '"></td>' +
                '<td><select name="items[' + bpid + '][received_stock_item_id]" class="form-select form-select-sm js-received-item">' + stockOptions + '</select></td>' +
                '<td><input type="number" step="0.0001" min="0" name="items[' + bpid + '][received_qty]" value="' + req + '" class="form-control form-control-sm text-end js-received-qty"></td>' +
                '<td><input type="text" name="items[' + bpid + '][remarks]" maxlength="1000" class="form-control form-control-sm"></td>';
            body.appendChild(tr);
        });

        submitBtn.disabled = false;
    }

    // Selecting an issued stock item defaults Issued Qty = Required (editable).
    // Selecting a received stock item defaults Received Qty = current Issued Qty.
    body.addEventListener('change', function (e) {
        const tr = e.target.closest('tr');
        if (!tr) return;

        if (e.target.classList.contains('js-issued-item') && e.target.value) {
            const qty = tr.querySelector('.js-issued-qty');
            qty.value = qty.dataset.required || '0';
            const recQty = tr.querySelector('.js-received-qty');
            if (recQty && tr.querySelector('.js-received-item').value === '') {
                recQty.value = qty.value;
            }
        }

        if (e.target.classList.contains('js-received-item') && e.target.value) {
            const recQty = tr.querySelector('.js-received-qty');
            const issuedQty = tr.querySelector('.js-issued-qty');
            recQty.value = issuedQty.value || recQty.dataset.required || '0';
        }
    });

    poSelect.addEventListener('change', function () { renderGroup(this.value); });

    // Restore after a validation redirect.
    if (poSelect.value) renderGroup(poSelect.value);
})();
</script>
@endif
@endsection
