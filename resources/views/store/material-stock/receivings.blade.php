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

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Record Receiving</h5>
                    @if($bookingPos->isEmpty())
                        <p class="text-muted small">No Booking POs available to receive against.</p>
                    @else
                    <form method="POST" action="{{ route('store.material.receivings.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Booking PO / Material <span class="text-danger">*</span></label>
                        <select name="booking_po_id" id="rcvPo" class="form-select mb-2" required>
                            <option value="">Select PO…</option>
                            @foreach($bookingPos as $po)
                                <option value="{{ $po->id }}" {{ old('booking_po_id')==$po->id?'selected':'' }}>
                                    {{ collect([$po->po_no, $po->style_name, $po->item_name ?: $po->description, $po->color, $po->size_width])->filter()->implode(' · ') }}
                                </option>
                            @endforeach
                        </select>
                        <div id="rcvVendorRow" class="small text-muted mb-3 d-none">
                            <i class="bi bi-building me-1"></i>Vendor (from BOM): <span id="rcvVendor" class="fw-semibold text-slate-900">—</span>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Source</label>
                                <select name="source_type" class="form-select">
                                    <option value="booking" {{ old('source_type')=='booking'?'selected':'' }}>Booking-wise</option>
                                    <option value="internal_po" {{ old('source_type')=='internal_po'?'selected':'' }}>Internal PO-wise</option>
                                </select>
                            </div>
                            <div class="col-6"><label class="form-label fw-semibold">Date <span class="text-danger">*</span></label><input type="date" name="receive_date" value="{{ old('receive_date', now()->toDateString()) }}" class="form-control" required></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">GRN No</label>
                                <input type="text" class="form-control bg-light text-muted" value="Auto-generated" readonly disabled>
                                <div class="form-text text-muted"><i class="bi bi-magic me-1"></i>Auto-generated on save</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Invoice No</label>
                                <input name="invoice_no" id="rcvInvoiceNo" value="{{ old('invoice_no') }}" class="form-control">
                                <div class="form-text text-primary d-none" id="rcvInvoiceHint"><i class="bi bi-magic me-1"></i>from BOM · editable</div>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Qty <span class="text-danger">*</span></label><input type="number" step="0.0001" min="0" name="qty" value="{{ old('qty') }}" class="form-control" required></div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Unit Price</label>
                                <input type="number" step="0.0001" min="0" name="unit_price" id="rcvUnitPrice" value="{{ old('unit_price') }}" class="form-control">
                                <div class="form-text text-primary d-none" id="rcvUnitPriceHint"><i class="bi bi-magic me-1"></i>from BOM · editable</div>
                            </div>
                        </div>
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="1000">{{ old('remarks') }}</textarea>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add Receiving</button>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Receiving History <span class="badge bg-primary-subtle text-primary ms-1">{{ $receivings->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Date</th><th>GRN No</th><th>PO / Material</th><th>Source</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($receivings as $r)
                                    <tr>
                                        <td class="small">{{ optional($r->receive_date)->format('d-M-Y') ?? '—' }}</td>
                                        <td class="small"><span class="fw-semibold text-nowrap">{{ $r->grn_no ?: '—' }}</span></td>
                                        <td>
                                            <div class="fw-semibold">{{ $r->po_no }} · {{ $r->material_description }}</div>
                                            <div class="small text-muted">{{ collect([$r->buyer_name.' / '.$r->style_name, $r->material_color, $r->size])->filter()->implode(' · ') }}</div>
                                        </td>
                                        <td><span class="badge bg-info-subtle text-info">{{ $r->source_type=='internal_po' ? 'Internal PO' : 'Booking' }}</span></td>
                                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$r->qty, 4), '0'), '.') }}</td>
                                        <td class="text-end small">{{ $r->unit_price !== null ? number_format((float)$r->unit_price, 4) : '—' }}</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('store.material.receivings.destroy', $r) }}" onsubmit="return confirm('Remove this receiving? Closing stock will update.');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-5">No receiving recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $receivings->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        // Editable suggested defaults from the linked BOM row (entered earlier by
        // Supply Chain / Merchant). Store may override any of them.
        const prefill = @json($prefill);
        const po = document.getElementById('rcvPo');
        if (!po) return;

        const invoiceEl = document.getElementById('rcvInvoiceNo');
        const invoiceHint = document.getElementById('rcvInvoiceHint');
        const priceEl = document.getElementById('rcvUnitPrice');
        const priceHint = document.getElementById('rcvUnitPriceHint');
        const vendorRow = document.getElementById('rcvVendorRow');
        const vendorEl = document.getElementById('rcvVendor');

        function apply(force) {
            const data = prefill[po.value] || {};

            // Vendor is display-only.
            if (data.vendor_name) {
                vendorEl.textContent = data.vendor_name;
                vendorRow.classList.remove('d-none');
            } else {
                vendorRow.classList.add('d-none');
            }

            // Only fill when empty (don't clobber what the user already typed),
            // unless force = true (initial page load with a preselected PO).
            fill(invoiceEl, invoiceHint, data.invoice_no, force);
            fill(priceEl, priceHint, data.unit_price, force);
        }

        function fill(el, hint, value, force) {
            if (value && (force || !el.value)) {
                el.value = value;
                hint.classList.remove('d-none');
            } else if (!value) {
                hint.classList.add('d-none');
            }
        }

        po.addEventListener('change', function () { apply(false); });
        // Re-apply on load so a validation-error redirect keeps the suggestion.
        if (po.value) apply(false);
    })();
</script>
@endsection
