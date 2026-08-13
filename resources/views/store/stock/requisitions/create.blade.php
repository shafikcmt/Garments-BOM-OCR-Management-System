@extends('layouts.app')

@section('title', 'General Stock — Purchase Requisition')

@php
    $editing = isset($requisition);
    $old = fn ($key, $fallback = null) => old($key, $fallback);

    // Lines to render on first paint: whatever validation bounced back, else
    // the saved lines when editing a draft, else one blank line.
    $existingLines = collect(old('items', $editing
        ? $requisition->items->map(fn ($i) => [
            'stock_item_id' => $i->stock_item_id,
            'specification' => $i->specification,
            'type' => $i->type,
            'user_dept' => $i->user_dept,
            'qty_requested' => $i->qty_requested,
            'rate_appx' => $i->rate_appx,
            'remarks' => $i->remarks,
        ])->all()
        : []));
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => $editing ? 'Edit' : 'New'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">{{ $editing ? 'Edit Requisition' : 'New Purchase Requisition' }}</h3>
                    <p class="app-hero-copy mb-0">Stock, consumption and last purchase are filled in for you as you pick each item.</p>
                </div>
            </div>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>All Requisitions
            </a>
        </div>
    </div>

    @include('store.stock._stock-ui')
    @include('store._flash')

    <form method="POST"
          action="{{ $editing ? route('store.stock.requisitions.update', $requisition) : route('store.stock.requisitions.store') }}"
          id="requisitionForm">
        @csrf
        @if($editing) @method('PUT') @endif

        {{-- One form, any number of lines — the Single/Multiple toggle is gone
             because "Multiple" with one line was already the same requisition.
             Posted as multi always, including when editing a requisition that
             was raised under the old single mode: the server trims a "single"
             requisition to its first line, so leaving the old value here would
             silently drop lines added on this screen. --}}
        <input type="hidden" name="mode" id="requisitionMode" value="multi">

        <div class="card gx-stock-card mb-4">
            <div class="gx-stock-card-body">

                <h5 class="mb-3">Requisition Details</h5>

                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4 col-xl-3">
                        <label class="form-label" for="requisitionNo">Requisition No</label>
                        {{-- Editable on purpose: a paper requisition that already
                             carries a number can be entered as-is. The unique
                             index on the column is what stops a duplicate. --}}
                        <input type="text" name="requisition_no" id="requisitionNo" maxlength="100"
                               class="form-control @error('requisition_no') is-invalid @enderror"
                               value="{{ $old('requisition_no', $editing ? $requisition->requisition_no : $nextNo) }}">
                        @error('requisition_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="gx-stock-help">Suggested for the chosen section — change it if your document already has a number.</div>
                    </div>

                    <div class="col-6 col-md-4 col-xl-2">
                        <label class="form-label" for="requisitionDate">Requisition Date <span class="text-danger">*</span></label>
                        <input type="date" name="requisition_date" id="requisitionDate" required
                               class="form-control @error('requisition_date') is-invalid @enderror"
                               value="{{ $old('requisition_date', $editing ? $requisition->requisition_date->toDateString() : now()->toDateString()) }}">
                        @error('requisition_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-6 col-md-4 col-xl-2">
                        <label class="form-label" for="sectionSelect">Section</label>
                        <select name="indent_section_id" id="sectionSelect" class="form-select js-searchable">
                            <option value="">All</option>
                            @foreach($sections as $s)
                                <option value="{{ $s->id }}" @selected($old('indent_section_id', $editing ? $requisition->indent_section_id : null) == $s->id)>{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-md-6 col-xl-2">
                        <label class="form-label" for="personSelect">Requested By</label>
                        <select name="indent_person_id" id="personSelect" class="form-select js-searchable">
                            <option value="">Select…</option>
                            @foreach($persons as $p)
                                <option value="{{ $p->id }}" @selected($old('indent_person_id', $editing ? $requisition->indent_person_id : null) == $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-md-6 col-xl-3">
                        <label class="form-label" for="contact">Contact No. / Email</label>
                        <input type="text" name="contact" id="contact" maxlength="255"
                               class="form-control" value="{{ $old('contact', $editing ? $requisition->contact : null) }}">
                    </div>
                </div>

                {{-- ----------------------------------------------------------
                     Item lines — a card each rather than one 19-column row.
                     The fields the requester actually types sit on top at full
                     size; the figures fetched from /requisitions/item-snapshot
                     are read-only facts, so they are shown as muted chips along
                     the bottom instead of as inputs nobody can edit.
                     ---------------------------------------------------------- --}}
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h6 class="gx-stock-subhead mb-0">Items</h6>
                        <span class="gx-stock-help">Pick a category to narrow the item list, then choose the item.</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary js-add-line" id="addRequisitionLine">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                    </button>
                </div>

                @error('items')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                <div id="requisitionLines" class="gx-req-lines"></div>

                {{-- The same button again, under the last line card. A
                     requisition can run to a dozen items, and each card is tall,
                     so the top button was a long scroll away by item three. Same
                     handler as it — see the .js-add-line binding below. --}}
                <div class="mt-3">
                    <button type="button" class="btn btn-sm btn-outline-primary js-add-line">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                    </button>
                </div>

                <div class="gx-req-total">
                    <span class="gx-req-total-label">Total Amount</span>
                    <span class="gx-req-total-value" id="grandTotal">0.00</span>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-12 col-xl-8">
                        <label class="form-label" for="remarks">Remarks</label>
                        <input type="text" name="remarks" id="remarks" maxlength="2000" class="form-control"
                               value="{{ $old('remarks', $editing ? $requisition->remarks : null) }}"
                               placeholder="Anything the approver should know">
                    </div>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-3 mt-4 pt-3 border-top">
                    {{-- Draft keeps it editable; Submit hands it to the store
                         lead and locks the lines. --}}
                    <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>Save as Draft
                    </button>
                    <button type="submit" name="action" value="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Submit Requisition
                    </button>
                    <p class="gx-stock-help mb-0" style="max-width:620px;">
                        A draft stays editable. Once submitted, the stock and last-purchase figures are frozen on the
                        requisition so it always shows the position that justified it.
                    </p>
                </div>
            </div>
        </div>
    </form>

    {{-- Line template. Cloned by the script below, with __INDEX__ replaced by
         the line's array index. --}}
    <template id="requisitionLineTemplate">
        <div class="gx-req-line">
            <div class="gx-req-line-head">
                <span class="gx-req-line-no js-line-number"></span>
                <button type="button" class="btn btn-sm btn-outline-danger js-line-remove">
                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                </button>
            </div>

            {{-- What the requester types — one row per line. Item Name leads
                 because it is what the requester actually came to choose;
                 Category sits beside it as the filter that narrows the list,
                 and fills itself in from the item once one is picked. --}}
            <div class="gx-req-fields">
                <div class="gx-req-field is-item">
                    <label class="form-label">Item Name <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm js-line-item js-searchable" name="items[__INDEX__][stock_item_id]" required>
                        <option value="">Select item…</option>
                        @foreach($items as $it)
                            <option value="{{ $it->id }}"
                                    data-uom="{{ $it->uom }}"
                                    data-brand="{{ $it->brand }}"
                                    data-category-id="{{ $it->item_category_id }}">{{ $it->name }}@if($it->brand) ({{ $it->brand }})@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="gx-req-field is-category">
                    <label class="form-label">Category</label>
                    {{-- Two jobs: narrows the item list, and shows the item's own
                         category once one is chosen. The category SAVED always
                         follows the item, so this stays a helper either way. --}}
                    <select class="form-select form-select-sm js-line-category">
                        <option value="">All categories</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gx-req-field is-uom">
                    <label class="form-label">Uom</label>
                    {{-- Derived from the item, never posted. --}}
                    <input type="text" class="form-control form-control-sm js-line-uom gx-stock-readonly text-center" readonly tabindex="-1" placeholder="—">
                </div>
                <div class="gx-req-field is-qty">
                    <label class="form-label">Qty Req. <span class="text-danger">*</span></label>
                    <input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm js-line-qty" name="items[__INDEX__][qty_requested]" required placeholder="0">
                </div>
                <div class="gx-req-field is-spec">
                    <label class="form-label">Specification</label>
                    <input type="text" class="form-control form-control-sm js-line-spec" name="items[__INDEX__][specification]" maxlength="255" placeholder="—">
                </div>
                <div class="gx-req-field is-type">
                    <label class="form-label">Type</label>
                    <select class="form-select form-select-sm" name="items[__INDEX__][type]">
                        <option value="">—</option>
                        @foreach($typeLabels as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="gx-req-field is-dept">
                    <label class="form-label">User Dept.</label>
                    <input type="text" class="form-control form-control-sm" name="items[__INDEX__][user_dept]" maxlength="255" placeholder="—">
                </div>
                <div class="gx-req-field is-rate">
                    <label class="form-label">Rate Appx.</label>
                    <input type="number" step="0.0001" min="0" class="form-control form-control-sm js-line-rate" name="items[__INDEX__][rate_appx]" placeholder="0.00">
                </div>
                <div class="gx-req-field is-remarks">
                    <label class="form-label">Remarks</label>
                    <input type="text" class="form-control form-control-sm" name="items[__INDEX__][remarks]" maxlength="1000" placeholder="Optional">
                </div>
            </div>

            {{-- Read-only facts and the two derived results. Chips rather than
                 disabled inputs: nothing here is typed into, and boxes that
                 look editable but are not is what made the old row confusing. --}}
            <div class="gx-req-line-foot">
                <div class="gx-req-facts">
                    <div class="gx-req-fact-group">
                        <span class="gx-req-group-label">Stock</span>
                        <span class="gx-req-fact"><em>In Hand</em><b class="js-line-stock">—</b></span>
                        <span class="gx-req-fact"><em>Safety</em><b class="js-line-safety">—</b></span>
                        <span class="gx-req-fact"><em>Cons. L/M</em><b class="js-line-consumption">—</b></span>
                    </div>
                    <div class="gx-req-fact-group">
                        <span class="gx-req-group-label">Last Purchase</span>
                        <span class="gx-req-fact"><em>Qty</em><b class="js-line-lpqty">—</b></span>
                        <span class="gx-req-fact"><em>Rate</em><b class="js-line-lprate">—</b></span>
                        <span class="gx-req-fact"><em>Date</em><b class="js-line-lpdate">—</b></span>
                    </div>
                </div>
                <div class="gx-req-results">
                    <span class="gx-req-result"><em>To Be Procured</em><b class="js-line-tbp">0</b></span>
                    <span class="gx-req-result is-amount"><em>Amount</em><b class="js-line-amount">0.00</b></span>
                </div>
            </div>
        </div>
    </template>

    @include('store.stock._searchable')

    <style>
        /* ------------------------------------------------------------------
         * One card per item line. Replaces a 19-column table that could only
         * be used by scrolling sideways: the columns are now rows of ordinary
         * full-size fields, so the form reflows on a narrow screen instead.
         * ------------------------------------------------------------------ */
        .gx-req-lines { display: flex; flex-direction: column; gap: 1rem; }

        .gx-req-line {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: 1rem 1.1rem 0.9rem;
        }
        .gx-req-line:focus-within { border-color: #bfdbfe; box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .06); }

        .gx-req-line-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; margin-bottom: .85rem;
        }
        .gx-req-line-no {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 26px; height: 26px; padding: 0 .45rem;
            border-radius: 8px; background: #eef4ff; color: #1d4ed8;
            font-size: .78rem; font-weight: 800;
        }

        .gx-req-line .form-label {
            font-size: .68rem; text-transform: uppercase; letter-spacing: .04em;
            font-weight: 750; color: #64748b; margin-bottom: .25rem;
            white-space: nowrap;
        }

        /* ------------------------------------------------------------------
         * The nine typed fields of a line. A grid rather than Bootstrap's
         * 12-column row: nine fields do not divide into twelve, and these need
         * genuinely different widths — Item Name is the field people read,
         * Uom is three characters.
         *
         * It steps down instead of scrolling sideways, so a smaller screen
         * wraps to two or three tidy rows rather than hiding fields off-page.
         * ------------------------------------------------------------------ */
        .gx-req-fields { display: grid; gap: .55rem .6rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (min-width: 768px)  { .gx-req-fields { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (min-width: 1200px) { .gx-req-fields { grid-template-columns: repeat(6, minmax(0, 1fr)); } }

        /* One row for all nine, once there is room to do it without cramming. */
        @media (min-width: 1500px) {
            .gx-req-fields {
                grid-template-columns:
                    minmax(0, 2.1fr)   /* Item Name */
                    minmax(0, 1.2fr)   /* Category */
                    64px               /* Uom */
                    minmax(0, .95fr)   /* Qty Req. */
                    minmax(0, 1.35fr)  /* Specification */
                    minmax(0, .95fr)   /* Type */
                    minmax(0, 1.1fr)   /* User Dept. */
                    minmax(0, .95fr)   /* Rate Appx. */
                    minmax(0, 1.3fr);  /* Remarks */
            }
        }

        /* min-width:0 on the field itself as well: a grid item defaults to
           min-content, and TomSelect's widest option would otherwise stretch
           the Item Name column past its share and squeeze the rest. */
        .gx-req-field { min-width: 0; }
        .gx-req-field .form-control,
        .gx-req-field .form-select,
        .gx-req-field .ts-wrapper { width: 100%; }

        /* The auto-filled figures. Muted and compact on purpose — they are
           evidence for the decision, not fields to fill in. */
        .gx-req-line-foot {
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: space-between; gap: .75rem 1.25rem;
            margin-top: .9rem; padding-top: .75rem;
            border-top: 1px dashed #e2e8f0;
        }
        .gx-req-facts { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1.1rem; }
        .gx-req-fact-group { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .8rem; }
        .gx-req-fact-group + .gx-req-fact-group {
            padding-left: 1.1rem; border-left: 1px solid #eef2f7;
        }
        .gx-req-group-label {
            font-size: .62rem; text-transform: uppercase; letter-spacing: .06em;
            font-weight: 800; color: #94a3b8;
        }
        .gx-req-fact { display: inline-flex; align-items: baseline; gap: .35rem; font-size: .78rem; }
        .gx-req-fact em { font-style: normal; color: #94a3b8; }
        .gx-req-fact b { color: #475569; font-weight: 700; font-variant-numeric: tabular-nums; }

        /* The two numbers the whole line exists to produce. */
        .gx-req-results { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .gx-req-result {
            display: inline-flex; align-items: baseline; gap: .4rem;
            padding: .3rem .7rem; border-radius: 9px;
            background: #f8fafc; border: 1px solid #eef2f7;
        }
        .gx-req-result em {
            font-style: normal; font-size: .66rem; text-transform: uppercase;
            letter-spacing: .04em; font-weight: 750; color: #64748b;
        }
        .gx-req-result b { font-size: .92rem; font-weight: 800; color: #0f172a; font-variant-numeric: tabular-nums; }
        .gx-req-result.is-amount { background: #eef4ff; border-color: #dbe4f0; }
        .gx-req-result.is-amount b { color: #1d4ed8; }


        .gx-req-total {
            display: flex; align-items: center; justify-content: flex-end;
            gap: .75rem; margin-top: 1rem; padding-top: .85rem;
            border-top: 1px solid #e2e8f0;
        }
        .gx-req-total-label {
            font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
            font-weight: 800; color: #64748b;
        }
        .gx-req-total-value {
            font-size: 1.1rem; font-weight: 800; color: #0f172a;
            font-variant-numeric: tabular-nums;
        }

        @media (max-width: 575.98px) {
            .gx-req-line { padding: .85rem .8rem .75rem; }
            .gx-req-fact-group + .gx-req-fact-group { padding-left: 0; border-left: 0; }
        }
    </style>

    <script>
        (function () {
            var form = document.getElementById('requisitionForm');
            if (!form) { return; }

            var body = document.getElementById('requisitionLines');
            var tpl = document.getElementById('requisitionLineTemplate');
            // Both triggers — above the line cards and under the last one.
            // Selected by class so the two can never drift apart.
            var addBtns = document.querySelectorAll('.js-add-line');
            var grand = document.getElementById('grandTotal');
            var dateField = document.getElementById('requisitionDate');

            var SNAPSHOT_URL = @json(route('store.stock.requisitions.item-snapshot', ['stockItem' => '__ID__']));
            var NEXT_NO_URL = @json(route('store.stock.requisitions.next-number'));

            var nextIndex = 0;

            function rows() {
                return Array.prototype.slice.call(body.querySelectorAll('.gx-req-line'));
            }

            function num(value) {
                var n = parseFloat(value);
                return isNaN(n) ? 0 : n;
            }

            /* Trailing zeros trimmed so a whole number reads "15", not "15.0000". */
            function qtyText(value) {
                if (value === null || value === undefined || value === '') { return '—'; }
                return String(parseFloat(parseFloat(value).toFixed(4)));
            }

            function money(value) {
                return num(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            /* Renumber the visible line number and rewrite every items[n] name,
               so a removed line never leaves a gap in the posted array. */
            function renumber() {
                rows().forEach(function (row, i) {
                    row.querySelector('.js-line-number').textContent = i + 1;

                    row.querySelectorAll('[name]').forEach(function (field) {
                        field.name = field.name.replace(/items\[\d+\]/, 'items[' + i + ']');
                    });
                });
            }

            /* To Be Procured and Amount, previewed exactly as they will be SAVED.
             *
             * The form lets a requester add a line per department, but on save
             * those lines collapse into one line per item
             * (PurchaseRequisitionService::mergeLines) — an item has one stock
             * figure, one shortfall and one row on the document.
             *
             * So the preview groups the rows the same way the server will:
             * quantities summed per item, the first non-blank rate kept, and
             * the shortfall worked out once from the combined quantity. Every
             * row of a repeated item shows that one shared result, and the
             * Total counts it once — so what is on screen is what gets stored,
             * with no surprise when the merged document appears. */
            function recalcAll() {
                var groups = [];
                var byItem = {};

                rows().forEach(function (row, index) {
                    var itemId = row.querySelector('.js-line-item').value || '';

                    // A row with no item yet cannot merge with anything.
                    var key = itemId === '' ? 'blank-' + index : 'item-' + itemId;

                    if (!byItem[key]) {
                        byItem[key] = { qty: 0, stock: num(row.dataset.stockInHand), rate: 0, rows: [] };
                        groups.push(byItem[key]);
                    }

                    var group = byItem[key];
                    group.qty += num(row.querySelector('.js-line-qty').value);
                    group.rows.push(row);

                    // First non-blank rate wins, matching mergeLines().
                    if (!group.rate) { group.rate = num(row.querySelector('.js-line-rate').value); }
                });

                var sum = 0;

                groups.forEach(function (group) {
                    var tbp = Math.max(0, group.qty - group.stock);
                    var amount = tbp * group.rate;

                    group.rows.forEach(function (row) {
                        row.querySelector('.js-line-tbp').textContent = qtyText(tbp);
                        row.querySelector('.js-line-amount').textContent = money(amount);
                    });

                    // Counted once per item, however many rows fed it.
                    sum += amount;
                });

                grand.textContent = money(sum);
            }

            /* Kept as the name the per-row listeners call. Any one line moving
               can change another line's share of the same item's stock, so the
               whole requisition is always recomputed. */
            function recalc() {
                recalcAll();
            }

            function total() {
                recalcAll();
            }

            /* Ask the server for the item's live position. Read-only: it comes
               from the same service the Stock Report uses, so the two can never
               disagree about the same item on the same day. */
            function loadSnapshot(row) {
                var select = row.querySelector('.js-line-item');
                var id = select.value;

                if (!id) {
                    ['stock', 'safety', 'consumption', 'lpqty', 'lprate', 'lpdate'].forEach(function (key) {
                        row.querySelector('.js-line-' + key).textContent = '—';
                    });
                    row.querySelector('.js-line-uom').value = '';
                    row.dataset.stockInHand = '';
                    recalc(row);
                    return;
                }

                var url = SNAPSHOT_URL.replace('__ID__', encodeURIComponent(id));
                if (dateField && dateField.value) { url += '?on=' + encodeURIComponent(dateField.value); }

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) {
                        if (!data) { return; }

                        row.querySelector('.js-line-uom').value = data.uom || '';
                        row.querySelector('.js-line-stock').textContent = qtyText(data.stock_in_hand);
                        row.querySelector('.js-line-safety').textContent = qtyText(data.safety_stock);
                        row.querySelector('.js-line-consumption').textContent = qtyText(data.consumption_last_month);
                        row.querySelector('.js-line-lpqty').textContent = qtyText(data.last_purchase_qty);
                        row.querySelector('.js-line-lprate').textContent = data.last_purchase_rate === null ? '—' : money(data.last_purchase_rate);
                        row.querySelector('.js-line-lpdate').textContent = data.last_purchase_date || '—';

                        row.dataset.stockInHand = data.stock_in_hand === null ? '' : data.stock_in_hand;

                        // Rate defaults to the last purchase rate but stays
                        // editable, so an expected price rise can be planned in.
                        var rateField = row.querySelector('.js-line-rate');
                        if (!rateField.value && data.last_purchase_rate !== null) {
                            rateField.value = data.last_purchase_rate;
                        }

                        applyItemMasterDefaults(row, data);

                        recalc(row);
                    })
                    .catch(function () { /* Leave the chips blank; the server fills them on save. */ });
            }

            /* Copy across what the Item Master already knows, so nothing that
               is on file has to be typed again.
             *
             * Only fields that genuinely exist on stock_items are touched:
             * Uom, Category (item_category_id) and the item master's merged
             * Brand/Specification, which pre-fills this line's Specification.
             * Type and User Dept. are NOT item attributes; they are decisions
             * about this particular request, so they stay manual.
             *
             * Nothing already typed is overwritten: a requester who asked for a
             * specific brand on this line keeps what they wrote. */
            function applyItemMasterDefaults(row, data) {
                var select = row.querySelector('.js-line-item');
                var option = select.querySelector('option[value="' + select.value + '"]');

                var spec = row.querySelector('.js-line-spec');
                if (!spec.value) {
                    var fromMaster = data.specification || (option ? option.dataset.brand : '') || '';

                    if (fromMaster) { spec.value = fromMaster; }
                }

                // The item's own category. Setting it re-runs the filter, which
                // leaves the chosen item in place because the item obviously
                // matches its own category — so this narrows the list for the
                // next edit without ever clearing the line.
                var category = row.querySelector('.js-line-category');
                var categoryId = data.item_category_id ? String(data.item_category_id) : '';

                if (categoryId && category.value !== categoryId
                    && category.querySelector('option[value="' + categoryId + '"]')) {
                    category.value = categoryId;
                    applyCategoryFilter(row);
                }
            }

            /* Category is a filter on the item list, exactly as on Record Issue. */
            function applyCategoryFilter(row) {
                var categoryId = row.querySelector('.js-line-category').value;
                var select = row.querySelector('.js-line-item');
                var ts = select.tomselect;

                Array.prototype.forEach.call(select.options, function (option) {
                    if (!option.value) { return; }

                    var match = !categoryId || option.dataset.categoryId === categoryId;
                    option.hidden = !match;
                    option.disabled = !match;
                });

                if (ts) {
                    var chosen = select.value;
                    ts.clearOptions();
                    Array.prototype.forEach.call(select.options, function (option) {
                        if (!option.disabled || !option.value) {
                            ts.addOption({ value: option.value, text: option.textContent });
                        }
                    });
                    ts.refreshOptions(false);

                    // The chosen item is no longer offered by this category, so
                    // the line is cleared rather than left showing a stale name.
                    if (chosen && select.querySelector('option[value="' + chosen + '"]').disabled) {
                        ts.clear(true);
                        loadSnapshot(row);
                    } else if (chosen) {
                        ts.setValue(chosen, true);
                    }
                }
            }

            function addLine(values) {
                var html = tpl.innerHTML.split('__INDEX__').join(nextIndex);
                nextIndex++;

                var holder = document.createElement('div');
                holder.innerHTML = html.trim();
                var row = holder.querySelector('.gx-req-line');
                body.appendChild(row);

                if (values) {
                    if (values.stock_item_id) { row.querySelector('.js-line-item').value = values.stock_item_id; }
                    if (values.specification) { row.querySelector('.js-line-spec').value = values.specification; }
                    if (values.type) { row.querySelector('[name$="[type]"]').value = values.type; }
                    if (values.user_dept) { row.querySelector('[name$="[user_dept]"]').value = values.user_dept; }
                    if (values.qty_requested) { row.querySelector('.js-line-qty').value = values.qty_requested; }
                    if (values.rate_appx) { row.querySelector('.js-line-rate').value = values.rate_appx; }
                    if (values.remarks) { row.querySelector('[name$="[remarks]"]').value = values.remarks; }
                }

                if (window.gxInitSearchable) { window.gxInitSearchable(row); }

                row.querySelector('.js-line-category').addEventListener('change', function () { applyCategoryFilter(row); });
                row.querySelector('.js-line-item').addEventListener('change', function () { loadSnapshot(row); });
                row.querySelector('.js-line-qty').addEventListener('input', function () { recalc(row); });
                row.querySelector('.js-line-rate').addEventListener('input', function () { recalc(row); });
                row.querySelector('.js-line-remove').addEventListener('click', function () {
                    // Only ask when there is something to lose — confirming an
                    // empty line every time trains people to click through it.
                    if (rowHasData(row) && ! window.confirm('Remove this item line? What you entered on it will be lost.')) {
                        return;
                    }

                    row.remove();

                    // A requisition always has at least one line to type into.
                    if (rows().length === 0) { addLine(); }

                    renumber();
                    total();
                });

                renumber();

                if (values && values.stock_item_id) { loadSnapshot(row); }

                return row;
            }

            function rowHasData(row) {
                return !!(row.querySelector('.js-line-item').value || row.querySelector('.js-line-qty').value);
            }

            addBtns.forEach(function (btn) { btn.addEventListener('click', function () { addLine(); }); });

            // Re-suggest the Requisition No when the section or date changes —
            // the serial restarts per section per month.
            function refreshNumber() {
                var field = document.getElementById('requisitionNo');
                if (!field || field.dataset.touched === '1') { return; }

                var params = new URLSearchParams();
                var section = document.getElementById('sectionSelect');
                if (section && section.value) { params.set('section', section.value); }
                if (dateField && dateField.value) { params.set('on', dateField.value); }

                fetch(NEXT_NO_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (data) { if (data && data.requisition_no) { field.value = data.requisition_no; } })
                    .catch(function () { /* Keep whatever is in the box. */ });
            }

            @if(! $editing)
                var numberField = document.getElementById('requisitionNo');

                numberField.addEventListener('input', function () { this.dataset.touched = '1'; });
                document.getElementById('sectionSelect').addEventListener('change', refreshNumber);
                if (dateField) { dateField.addEventListener('change', refreshNumber); }

                // The box shows the number so the operator knows what they are
                // about to create, but an untouched preview must NOT be posted:
                // if it were, the server would take the form's value instead of
                // calling the allocator, the counter would never advance, and
                // the next requisition would preview the same number and
                // collide on the unique index.
                //
                // Dropping the name leaves the field purely informational, so
                // the server allocates — and whoever saves second correctly
                // gets the next number rather than an error.
                form.addEventListener('submit', function () {
                    if (numberField.dataset.touched !== '1') {
                        numberField.removeAttribute('name');
                    }
                });
            @endif

            // Re-fetch every line's figures when the requisition date moves:
            // the stock position is as at that date, not today.
            if (dateField) {
                dateField.addEventListener('change', function () {
                    rows().forEach(function (row) { loadSnapshot(row); });
                });
            }

            // First paint: whatever bounced back from validation or is being
            // edited, else one empty line.
            @foreach($existingLines as $line)
                addLine(@json($line));
            @endforeach

            if (rows().length === 0) { addLine(); }
        })();
    </script>
</div>
@endsection
