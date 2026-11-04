@extends('layouts.app')

@section('title', 'General Stock — '.($cs ? 'Edit '.$cs->cs_no : 'New Comparative Statement'))

@php
    $max = $maxSuppliers;
    $suppliersByKey = collect($state['suppliers'])->mapWithKeys(fn ($s, $idx) => [(string) ($s['key'] ?? $idx) => $s]);

    // A new sheet starts with the minimum number of supplier columns open.
    $active = $suppliersByKey->isEmpty() ? range(0, max(1, $minSuppliers) - 1) : $suppliersByKey->keys()->map(fn ($k) => (int) $k)->all();

    $lines = array_values($state['lines']);
    $fmt = fn ($n) => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchasing'],
        ['label' => 'Comparative Statements', 'url' => route('store.purchasing.cs.index')],
        ['label' => $cs ? $cs->cs_no : 'New'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="columns-gap" eyebrow="General Stock Purchasing"
                   :title="$cs ? 'Edit '.$cs->cs_no : 'New Comparative Statement'"
                   copy="Enter each supplier's rate for every item, then choose the supplier for each item.">
        <x-slot:actions>
            <a href="{{ $cs ? route('store.purchasing.cs.show', $cs) : route('store.purchasing.queue') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @if($cs?->return_note)
        <div class="alert alert-warning">
            <strong>Sent back{{ $cs->returnedBy ? ' by '.$cs->returnedBy->name : '' }}:</strong> {{ $cs->return_note }}
        </div>
    @endif

    <form method="POST" id="csForm" novalidate
          action="{{ $cs ? route('store.purchasing.cs.update', $cs) : route('store.purchasing.cs.store') }}">
        @csrf
        @if($cs) @method('PUT') @endif

        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="csNo">CS No</label>
                        <input type="text" id="csNo" class="form-control" value="{{ $nextNo }}" readonly>
                        @unless($cs)<div class="form-text">Confirmed when you save.</div>@endunless
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="csDate">Date</label>
                        <input type="date" id="csDate" name="cs_date" class="form-control @error('cs_date') is-invalid @enderror" value="{{ $state['cs_date'] }}">
                        @error('cs_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Suppliers being compared. Up to $max column groups; a closed slot is hidden AND
             disabled, so it posts nothing. --}}
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5 class="mb-0">Suppliers compared</h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addSupplier">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add supplier
                    </button>
                </div>
                @error('suppliers')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror
                <div class="row g-3" id="supplierSlots">
                    @for($k = 0; $k < $max; $k++)
                        @php($s = $suppliersByKey[(string) $k] ?? [])
                        @php($on = in_array($k, $active, true))
                        <div class="col-12 col-md-6 col-xl-3" data-slot="{{ $k }}" @unless($on) hidden @endunless>
                            <div class="border rounded p-3 h-100">
                                <input type="hidden" name="suppliers[{{ $k }}][key]" value="{{ $k }}" @unless($on) disabled @endunless>
                                <input type="hidden" name="suppliers[{{ $k }}][general_stock_supplier_id]" value="{{ $s['general_stock_supplier_id'] ?? '' }}" data-f="supplier-id" @unless($on) disabled @endunless>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>Supplier {{ $k + 1 }}</strong>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-remove="{{ $k }}">
                                        <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Remove
                                    </button>
                                </div>
                                <label class="form-label small" for="pick{{ $k }}">From supplier list</label>
                                <select id="pick{{ $k }}" class="form-select form-select-sm mb-2" data-pick="{{ $k }}" @unless($on) disabled @endunless>
                                    <option value="">Choose a saved supplier</option>
                                    @foreach($savedSuppliers as $gs)
                                        <option value="{{ $gs->id }}" data-name="{{ $gs->name }}" data-address="{{ $gs->address }}" data-phone="{{ $gs->phone }}"
                                            @selected((string) ($s['general_stock_supplier_id'] ?? '') === (string) $gs->id)>{{ $gs->name }}</option>
                                    @endforeach
                                </select>
                                <label class="form-label small" for="sname{{ $k }}">Name</label>
                                <input type="text" id="sname{{ $k }}" name="suppliers[{{ $k }}][name]" value="{{ $s['name'] ?? '' }}"
                                       class="form-control form-control-sm mb-2 @error('suppliers.'.$k.'.name') is-invalid @enderror" data-f="name" @unless($on) disabled @endunless>
                                @error('suppliers.'.$k.'.name')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                                <label class="form-label small" for="sphone{{ $k }}">Phone</label>
                                <input type="text" id="sphone{{ $k }}" name="suppliers[{{ $k }}][phone]" value="{{ $s['phone'] ?? '' }}" class="form-control form-control-sm mb-2" data-f="phone" @unless($on) disabled @endunless>
                                <label class="form-label small" for="saddr{{ $k }}">Address</label>
                                <textarea id="saddr{{ $k }}" name="suppliers[{{ $k }}][address]" rows="2" class="form-control form-control-sm mb-2" data-f="address" @unless($on) disabled @endunless>{{ $s['address'] ?? '' }}</textarea>
                                <label class="form-label small" for="sterms{{ $k }}">Terms (VAT, credit)</label>
                                <textarea id="sterms{{ $k }}" name="suppliers[{{ $k }}][terms]" rows="2" class="form-control form-control-sm mb-2" @unless($on) disabled @endunless>{{ $s['terms'] ?? 'Excluding VAT. One month credit along with work order.' }}</textarea>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="recommended_supplier" id="rec{{ $k }}" value="{{ $k }}" @checked((string) ($state['recommended'] ?? '') === (string) $k) @unless($on) disabled @endunless>
                                    <label class="form-check-label small" for="rec{{ $k }}">Recommended supplier</label>
                                </div>
                            </div>
                        </div>
                    @endfor
                </div>
                <div class="form-text mt-2">At least {{ $minSuppliers }} suppliers are needed before the statement can be sent for checking.</div>
            </div>
        </div>

        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5 class="mb-0">Items and rates</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="pickLowest">
                            <i class="bi bi-arrow-down-circle me-1" aria-hidden="true"></i>Choose lowest price for every item
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="pickRecommended">
                            <i class="bi bi-hand-thumbs-up me-1" aria-hidden="true"></i>Choose recommended supplier
                        </button>
                    </div>
                </div>
                @error('lines')<div class="alert alert-danger py-2 small">{{ $message }}</div>@enderror

                <div class="table-responsive">
                    <table class="table align-middle gx-stock-table" id="csTable">
                        <thead>
                            <tr>
                                <th rowspan="2" style="min-width:40px;">#</th>
                                <th rowspan="2" style="min-width:220px;">Item</th>
                                <th rowspan="2" style="min-width:110px;">Qty</th>
                                <th rowspan="2" style="min-width:160px;">Specification / Brand &amp; Model</th>
                                <th rowspan="2" style="min-width:150px;">Current supplier</th>
                                @for($k = 0; $k < $max; $k++)
                                    <th colspan="3" class="text-center" data-slot="{{ $k }}" data-head="{{ $k }}" @unless(in_array($k, $active, true)) hidden @endunless>Supplier {{ $k + 1 }}</th>
                                @endfor
                                <th rowspan="2" style="min-width:150px;">Remarks</th>
                            </tr>
                            <tr>
                                @for($k = 0; $k < $max; $k++)
                                    <th data-slot="{{ $k }}" style="min-width:140px;" @unless(in_array($k, $active, true)) hidden @endunless>Spec / Model</th>
                                    <th data-slot="{{ $k }}" style="min-width:150px;" @unless(in_array($k, $active, true)) hidden @endunless>Unit price &middot; Choose</th>
                                    <th data-slot="{{ $k }}" class="text-end" style="min-width:100px;" @unless(in_array($k, $active, true)) hidden @endunless>Total</th>
                                @endfor
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lines as $i => $line)
                                <tr data-line="{{ $i }}">
                                    <td>{{ $i + 1 }}
                                        <input type="hidden" name="lines[{{ $i }}][purchase_requisition_item_id]" value="{{ $line['purchase_requisition_item_id'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][item_name]" value="{{ $line['item_name'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][uom]" value="{{ $line['uom'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][requisition_no]" value="{{ $line['requisition_no'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][last_rate]" value="{{ $line['last_rate'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][max_qty]" value="{{ $line['max_qty'] ?? '' }}">
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $line['item_name'] ?? '' }}</div>
                                        <div class="small text-muted">
                                            {{ $line['uom'] ?? '' }}
                                            @if(!empty($line['requisition_no'])) &middot; {{ $line['requisition_no'] }}@endif
                                            @if(isset($line['last_rate']) && $line['last_rate'] !== '' && $line['last_rate'] !== null) &middot; last rate {{ $fmt($line['last_rate']) }}@endif
                                        </div>
                                    </td>
                                    <td>
                                        <input type="number" step="any" min="0" name="lines[{{ $i }}][qty]" value="{{ $line['qty'] ?? '' }}"
                                               class="form-control form-control-sm text-end @error('lines.'.$i.'.qty') is-invalid @enderror" data-qty
                                               @if(!empty($line['max_qty'])) max="{{ $line['max_qty'] }}" @endif aria-label="Quantity of {{ $line['item_name'] ?? 'item' }}">
                                        @error('lines.'.$i.'.qty')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </td>
                                    <td><input type="text" name="lines[{{ $i }}][specification]" value="{{ $line['specification'] ?? '' }}" class="form-control form-control-sm" aria-label="Specification"></td>
                                    <td><input type="text" name="lines[{{ $i }}][current_supplier]" value="{{ $line['current_supplier'] ?? '' }}" class="form-control form-control-sm" aria-label="Current supplier"></td>
                                    @for($k = 0; $k < $max; $k++)
                                        @php($on = in_array($k, $active, true))
                                        @php($q = $line['quotes'][$k] ?? $line['quotes'][(string) $k] ?? [])
                                        <td data-slot="{{ $k }}" @unless($on) hidden @endunless>
                                            <input type="text" name="lines[{{ $i }}][quotes][{{ $k }}][spec]" value="{{ $q['spec'] ?? '' }}" class="form-control form-control-sm" aria-label="Supplier {{ $k + 1 }} specification" @unless($on) disabled @endunless>
                                        </td>
                                        <td data-slot="{{ $k }}" @unless($on) hidden @endunless>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">
                                                    <input class="form-check-input mt-0" type="radio" name="lines[{{ $i }}][selected]" value="{{ $k }}"
                                                           @checked((string) ($line['selected'] ?? '') === (string) $k) data-choose="{{ $k }}" aria-label="Choose supplier {{ $k + 1 }} for this item" @unless($on) disabled @endunless>
                                                </span>
                                                <input type="number" step="any" min="0" name="lines[{{ $i }}][quotes][{{ $k }}][price]" value="{{ $q['price'] ?? '' }}"
                                                       class="form-control text-end @error('lines.'.$i.'.quotes.'.$k.'.price') is-invalid @enderror" data-price="{{ $k }}" aria-label="Supplier {{ $k + 1 }} unit price" @unless($on) disabled @endunless>
                                            </div>
                                        </td>
                                        <td data-slot="{{ $k }}" class="text-end" data-total="{{ $k }}" @unless($on) hidden @endunless>—</td>
                                    @endfor
                                    <td><input type="text" name="lines[{{ $i }}][remarks]" value="{{ $line['remarks'] ?? '' }}" class="form-control form-control-sm" aria-label="Remarks"></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-semibold">
                                <td colspan="5" class="text-end">Total per supplier</td>
                                @for($k = 0; $k < $max; $k++)
                                    <td data-slot="{{ $k }}" @unless(in_array($k, $active, true)) hidden @endunless></td>
                                    <td data-slot="{{ $k }}" class="text-end" @unless(in_array($k, $active, true)) hidden @endunless>Total</td>
                                    <td data-slot="{{ $k }}" class="text-end" data-sum="{{ $k }}" @unless(in_array($k, $active, true)) hidden @endunless>0</td>
                                @endfor
                                <td></td>
                            </tr>
                            <tr class="fw-semibold">
                                <td colspan="5" class="text-end">Total of the suppliers chosen</td>
                                <td colspan="{{ $max * 3 + 1 }}"><span id="chosenTotal">0</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="form-text">The green price is the lowest on that item. Tick the circle beside the price of the supplier you choose.</div>
            </div>
        </div>

        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="recommendation">Recommendation from committee members</label>
                        <textarea id="recommendation" name="recommendation" rows="3" class="form-control" placeholder="Based on the terms and conditions, quality and lead time, we could go with ...">{{ $state['recommendation'] }}</textarea>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="3" class="form-control">{{ $state['remarks'] }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-5">
            <button type="submit" name="action" value="draft" class="btn btn-outline-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Save Draft
            </button>
            <button type="submit" name="action" value="submit" class="btn btn-primary">
                <i class="bi bi-send me-1" aria-hidden="true"></i>Save &amp; Send for Checking
            </button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var form = document.getElementById('csForm');
    var maxSlots = {{ $max }};

    function num(v) { var n = parseFloat(v); return isNaN(n) ? null : n; }
    function fmt(n) { return n.toLocaleString('en-US', { maximumFractionDigits: 2 }); }
    function slotOn(k) { var el = form.querySelector('[data-slot="' + k + '"]'); return el && !el.hidden; }
    function activeKeys() { var out = []; for (var k = 0; k < maxSlots; k++) if (slotOn(k)) out.push(k); return out; }

    function recalc() {
        var keys = activeKeys();
        var sums = {}, chosen = 0;
        keys.forEach(function (k) { sums[k] = 0; });

        form.querySelectorAll('tr[data-line]').forEach(function (row) {
            var qty = num(row.querySelector('[data-qty]').value) || 0;
            var lowest = null;
            keys.forEach(function (k) {
                var p = num(row.querySelector('[data-price="' + k + '"]').value);
                if (p !== null && (lowest === null || p < lowest)) lowest = p;
            });
            keys.forEach(function (k) {
                var input = row.querySelector('[data-price="' + k + '"]');
                var p = num(input.value);
                var cell = row.querySelector('[data-total="' + k + '"]');
                cell.textContent = p === null ? '—' : fmt(qty * p);
                if (p !== null) sums[k] += qty * p;
                input.classList.toggle('border-success', p !== null && p === lowest);
                input.classList.toggle('text-success', p !== null && p === lowest);
                var radio = row.querySelector('[data-choose="' + k + '"]');
                if (radio && radio.checked && p !== null) chosen += qty * p;
            });
        });

        keys.forEach(function (k) { form.querySelector('[data-sum="' + k + '"]').textContent = fmt(sums[k]); });
        document.getElementById('chosenTotal').textContent = fmt(chosen);
    }

    function lowestKey(row) {
        var best = null, bestKey = null;
        activeKeys().forEach(function (k) {
            var p = num(row.querySelector('[data-price="' + k + '"]').value);
            if (p !== null && (best === null || p < best)) { best = p; bestKey = k; }
        });
        return bestKey;
    }

    function choose(row, k) {
        var r = row.querySelector('[data-choose="' + k + '"]');
        if (r && !r.disabled) r.checked = true;
    }

    document.getElementById('pickLowest').addEventListener('click', function () {
        form.querySelectorAll('tr[data-line]').forEach(function (row) {
            var k = lowestKey(row);
            if (k !== null) choose(row, k);
        });
        recalc();
    });

    document.getElementById('pickRecommended').addEventListener('click', function () {
        var rec = form.querySelector('input[name="recommended_supplier"]:checked');
        if (!rec) return;
        form.querySelectorAll('tr[data-line]').forEach(function (row) {
            var p = num(row.querySelector('[data-price="' + rec.value + '"]').value);
            if (p !== null) choose(row, rec.value);
        });
        recalc();
    });

    // An item with no supplier chosen yet takes the lowest price as soon as it is typed.
    form.addEventListener('input', function (e) {
        if (e.target.matches('[data-price]')) {
            var row = e.target.closest('tr[data-line]');
            if (!row.querySelector('[data-choose]:checked')) {
                var k = lowestKey(row);
                if (k !== null) choose(row, k);
            }
        }
        if (e.target.matches('[data-price],[data-qty]')) recalc();
    });
    form.addEventListener('change', function (e) { if (e.target.matches('[data-choose]')) recalc(); });

    function setSlot(k, on) {
        form.querySelectorAll('[data-slot="' + k + '"]').forEach(function (el) {
            el.hidden = !on;
            el.querySelectorAll('input,select,textarea').forEach(function (f) { f.disabled = !on; });
        });
        if (!on) {
            form.querySelectorAll('[data-choose="' + k + '"]').forEach(function (r) { r.checked = false; });
            form.querySelectorAll('[data-price="' + k + '"]').forEach(function (p) { p.value = ''; });
            var rec = document.getElementById('rec' + k); if (rec) rec.checked = false;
        }
    }

    document.getElementById('addSupplier').addEventListener('click', function () {
        for (var k = 0; k < maxSlots; k++) { if (!slotOn(k)) { setSlot(k, true); recalc(); return; } }
    });
    form.querySelectorAll('[data-remove]').forEach(function (b) {
        b.addEventListener('click', function () { setSlot(parseInt(b.dataset.remove, 10), false); recalc(); });
    });

    // Picking a saved supplier fills the details; they stay editable.
    form.querySelectorAll('[data-pick]').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var k = sel.dataset.pick, opt = sel.options[sel.selectedIndex];
            var box = form.querySelector('[data-slot="' + k + '"]');
            box.querySelector('[data-f="supplier-id"]').value = sel.value;
            if (!sel.value) return;
            box.querySelector('[data-f="name"]').value = opt.dataset.name || '';
            box.querySelector('[data-f="phone"]').value = opt.dataset.phone || '';
            box.querySelector('[data-f="address"]').value = opt.dataset.address || '';
        });
    });

    recalc();
})();
</script>
@endsection
