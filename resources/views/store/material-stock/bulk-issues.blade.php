@extends('layouts.app')

@section('title', 'Material Stock — Bulk Issue')

{{-- Page-scoped helpers. Leans on the existing --gx-* tokens so Bulk Issue reads
     as one system with Receiving. Layout is a full-width single-column stack:
     Filter & Select → Summary → Indent + Quantities → History. --}}
@section('styles')
<style>
    /* Searchable PO picker (client-side over the already-loaded Booking POs). */
    .bi-search { position: relative; }
    .bi-search-panel {
        z-index: 1056;
        max-height: 320px;
        overflow-y: auto;
    }
    .bi-opt {
        cursor: pointer;
        border-left: 2px solid transparent;
        transition: background-color .15s ease, border-color .15s ease;
    }
    .bi-opt:hover { background: var(--gx-bg, #F8FAFC); }
    .bi-opt.active {
        background: var(--gx-secondary-bg, #DBEAFE);
        border-left-color: var(--gx-secondary, #3B82F6);
    }
    .bi-opt-primary { font-weight: 500; color: var(--gx-primary, #0F172A); line-height: 1.35; }
    .bi-opt-meta { font-size: .75rem; color: var(--gx-text-muted, #64748B); line-height: 1.35; }

    /* Selected value chip. */
    .bi-chip {
        display: inline-flex; align-items: center; gap: .5rem;
        background: var(--gx-secondary-bg, #DBEAFE);
        color: var(--gx-secondary-700, #1D4ED8);
        border-radius: 8px; padding: .35rem .5rem .35rem .75rem;
        font-weight: 500; max-width: 100%;
    }

    /* Read-only summary grid. */
    #biSummaryGrid { background: #F1F5F9; border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: var(--gx-radius-sm, 12px); }
    #biSummaryGrid .bi-sum-label { font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; color: #94A3B8; margin-bottom: .1rem; }
    #biSummaryGrid .bi-sum-value { font-weight: 600; color: var(--gx-primary, #0F172A); line-height: 1.3; overflow-wrap: anywhere; }

    /* Stock indicator chips. */
    .bi-stock-chip { display: inline-flex; align-items: center; gap: .5rem; border: 1px solid var(--bs-border-color, #E2E8F0); background: #fff; border-radius: 8px; padding: .4rem .75rem; font-size: .875rem; }
    .bi-stock-chip .dot { width: .625rem; height: .625rem; border-radius: 999px; }
    .bi-stock-chip .val { font-weight: 600; font-variant-numeric: tabular-nums; }

    /* Colour-coded quantity cards. */
    .bi-qty-card { border: 1px solid var(--bs-border-color, #E2E8F0); border-radius: 12px; padding: 1rem; height: 100%; }
    .bi-qty-card.bulk      { border-color: #A7F3D0; }
    .bi-qty-card.sample    { border-color: #BFDBFE; }
    .bi-qty-card.liability { border-color: #FDE68A; }
    .bi-qty-card.dead      { border-color: #FECACA; }
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

    @php
        // Compact per-PO index for the client-side searchable picker. Only fields
        // already loaded on $bookingPos + the running stock from $prefill — no
        // extra queries. SAP/GMTS/description are auto-filled from po-details on
        // selection (unchanged).
        $poOptions = $bookingPos->map(fn ($po) => [
            'id' => $po->id,
            'po_no' => (string) $po->po_no,
            'style' => (string) $po->style_name,
            'buyer' => (string) $po->buyer_name,
            'material' => (string) ($po->item_name ?: $po->description),
            'art_no' => (string) $po->supplier_article,
            'color' => (string) $po->color,
            'size' => (string) $po->size_width,
            'available' => (float) ($prefill[$po->id]['running'] ?? 0),
        ])->values();
    @endphp

    @if($bookingPos->isEmpty())
        <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4"><p class="text-muted small mb-0">No Booking POs available to issue against.</p></div>
        </div>
    @else
    <form method="POST" action="{{ route('store.material.bulk-issues.store') }}" id="biForm">
        @csrf
        <input type="hidden" name="booking_po_id" id="biPoId" value="{{ old('booking_po_id') }}" required>

        {{-- 1 — Filter & Select ------------------------------------------------ --}}
        <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="bi bi-search me-2" aria-hidden="true"></i>Filter &amp; Select</h5>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-3">
                        <label class="form-label fw-semibold" for="biFilterType">Filter by</label>
                        <select class="form-select" id="biFilterType">
                            <option value="po_no" selected>PO Number</option>
                            <option value="style">Style Number</option>
                            <option value="buyer">Buyer</option>
                            <option value="material">Material</option>
                            <option value="art_no">Art. No</option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-9">
                        <label class="form-label fw-semibold" for="biSearch" id="biSearchLabel">PO Number</label>
                        <div class="bi-search" id="biSearchWrap">
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                                <input type="text" class="form-control" id="biSearch" autocomplete="off"
                                       role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="biSearchList"
                                       placeholder="Click or type to search available POs…">
                            </div>
                            <div id="biSearchPanel" class="bi-search-panel d-none position-absolute w-100 mt-1 bg-body border rounded-3 shadow">
                                <div class="small text-muted px-3 py-2 border-bottom" id="biSearchHint"></div>
                                <div class="list-group list-group-flush" id="biSearchList" role="listbox"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Selected chip (shown after a pick). --}}
                <div id="biSelectedRow" class="mt-3 d-none">
                    <span class="bi-chip">
                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                        <span id="biSelectedText">—</span>
                        <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-decoration-none" id="biClear" title="Change selection" aria-label="Change selection">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="biChange"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Change Selection</button>
                </div>
            </div>
        </div>

        {{-- 2 — Selected PO / Material Summary --------------------------------- --}}
        <div class="card border-0 shadow-sm mb-4 d-none" id="biSummaryCard" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="bi bi-clipboard-data me-2" aria-hidden="true"></i>Selected PO / Material Summary <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">Read-only</span></h5>
                <div id="biSummaryGrid" class="p-3">
                    <div class="row g-3">
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Buyer</div><div class="bi-sum-value" id="biSumBuyer">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Season</div><div class="bi-sum-value" id="biSumSeason">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Style No</div><div class="bi-sum-value" id="biSumStyle">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">PO Number</div><div class="bi-sum-value" id="biSumPo">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Material Name</div><div class="bi-sum-value" id="biSumMaterialName">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Description</div><div class="bi-sum-value" id="biSumDesc">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Art. No</div><div class="bi-sum-value" id="biSumArtNo">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">SAP Code</div><div class="bi-sum-value" id="biSumSap">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">GMTS Color</div><div class="bi-sum-value" id="biSumGmtsColor">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Material Color</div><div class="bi-sum-value" id="biSumMatColor">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Size</div><div class="bi-sum-value" id="biSumSize">—</div></div>
                        <div class="col-6 col-md-3"><div class="bi-sum-label">Unit</div><div class="bi-sum-value" id="biSumUom">—</div></div>
                    </div>
                </div>

                {{-- Stock indicator. "Booked" is not tracked in this module, so the
                     live figures are Available (running) → This Issue → Remaining. --}}
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <span class="bi-stock-chip"><span class="dot" style="background:#10B981"></span>Available (Running): <span class="val" id="biStkAvailable">0</span></span>
                    <span class="bi-stock-chip"><span class="dot" style="background:#2563EB"></span>This Issue: <span class="val" id="biStkIssue">0</span></span>
                    <span class="bi-stock-chip" id="biStkRemainChip"><span class="dot" style="background:#94A3B8"></span>Remaining: <span class="val" id="biStkRemain">0</span></span>
                </div>
            </div>
        </div>

        {{-- 3 & 4 — Indent Info + Issue Quantities ----------------------------- --}}
        <div class="card border-0 shadow-sm mb-4 d-none" id="biEntryCard" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                @if($requisitions->isNotEmpty())
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fulfil Requisition <span class="text-muted small">(optional)</span></label>
                        <select name="material_requisition_id" class="form-select">
                            <option value="">None</option>
                            @foreach($requisitions as $req)
                                <option value="{{ $req->id }}">#{{ $req->id }} · {{ $req->po_no }} · {{ $req->material_description }} · {{ rtrim(rtrim(number_format((float)$req->qty,4),'0'),'.') }} ({{ ucfirst($req->status) }})</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <h6 class="text-uppercase text-muted fw-semibold small mb-3"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Indent Information</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">Indent Section</label>
                        <select name="indent_section" class="form-select">
                            <option value="">Select…</option>
                            @foreach($sections as $section)
                                <option value="{{ $section }}" {{ old('indent_section')==$section?'selected':'' }}>{{ $section }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">Indent Person</label>
                        <input name="indent_person" value="{{ old('indent_person') }}" class="form-control" maxlength="100">
                    </div>
                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="form-label fw-semibold">Requisition No</label>
                        <input name="requisition_number" value="{{ old('requisition_number') }}" class="form-control" maxlength="100">
                    </div>
                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="form-label fw-semibold">Issue Date <span class="text-danger">*</span></label>
                        <input type="date" name="issue_date" id="biIssueDate" value="{{ old('issue_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" class="form-control" required>
                    </div>
                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="form-label fw-semibold">Issue No</label>
                        <input name="issue_no" id="biIssueNo" value="{{ old('issue_no') }}" class="form-control" placeholder="Auto">
                        <div class="form-text"><i class="bi bi-magic me-1" aria-hidden="true"></i>Auto-suggested · editable</div>
                    </div>
                </div>

                <h6 class="text-uppercase text-muted fw-semibold small mb-3"><i class="bi bi-rulers me-1" aria-hidden="true"></i>Issue Quantities</h6>
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="bi-qty-card bulk">
                            <label class="form-label fw-semibold text-success">🟢 Bulk Qty</label>
                            <input type="number" step="0.0001" min="0" name="bulk_qty" id="biBulk" value="{{ old('bulk_qty') }}" placeholder="0" class="form-control bi-qty">
                            <div class="form-text text-primary d-none" id="biBulkHint"><i class="bi bi-magic me-1" aria-hidden="true"></i>from GMTS Order Qty · editable</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="bi-qty-card sample">
                            <label class="form-label fw-semibold text-primary">🔵 Sample Qty</label>
                            <input type="number" step="0.0001" min="0" name="sample_qty" id="biSample" value="{{ old('sample_qty') }}" placeholder="0" class="form-control bi-qty">
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="bi-qty-card liability">
                            <label class="form-label fw-semibold text-warning">🟠 Liability Qty</label>
                            <input type="number" step="0.0001" min="0" name="liability_qty" id="biLiability" value="{{ old('liability_qty') }}" placeholder="0" class="form-control bi-qty">
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="bi-qty-card dead">
                            <label class="form-label fw-semibold text-danger">🔴 Dead Qty</label>
                            <input type="number" step="0.0001" min="0" name="dead_qty" id="biDead" value="{{ old('dead_qty') }}" placeholder="0" class="form-control bi-qty">
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning py-2 px-3 small d-none" id="biOverWarn">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><span id="biOverText"></span>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Remarks</label>
                    <textarea name="remarks" rows="2" class="form-control" maxlength="1000">{{ old('remarks') }}</textarea>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-3">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Issue</button>
                    <span class="form-text mb-0"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Enter at least one of the four quantities. Liability &amp; Dead can later be reused (transfer to bulk) on the Closing Stock page.</span>
                </div>
            </div>
        </div>
    </form>
    @endif

    {{-- 5 — Bulk Issue History ------------------------------------------------ --}}
    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                <h5 class="mb-0">Bulk Issue History <span class="badge bg-primary-subtle text-primary ms-1">{{ $issues->total() }}</span></h5>
                <div class="position-relative" style="max-width:280px;">
                    <i class="bi bi-search position-absolute text-muted" style="left:.7rem;top:.65rem;" aria-hidden="true"></i>
                    <input type="text" id="biHistorySearch" class="form-control ps-4" placeholder="Filter this page…" aria-label="Filter history on this page">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="biHistoryTable">
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
                            <tr id="biHistoryEmpty"><td colspan="7" class="text-center text-muted py-5">No bulk issues recorded yet.</td></tr>
                        @endforelse
                        <tr id="biHistoryNoMatch" class="d-none"><td colspan="7" class="text-center text-muted py-4">No rows match your filter on this page.</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $issues->links() }}</div>
        </div>
    </div>
</div>

@if(!$bookingPos->isEmpty())
<script>
    (function () {
        const OPTIONS = @json($poOptions);
        const prefill = @json($prefill);
        const byId = {};
        OPTIONS.forEach(o => { byId[o.id] = o; });

        const poId = document.getElementById('biPoId');
        const form = document.getElementById('biForm');
        if (!poId || !form) return;

        // Cards revealed after a PO is chosen.
        const summaryCard = document.getElementById('biSummaryCard');
        const entryCard = document.getElementById('biEntryCard');

        // Search UI.
        const filterType = document.getElementById('biFilterType');
        const searchLabel = document.getElementById('biSearchLabel');
        const searchEl = document.getElementById('biSearch');
        const panel = document.getElementById('biSearchPanel');
        const list = document.getElementById('biSearchList');
        const hint = document.getElementById('biSearchHint');
        const selectedRow = document.getElementById('biSelectedRow');
        const selectedText = document.getElementById('biSelectedText');

        // Quantity + summary.
        const bulk = document.getElementById('biBulk');
        const bulkHint = document.getElementById('biBulkHint');
        const overWarn = document.getElementById('biOverWarn');
        const overText = document.getElementById('biOverText');
        const qtyEls = Array.from(document.querySelectorAll('.bi-qty'));
        const issueNoEl = document.getElementById('biIssueNo');

        const LABELS = { po_no: 'PO Number', style: 'Style Number', buyer: 'Buyer', material: 'Material', art_no: 'Art. No' };

        const esc = (v) => (v === null || v === undefined) ? '' : String(v);
        const h = (v) => esc(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const dash = (v) => esc(v).trim() === '' ? '—' : h(v);
        const fmt = (n) => (Math.round((Number(n) || 0) * 10000) / 10000).toString();

        // --- Summary (auto-fill via po-details) ------------------------------
        const summary = { card: summaryCard };
        const DETAILS_URL = @json(route('store.material.bulk-issues.po-details', ['bookingPo' => '__ID__']));
        const SUM_FIELDS = {
            buyer_name: 'biSumBuyer', season_name: 'biSumSeason', style_name: 'biSumStyle', po_no: 'biSumPo',
            material_name: 'biSumMaterialName', material_description: 'biSumDesc', gmts_color_name: 'biSumGmtsColor',
            art_no: 'biSumArtNo', sap_code: 'biSumSap', material_color: 'biSumMatColor', size: 'biSumSize', uom: 'biSumUom',
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

        function loadSummary(id) {
            const ticket = ++summaryTicket;
            setSummary(null);
            fetch(DETAILS_URL.replace('__ID__', encodeURIComponent(id)), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(data => { if (ticket === summaryTicket && String(poId.value) === String(id)) setSummary(data); })
                .catch(() => { if (ticket === summaryTicket) setSummary(null); });
        }

        // --- Stock indicator --------------------------------------------------
        const stkAvail = document.getElementById('biStkAvailable');
        const stkIssue = document.getElementById('biStkIssue');
        const stkRemain = document.getElementById('biStkRemain');
        const stkRemainChip = document.getElementById('biStkRemainChip');

        function currentRunning() {
            const d = prefill[poId.value];
            return d ? Number(d.running) || 0 : 0;
        }
        function total() { return qtyEls.reduce((s, el) => s + (parseFloat(el.value) || 0), 0); }

        function refreshStock() {
            const avail = currentRunning();
            const t = total();
            stkAvail.textContent = fmt(avail);
            stkIssue.textContent = fmt(t);
            stkRemain.textContent = fmt(avail - t);
            const over = t > avail + 1e-9;
            stkRemainChip.style.borderColor = over ? '#FCA5A5' : '';
            stkRemainChip.style.background = over ? '#FEF2F2' : '';
            return over;
        }

        function checkOver() {
            const over = refreshStock();
            if (poId.value && over) {
                overText.textContent = 'This issue (' + fmt(total()) + ') exceeds current available stock (' + fmt(currentRunning()) + '). You can still proceed if this is intentional.';
                overWarn.classList.remove('d-none');
            } else {
                overWarn.classList.add('d-none');
            }
            return over;
        }

        // --- Selection --------------------------------------------------------
        function suggestIssueNo() {
            if (issueNoEl && !issueNoEl.value) {
                const d = new Date();
                const ymd = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
                issueNoEl.value = 'BI-' + ymd + '-' + String(Math.floor(1000 + Math.random() * 9000));
            }
        }

        function selectPo(id) {
            const o = byId[id];
            if (!o) return;
            poId.value = id;

            selectedText.textContent = [o.po_no, o.material].filter(Boolean).join(' · ') || o.po_no;
            selectedRow.classList.remove('d-none');
            summaryCard.classList.remove('d-none');
            entryCard.classList.remove('d-none');
            closePanel();
            searchEl.value = '';

            loadSummary(id);

            // Suggest bulk_qty from GMTS Order Qty only when Bulk is still empty.
            const d = prefill[id] || {};
            if (d.gmts_order_qty && !bulk.value) {
                bulk.value = d.gmts_order_qty;
                bulkHint.classList.remove('d-none');
            }
            suggestIssueNo();
            checkOver();
            summaryCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function clearSelection() {
            poId.value = '';
            selectedRow.classList.add('d-none');
            summaryCard.classList.add('d-none');
            entryCard.classList.add('d-none');
            summaryTicket++;
            searchEl.focus();
        }

        document.getElementById('biClear').addEventListener('click', clearSelection);
        document.getElementById('biChange').addEventListener('click', clearSelection);

        // --- Searchable dropdown ---------------------------------------------
        let activeIndex = -1;

        function matches(term) {
            const field = filterType.value;
            const needle = term.trim().toLowerCase();
            if (needle === '') return OPTIONS;
            return OPTIONS.filter(o => esc(o[field]).toLowerCase().includes(needle));
        }

        function render(results, term) {
            activeIndex = -1;
            hint.textContent = term.trim() === ''
                ? 'All ' + LABELS[filterType.value] + 's (' + results.length + ')'
                : results.length + (results.length === 1 ? ' match' : ' matches');

            if (!results.length) {
                list.innerHTML = '<div class="list-group-item text-center text-muted py-3"><i class="bi bi-inbox d-block mb-1" style="font-size:18px;opacity:.5;" aria-hidden="true"></i><div class="small">No matching records' + (term ? ' for “' + h(term) + '”' : '') + '</div></div>';
                return;
            }

            list.innerHTML = results.map(o =>
                '<div class="list-group-item bi-opt" role="option" tabindex="-1" data-id="' + h(o.id) + '">' +
                    '<div class="d-flex justify-content-between align-items-start gap-2">' +
                        '<div class="bi-opt-primary">' + dash(o.po_no) + '</div>' +
                        '<span class="badge bg-success-subtle text-success text-nowrap">Avail: ' + fmt(o.available) + '</span>' +
                    '</div>' +
                    '<div class="bi-opt-meta">' + [dash(o.style), dash(o.buyer), dash(o.material)].join(' · ') + '</div>' +
                '</div>'
            ).join('');
        }

        function openPanel() { panel.classList.remove('d-none'); searchEl.setAttribute('aria-expanded', 'true'); }
        function closePanel() { panel.classList.add('d-none'); searchEl.setAttribute('aria-expanded', 'false'); activeIndex = -1; }

        function showResults() { render(matches(searchEl.value), searchEl.value); openPanel(); }

        filterType.addEventListener('change', function () {
            const label = LABELS[filterType.value];
            searchLabel.textContent = label;
            searchEl.placeholder = 'Click or type to search ' + label + '…';
            searchEl.value = '';
            showResults();
        });

        searchEl.addEventListener('focus', showResults);
        searchEl.addEventListener('click', showResults);
        searchEl.addEventListener('input', showResults);

        const opts = () => Array.from(list.querySelectorAll('.bi-opt'));
        function highlight(i) {
            const els = opts();
            if (!els.length) return;
            activeIndex = (i + els.length) % els.length;
            els.forEach((el, idx) => el.classList.toggle('active', idx === activeIndex));
            els[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        searchEl.addEventListener('keydown', function (e) {
            const open = !panel.classList.contains('d-none');
            if (e.key === 'Escape') return closePanel();
            if (e.key === 'ArrowDown' && open) { e.preventDefault(); return highlight(activeIndex + 1); }
            if (e.key === 'ArrowUp' && open) { e.preventDefault(); return highlight(activeIndex - 1); }
            if (e.key === 'Enter') {
                e.preventDefault();
                const els = opts();
                if (!open || !els.length) return;
                selectPo((activeIndex >= 0 ? els[activeIndex] : els[0]).dataset.id);
            }
        });

        list.addEventListener('click', function (e) {
            const opt = e.target.closest('.bi-opt');
            if (opt) selectPo(opt.dataset.id);
        });

        document.addEventListener('click', function (e) {
            if (!document.getElementById('biSearchWrap').contains(e.target)) closePanel();
        });

        // --- Quantities / submit ---------------------------------------------
        qtyEls.forEach(el => el.addEventListener('input', checkOver));

        form.addEventListener('submit', function (e) {
            if (checkOver()) {
                if (!window.confirm('This issue exceeds available stock (' + fmt(currentRunning()) + '). Proceed anyway?')) {
                    e.preventDefault();
                }
            }
        });

        // Restore after a validation redirect (old booking_po_id present).
        if (poId.value && byId[poId.value]) {
            selectPo(poId.value);
        }
    })();

    // --- History client-side filter (current page) ---------------------------
    (function () {
        const input = document.getElementById('biHistorySearch');
        const table = document.getElementById('biHistoryTable');
        if (!input || !table) return;
        const noMatch = document.getElementById('biHistoryNoMatch');
        const emptyRow = document.getElementById('biHistoryEmpty');

        input.addEventListener('input', function () {
            const q = input.value.trim().toLowerCase();
            const rows = Array.from(table.querySelectorAll('tbody tr')).filter(r => r.id !== 'biHistoryNoMatch' && r.id !== 'biHistoryEmpty');
            let shown = 0;
            rows.forEach(function (r) {
                const hit = r.textContent.toLowerCase().includes(q);
                r.classList.toggle('d-none', !hit);
                if (hit) shown++;
            });
            if (noMatch) noMatch.classList.toggle('d-none', !(q !== '' && shown === 0 && !emptyRow));
        });
    })();
</script>
@endif
@endsection
