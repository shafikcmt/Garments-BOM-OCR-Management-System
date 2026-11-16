@extends('layouts.app')

@section('title', 'General Stock — '.($cs ? 'Edit '.$cs->cs_no : 'New Comparative Statement'))

@php
    $max = $maxSuppliers;
    $suppliersByKey = collect($state['suppliers'])->mapWithKeys(fn ($s, $idx) => [(string) ($s['key'] ?? $idx) => $s]);

    // A new sheet starts with the minimum number of supplier columns open.
    $active = $suppliersByKey->isEmpty() ? range(0, max(1, $minSuppliers) - 1) : $suppliersByKey->keys()->map(fn ($k) => (int) $k)->all();

    $lines = array_values($state['lines']);
    $categoryNames = collect($lines)->pluck('category')->filter()->unique()->values();
    $totalCols = 5 + ($max * 3) + 1;
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
        {{-- The item grid is sent as this one field (see ComparativeStatementController::unpackLines). --}}
        <input type="hidden" name="lines_json" id="linesJson" value="">

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

                <div class="row g-2 align-items-end mb-2" id="gridTools">
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1" for="rowSearch">Find an item</label>
                        <input type="search" id="rowSearch" class="form-control form-control-sm" placeholder="Type part of an item name">
                    </div>
                    @if($categoryNames->count() > 1)
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1" for="catFilter">Category</label>
                            <select id="catFilter" class="form-select form-select-sm">
                                <option value="">All categories</option>
                                @foreach($categoryNames as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="viewFilter">Show</label>
                        <select id="viewFilter" class="form-select form-select-sm">
                            <option value="">All items</option>
                            <option value="unpriced">Items still missing a price</option>
                            <option value="nochoice">Items with no supplier chosen</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="copyList">
                            <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy item list
                        </button>
                    </div>
                    <div class="col-12 small text-muted" id="progress" aria-live="polite"></div>
                </div>
                <div class="form-text mb-2">
                    <i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>
                    Many prices? Press <strong>Copy item list</strong> and paste it into Excel to send suppliers the items in this order. When their quotation comes back in the same order, copy the price column from the supplier's Excel quotation (same item order), click the first price box under that supplier and paste (Ctrl+V). Copy two columns to fill two suppliers at once. Press Enter to move down to the next item.
                </div>
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
                                @if(filled($line['category'] ?? null) && ($i === 0 || ($lines[$i - 1]['category'] ?? null) !== $line['category']))
                                    <tr class="table-light" data-cat-row="{{ $line['category'] }}">
                                        <td colspan="{{ $totalCols }}" class="fw-semibold"><i class="bi bi-tags text-primary me-1" aria-hidden="true"></i>{{ $line['category'] }}</td>
                                    </tr>
                                @endif
                                <tr data-line="{{ $i }}" data-name="{{ mb_strtolower($line['item_name'] ?? '') }}" data-cat="{{ $line['category'] ?? '' }}">
                                    <td>{{ $i + 1 }}
                                        <input type="hidden" name="lines[{{ $i }}][purchase_requisition_item_id]" value="{{ $line['purchase_requisition_item_id'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][item_name]" value="{{ $line['item_name'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][uom]" value="{{ $line['uom'] ?? '' }}">
                                        <input type="hidden" name="lines[{{ $i }}][category]" value="{{ $line['category'] ?? '' }}">
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
                    <div class="col-12">
                        <label class="form-label">Signature boxes on the printed form</label>
                        @php($signSlots = range(0, \App\Models\GsComparativeStatement::MAX_SIGNATORIES - 1))
                        <div class="row g-2">
                            @foreach($signSlots as $i)
                                @php($box = $state['signatories'][$i] ?? ['label' => '', 'role' => 'blank'])
                                <div class="col-12 col-md-6 col-xl">
                                    <input type="text" name="signatories[{{ $i }}][label]" value="{{ $box['label'] ?? '' }}" maxlength="60" class="form-control form-control-sm mb-1" placeholder="Box {{ $i + 1 }} title (empty = not printed)">
                                    <select name="signatories[{{ $i }}][role]" class="form-select form-select-sm">
                                        @foreach(\App\Models\GsComparativeStatement::SIGN_ROLES as $key => $text)
                                            <option value="{{ $key }}" @selected(($box['role'] ?? 'blank') === $key)>{{ $text }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text">Leave a title empty to drop that box. Up to {{ \App\Models\GsComparativeStatement::MAX_SIGNATORIES }} boxes; the name and date fill in automatically as the sheet is submitted, checked and approved.</div>
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

    // ---------------------------------------------------------------- long lists
    var rows = Array.prototype.slice.call(form.querySelectorAll('tr[data-line]'));
    var search = document.getElementById('rowSearch');
    var catSel = document.getElementById('catFilter');
    var viewSel = document.getElementById('viewFilter');

    function priced(row, k) { return num(row.querySelector('[data-price="' + k + '"]').value) !== null; }

    function applyFilter() {
        var needle = (search.value || '').trim().toLowerCase();
        var cat = catSel ? catSel.value : '';
        var view = viewSel.value;
        var keys = activeKeys();
        var shownCats = {};
        rows.forEach(function (row) {
            var show = true;
            if (needle && row.dataset.name.indexOf(needle) === -1) show = false;
            if (cat && row.dataset.cat !== cat) show = false;
            if (view === 'unpriced' && !keys.some(function (k) { return !priced(row, k); })) show = false;
            if (view === 'nochoice' && row.querySelector('[data-choose]:checked')) show = false;
            row.hidden = !show;
            if (show) shownCats[row.dataset.cat] = true;
        });
        form.querySelectorAll('tr[data-cat-row]').forEach(function (h) { h.hidden = !shownCats[h.dataset.catRow]; });
    }

    function progress() {
        var keys = activeKeys(), n = rows.length, parts = [];
        keys.forEach(function (k) {
            var c = rows.filter(function (r) { return priced(r, k); }).length;
            parts.push('Supplier ' + (k + 1) + ': ' + c + '/' + n + ' priced');
        });
        var chosen = rows.filter(function (r) { return r.querySelector('[data-choose]:checked'); }).length;
        parts.push('Chosen: ' + chosen + '/' + n);
        document.getElementById('progress').innerHTML = parts.join('<br>');
    }

    [search, catSel, viewSel].forEach(function (el) { if (el) el.addEventListener('input', applyFilter); });
    form.addEventListener('input', function (e) { if (e.target.matches('[data-price]')) progress(); });
    form.addEventListener('change', progress);

    function visibleRows() { return rows.filter(function (r) { return !r.hidden; }); }

    // Enter moves down to the same supplier's price on the next item.
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.matches('[data-price],[data-qty]')) return;
        e.preventDefault();
        var sel = e.target.matches('[data-price]') ? '[data-price="' + e.target.dataset.price + '"]' : '[data-qty]';
        var vis = visibleRows(), at = vis.indexOf(e.target.closest('tr[data-line]'));
        var next = vis[at + 1];
        if (next) { var f = next.querySelector(sel); f.focus(); f.select(); }
    });

    // Item, UoM and quantity of the items shown, tab-separated: pastes into Excel as three columns.
    document.getElementById('copyList').addEventListener('click', function () {
        var lines = visibleRows().map(function (r) {
            var cells = r.querySelectorAll('td');
            return [r.querySelector('.fw-semibold').textContent.trim(), r.querySelector('[name$="[uom]"]').value, r.querySelector('[data-qty]').value].join('\t');
        });
        var text = lines.join('\n'), btn = this;
        function done() { btn.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Copied ' + lines.length + ' item(s)'; }
        if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); }
        else { var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done(); }
    });

    // Paste a column (or several) straight from Excel.
    form.addEventListener('paste', function (e) {
        if (!e.target.matches('[data-price]')) return;
        var text = (e.clipboardData || window.clipboardData).getData('text');
        if (text.indexOf('\n') === -1 && text.indexOf('\t') === -1) return; // one value: normal paste
        e.preventDefault();
        var grid = text.replace(/\r/g, '').replace(/\n+$/, '').split('\n').map(function (r) { return r.split('\t'); });
        var keys = activeKeys(), k0 = keys.indexOf(parseInt(e.target.dataset.price, 10));
        var vis = visibleRows(), r0 = vis.indexOf(e.target.closest('tr[data-line]'));
        var filled = 0;
        grid.forEach(function (cells, ri) {
            var row = vis[r0 + ri];
            if (!row) return;
            cells.forEach(function (cell, ci) {
                var k = keys[k0 + ci];
                if (k === undefined) return;
                var v = cell.replace(/[^0-9.\-]/g, '');
                if (v === '' || isNaN(parseFloat(v))) return;
                row.querySelector('[data-price="' + k + '"]').value = v;
                filled++;
            });
            if (!row.querySelector('[data-choose]:checked')) { var lk = lowestKey(row); if (lk !== null) choose(row, lk); }
        });
        recalc(); progress(); applyFilter();
        var note = document.getElementById('progress');
        note.insertAdjacentHTML('beforeend', '<br><span class="text-success">Pasted ' + filled + ' price(s).</span>');
    });

    // Send the grid as one field: 500+ items would overrun PHP's input-variable limit as ordinary fields.
    form.addEventListener('submit', function () {
        var out = [];
        rows.forEach(function (row) {
            var o = {};
            row.querySelectorAll('input').forEach(function (inp) {
                if (!inp.name || inp.name.indexOf('lines[') !== 0 || inp.disabled) return;
                if (inp.type === 'radio' && !inp.checked) return;
                if (inp.value === '') return;
                var path = inp.name.match(/\[([^\]]*)\]/g).map(function (x) { return x.slice(1, -1); });
                path.shift();
                var t = o;
                path.forEach(function (key, idx) {
                    if (idx === path.length - 1) { t[key] = inp.value; }
                    else { t[key] = t[key] || {}; t = t[key]; }
                });
            });
            out.push(o);
        });
        document.getElementById('linesJson').value = JSON.stringify(out);
        form.querySelectorAll('tbody input').forEach(function (inp) { inp.disabled = true; });
    });

    recalc(); progress();
})();
</script>
@endsection
