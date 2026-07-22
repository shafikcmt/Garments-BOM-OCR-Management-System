@extends('layouts.app')

@section('title', 'Material Stock — Bulk Issue')

{{-- Page-scoped helpers for the read-only PO / Material summary. Leans on the
     existing --gx-* tokens so Bulk Issue reads as one system with Receiving. --}}
@section('styles')
<style>
    #biSummary {
        background: #F1F5F9;
        border: 1px solid var(--bs-border-color, #E2E8F0);
        border-radius: var(--gx-radius-sm, 12px);
    }
    #biSummary .bi-sum-label {
        font-size: .6875rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #94A3B8;
        margin-bottom: .1rem;
    }
    #biSummary .bi-sum-value {
        font-weight: 600;
        color: var(--gx-primary, #0F172A);
        line-height: 1.3;
        overflow-wrap: anywhere;
    }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'Buyer / Style Stock'],
        ['label' => 'Bulk Issuing'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Bulk Issuing</h3>
                    <p class="app-hero-copy mb-0">Each issue splits into Bulk / Sample / Liability / Dead.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.material.receivings.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receiving</a>
                <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Closing Stock</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Record Bulk Issue</h5>
                    @if($bookingPos->isEmpty())
                        <p class="text-muted small">No Booking POs available to issue against.</p>
                    @else
                    <form method="POST" action="{{ route('store.material.bulk-issues.store') }}" id="biForm">
                        @csrf
                        <label class="form-label fw-semibold">Booking PO / Material <span class="text-danger">*</span></label>
                        <select name="booking_po_id" id="biPo" class="form-select mb-2" required>
                            <option value="">Select PO…</option>
                            @foreach($bookingPos as $po)
                                <option value="{{ $po->id }}" {{ old('booking_po_id')==$po->id?'selected':'' }}>
                                    {{ collect([$po->po_no, $po->style_name, $po->item_name ?: $po->description, $po->color, $po->size_width])->filter()->implode(' · ') }}
                                </option>
                            @endforeach
                        </select>
                        <div id="biStockRow" class="small mb-3 d-none">
                            <span class="badge bg-success-subtle text-success"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Available (Running) stock: <span id="biRunning" class="fw-bold">0</span></span>
                        </div>

                        {{-- Read-only identity from the selected PO / BOM row. Auto-filled,
                             never typed — same values that get stored on the issue. --}}
                        <div id="biSummary" class="p-3 mb-3 d-none">
                            <div class="small fw-semibold text-uppercase text-muted mb-2">
                                <i class="bi bi-magic me-1" aria-hidden="true"></i>PO / Material Summary <span class="text-muted">— read-only</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-6"><div class="bi-sum-label">Buyer</div><div class="bi-sum-value" id="biSumBuyer">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Season</div><div class="bi-sum-value" id="biSumSeason">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Style No</div><div class="bi-sum-value" id="biSumStyle">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">PO Number</div><div class="bi-sum-value" id="biSumPo">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Material Name</div><div class="bi-sum-value" id="biSumMaterialName">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Description</div><div class="bi-sum-value" id="biSumDesc">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">GMTS Color</div><div class="bi-sum-value" id="biSumGmtsColor">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Art. No</div><div class="bi-sum-value" id="biSumArtNo">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">SAP Code</div><div class="bi-sum-value" id="biSumSap">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Material Color</div><div class="bi-sum-value" id="biSumMatColor">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Size</div><div class="bi-sum-value" id="biSumSize">—</div></div>
                                <div class="col-6"><div class="bi-sum-label">Unit</div><div class="bi-sum-value" id="biSumUom">—</div></div>
                            </div>
                        </div>

                        @if($requisitions->isNotEmpty())
                            <label class="form-label fw-semibold">Fulfil Requisition <span class="text-muted small">(optional)</span></label>
                            <select name="material_requisition_id" class="form-select mb-3">
                                <option value="">None</option>
                                @foreach($requisitions as $req)
                                    <option value="{{ $req->id }}">#{{ $req->id }} · {{ $req->po_no }} · {{ $req->material_description }} · {{ rtrim(rtrim(number_format((float)$req->qty,4),'0'),'.') }} ({{ ucfirst($req->status) }})</option>
                                @endforeach
                            </select>
                        @endif

                        {{-- Indent header (Excel "Bulk Issuing" register). Season is
                             shown read-only above (it follows the PO), so it is not
                             repeated here as a manual field. --}}
                        <div class="border rounded-3 bg-body-secondary p-3 mb-3">
                            <div class="small fw-semibold text-uppercase text-muted mb-2">
                                <i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Indent Info
                            </div>
                            <div class="row g-2">
                                <div class="col-12 col-sm-4">
                                    <label class="form-label fw-semibold">Indent Section</label>
                                    <select name="indent_section" class="form-select">
                                        <option value="">Select…</option>
                                        @foreach($sections as $section)
                                            <option value="{{ $section }}" {{ old('indent_section')==$section?'selected':'' }}>{{ $section }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <label class="form-label fw-semibold">Indent Person</label>
                                    <input name="indent_person" value="{{ old('indent_person') }}" class="form-control" maxlength="100">
                                </div>
                                <div class="col-12 col-sm-4">
                                    <label class="form-label fw-semibold">Requisition No</label>
                                    <input name="requisition_number" value="{{ old('requisition_number') }}" class="form-control" maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Issue No</label><input name="issue_no" value="{{ old('issue_no') }}" class="form-control"></div>
                            <div class="col-6"><label class="form-label fw-semibold">Date <span class="text-danger">*</span></label><input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" class="form-control" required></div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold text-success">Bulk Qty</label><input type="number" step="0.0001" min="0" name="bulk_qty" id="biBulk" value="{{ old('bulk_qty') }}" class="form-control bi-qty"><div class="form-text text-primary d-none" id="biBulkHint"><i class="bi bi-magic me-1" aria-hidden="true"></i>from GMTS Order Qty · editable</div></div>
                            <div class="col-6"><label class="form-label fw-semibold text-primary">Sample Qty</label><input type="number" step="0.0001" min="0" name="sample_qty" id="biSample" value="{{ old('sample_qty') }}" class="form-control bi-qty"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold text-warning">Liability Qty</label><input type="number" step="0.0001" min="0" name="liability_qty" id="biLiability" value="{{ old('liability_qty') }}" class="form-control bi-qty"></div>
                            <div class="col-6"><label class="form-label fw-semibold text-danger">Dead Qty</label><input type="number" step="0.0001" min="0" name="dead_qty" id="biDead" value="{{ old('dead_qty') }}" class="form-control bi-qty"></div>
                        </div>
                        <div class="alert alert-warning py-2 px-3 small d-none" id="biOverWarn">
                            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><span id="biOverText"></span>
                        </div>
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="1000">{{ old('remarks') }}</textarea>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Bulk Issue</button>
                        <div class="form-text">Enter at least one of the four quantities. Liability &amp; Dead can later be reused (transfer to bulk) on the Closing Stock page.</div>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Bulk Issue History <span class="badge bg-primary-subtle text-primary ms-1">{{ $issues->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Date</th><th>PO / Material</th>
                                    <th class="text-end">Bulk</th><th class="text-end">Sample</th><th class="text-end">Liab.</th><th class="text-end">Dead</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($issues as $i)
                                    <tr>
                                        <td class="small">{{ optional($i->issue_date)->format('d-M-Y') ?? '—' }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ $i->po_no }} · {{ $i->material_name ?: $i->material_description }}</div>
                                            {{-- Buyer / Style / colour / size on one quiet line; Section
                                                 as a chip so it reads as an attribute, not free text. --}}
                                            <div class="small text-muted">{{ collect([$i->buyer_name, $i->style_name, $i->material_color, $i->size])->filter()->implode(' · ') }}</div>
                                            @if($i->indent_section)
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis mt-1"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>{{ $i->indent_section }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-success">{{ rtrim(rtrim(number_format((float)$i->bulk_qty, 4), '0'), '.') }}</td>
                                        <td class="text-end text-primary">{{ rtrim(rtrim(number_format((float)$i->sample_qty, 4), '0'), '.') }}</td>
                                        <td class="text-end text-warning">{{ rtrim(rtrim(number_format((float)$i->liability_qty, 4), '0'), '.') }}</td>
                                        <td class="text-end text-danger">{{ rtrim(rtrim(number_format((float)$i->dead_qty, 4), '0'), '.') }}</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('store.material.bulk-issues.destroy', $i) }}" onsubmit="return confirm('Remove this bulk issue? Closing stock will update.');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3" aria-label="Delete this entry" title="Delete"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-5">No bulk issues recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $issues->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const prefill = @json($prefill);
        const po = document.getElementById('biPo');
        if (!po) return;

        const stockRow = document.getElementById('biStockRow');
        const runningEl = document.getElementById('biRunning');
        const bulk = document.getElementById('biBulk');
        const bulkHint = document.getElementById('biBulkHint');
        const overWarn = document.getElementById('biOverWarn');
        const overText = document.getElementById('biOverText');
        const form = document.getElementById('biForm');
        const qtyEls = Array.from(document.querySelectorAll('.bi-qty'));

        // --- Read-only PO / Material summary ---------------------------------
        const summary = document.getElementById('biSummary');
        const DETAILS_URL = @json(route('store.material.bulk-issues.po-details', ['bookingPo' => '__ID__']));
        // Map response keys -> summary element ids.
        const SUM_FIELDS = {
            buyer_name: 'biSumBuyer',
            season_name: 'biSumSeason',
            style_name: 'biSumStyle',
            po_no: 'biSumPo',
            material_name: 'biSumMaterialName',
            material_description: 'biSumDesc',
            gmts_color_name: 'biSumGmtsColor',
            art_no: 'biSumArtNo',
            sap_code: 'biSumSap',
            material_color: 'biSumMatColor',
            size: 'biSumSize',
            uom: 'biSumUom',
        };
        let summaryTicket = 0;

        function setSummary(data) {
            Object.keys(SUM_FIELDS).forEach(function (key) {
                const el = document.getElementById(SUM_FIELDS[key]);
                if (!el) return;
                const v = data ? data[key] : null;
                el.textContent = (v === null || v === undefined || String(v).trim() === '') ? '—' : String(v);
            });
        }

        function loadSummary(poId) {
            const ticket = ++summaryTicket;
            summary.classList.remove('d-none');
            setSummary(null);

            fetch(DETAILS_URL.replace('__ID__', encodeURIComponent(poId)), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => {
                    if (ticket !== summaryTicket || String(po.value) !== String(poId)) return;
                    setSummary(data);
                })
                .catch(() => {
                    if (ticket !== summaryTicket) return;
                    setSummary(null);   // leave the card visible with dashes
                });
        }

        function currentRunning() {
            const d = prefill[po.value];
            return d ? Number(d.running) || 0 : 0;
        }

        function fmt(n) {
            return (Math.round(n * 10000) / 10000).toString();
        }

        function onPoChange() {
            const d = prefill[po.value] || {};

            if (po.value && d.running !== undefined) {
                runningEl.textContent = fmt(Number(d.running) || 0);
                stockRow.classList.remove('d-none');
            } else {
                stockRow.classList.add('d-none');
            }

            if (po.value) {
                loadSummary(po.value);
            } else {
                summary.classList.add('d-none');
                summaryTicket++;
            }

            // Suggest bulk_qty from GMTS Order Qty only when Bulk is still empty.
            if (d.gmts_order_qty && !bulk.value) {
                bulk.value = d.gmts_order_qty;
                bulkHint.classList.remove('d-none');
            }
            checkOver();
        }

        function total() {
            return qtyEls.reduce((sum, el) => sum + (parseFloat(el.value) || 0), 0);
        }

        function checkOver() {
            const running = currentRunning();
            const t = total();
            if (po.value && t > running + 1e-9) {
                overText.textContent = 'This issue (' + fmt(t) + ') exceeds current available stock ('
                    + fmt(running) + '). You can still proceed if this is intentional.';
                overWarn.classList.remove('d-none');
                return true;
            }
            overWarn.classList.add('d-none');
            return false;
        }

        po.addEventListener('change', onPoChange);
        qtyEls.forEach(el => el.addEventListener('input', checkOver));

        // Soft confirm on submit — never a hard block.
        form.addEventListener('submit', function (e) {
            if (checkOver()) {
                const running = currentRunning();
                if (!window.confirm('This issue exceeds available stock (' + fmt(running)
                    + '). Proceed anyway?')) {
                    e.preventDefault();
                }
            }
        });

        if (po.value) onPoChange();
    })();
</script>
@endsection
