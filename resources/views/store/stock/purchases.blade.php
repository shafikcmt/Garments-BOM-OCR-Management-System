@extends('layouts.app')

@section('title', 'General Stock — Receiving')

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);

    // Drives the Clear button only — the filtering itself is the controller's.
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Receiving'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Receiving</h3>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Stock Report</a>
                <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Items</a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')


    @include('store._flash')

    {{-- ------------------------------------------------------------------
         Record Purchase — one header, many item lines, mirroring Record Issue.
         Each line is still saved as its own purchase row carrying a copy of
         the header, so the Stock Report is untouched by grouping.
         ------------------------------------------------------------------ --}}
    <div class="card gx-stock-card mb-4">
        <div class="gx-stock-card-body">
            <h5 class="mb-3">Record Receiving</h5>

            @if($items->isEmpty())
                <p class="text-muted small mb-0">Add a stock item first — a purchase must be received against an item in the master.</p>
            @else
                <form method="POST" action="{{ route('store.stock.purchases.store') }}" id="purchaseForm">
                    @csrf

                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3 col-xl-2">
                            <label class="form-label" for="challanDate">Challan Date <span class="text-danger">*</span></label>
                            <input type="date" name="purchase_date" id="challanDate" value="{{ old('purchase_date', now()->toDateString()) }}" class="form-control" required>
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            <label class="form-label" for="rcvDate">RCV Date <span class="text-danger">*</span></label>
                            <input type="date" name="rcv_date" id="rcvDate" value="{{ old('rcv_date', now()->toDateString()) }}" class="form-control" required>
                        </div>
                        <div class="col-6 col-md-3 col-xl-1">
                            <label class="form-label" for="monthLabel">Month</label>
                            <input type="text" id="monthLabel" class="form-control gx-stock-readonly" readonly tabindex="-1">
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            {{-- Allocated by the system on save. Shown as a preview,
                                 never typed: whoever saves first takes this number,
                                 so it is not promised to this form. --}}
                            <label class="form-label" for="rvPreview">RV No</label>
                            <input type="text" id="rvPreview" class="form-control gx-stock-readonly" readonly tabindex="-1" value="{{ $nextRv }}">
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <label class="form-label" for="challanNo">Challan / Invoice No</label>
                            <input name="challan_no" id="challanNo" value="{{ old('challan_no') }}" class="form-control" maxlength="100">
                        </div>
                        <div class="col-12 col-md-8 col-xl-3">
                            <label class="form-label" for="supplierSelect">Supplier Name</label>
                            @if($suppliers->isEmpty())
                                <div class="alert alert-info small py-2 mb-0">
                                    No suppliers yet — add them in
                                    <a href="{{ route('store.stock.purchase-setup.index') }}" class="alert-link">Master Setup</a>.
                                </div>
                            @else
                                <select name="general_stock_supplier_id" id="supplierSelect" class="form-select js-searchable">
                                    <option value="">Select supplier…</option>
                                    @foreach($suppliers as $s)
                                        <option value="{{ $s->id }}" @selected(old('general_stock_supplier_id') == $s->id)>{{ $s->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div>
                            <h6 class="gx-stock-subhead mb-0">Items</h6>
                            <span class="gx-stock-help">Everything received on this challan. All lines share the RV No above.</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary js-add-line" id="addPurchaseLine">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                        </button>
                    </div>

                    {{-- Scrolls sideways on a narrow screen. The row dropdowns are
                         rendered on <body> (see _searchable) because anything that
                         scrolls horizontally also clips vertically. --}}
                    <div class="gx-line-scroll">
                        <table class="table align-middle mb-0 gx-line-table">
                            <colgroup>
                                <col style="width:44px;">
                                <col style="width:30%;">
                                <col style="width:80px;">
                                <col style="width:15%;">
                                <col style="width:110px;">
                                <col style="width:120px;">
                                <col>
                                <col style="width:104px;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Item Name <span class="text-danger">*</span></th>
                                    <th>Uom</th>
                                    <th>Category</th>
                                    <th>Purchased Qty <span class="text-danger">*</span></th>
                                    <th>Unit Price</th>
                                    <th>Remarks</th>
                                    <th class="text-end gx-stock-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody id="purchaseLines"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-end gx-stock-total-label">Total Value</td>
                                    <td colspan="3">
                                        <input type="text" id="grandTotal" class="form-control form-control-sm gx-stock-readonly fw-bold" readonly tabindex="-1" value="0.00">
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- The same button again, under the last line. Adding ten
                         items meant scrolling back up to the heading after each
                         one; this puts the next "add" where the operator's eyes
                         already are. Same handler as the top button — see the
                         .js-add-line binding in the script below. --}}
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-primary js-add-line">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                        </button>
                    </div>

                    {{-- Row template. Cloned by the script below, with __INDEX__
                         replaced by the line's array index. --}}
                    <template id="purchaseLineTemplate">
                        <tr class="purchase-line">
                            <td class="text-center js-line-number gx-line-no"></td>
                            <td>
                                <select class="form-select form-select-sm js-line-item js-searchable" name="items[__INDEX__][stock_item_id]" required>
                                    <option value="">Select item…</option>
                                    @foreach($items as $it)
                                        <option value="{{ $it->id }}" data-uom="{{ $it->uom }}" data-category="{{ $it->category }}">{{ $it->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            {{-- Derived from the item, never posted. --}}
                            <td><input type="text" class="form-control form-control-sm js-line-uom gx-stock-readonly text-center" readonly tabindex="-1" placeholder="—"></td>
                            <td><input type="text" class="form-control form-control-sm js-line-category gx-stock-readonly" readonly tabindex="-1" placeholder="—"></td>
                            <td><input type="number" step="0.0001" min="0.0001" class="form-control form-control-sm js-line-qty" name="items[__INDEX__][qty]" required placeholder="0"></td>
                            <td><input type="number" step="0.0001" min="0" class="form-control form-control-sm js-line-price" name="items[__INDEX__][unit_price]" placeholder="0.00"></td>
                            <td><input type="text" class="form-control form-control-sm" name="items[__INDEX__][remarks]" maxlength="1000" placeholder="Optional"></td>
                            {{-- Padded away from Remarks so a fast click on the
                                 input cannot land on Remove. --}}
                            <td class="text-end gx-line-action">
                                <button type="button" class="btn btn-sm btn-outline-danger js-line-remove">
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                                </button>
                            </td>
                        </tr>
                    </template>

                    <div class="d-flex flex-wrap align-items-center gap-3 mt-4 pt-3 border-top">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Record Receiving
                        </button>
                        <p class="gx-stock-help mb-0" style="max-width:640px;">
                            The RV No is generated on save and shared by every line on this challan.
                            Each line is recorded as its own purchase against its item.
                        </p>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- ------------------------------------------------------------------
         Purchase History — one row per goods-receiving event, expandable.
         ------------------------------------------------------------------ --}}
    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5>Purchase History <span class="badge bg-primary-subtle text-primary ms-1">{{ $groups->total() }}</span></h5>
            </div>

            <form method="GET" class="row g-3 gx-stock-filter mb-4">
                <div class="col-6 col-md-3">
                    <label class="form-label" for="purchaseFilterMonth">Month</label>
                    <input type="month" id="purchaseFilterMonth" name="month" value="{{ $filters['month'] ?? '' }}" class="form-control">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label" for="purchaseFilterSearch">Search</label>
                    <input id="purchaseFilterSearch" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control"
                           placeholder="Item, challan, RV, supplier">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </form>

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:140px;">RV No</th>
                            <th>Challan / Inv</th>
                            <th>Challan Date</th>
                            <th>RCV Date</th>
                            <th style="min-width:150px;">Supplier</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total Qty</th>
                            <th class="text-end">Total Value</th>
                            <th class="text-end gx-stock-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groups as $group)
                            @php
                                $groupLines = $lines[$group->group_key] ?? collect();
                                $paneId = 'rv-'.md5($group->group_key);
                                // Numbers issued before the RV was generated are bare
                                // digits; the generated ones always carry a month prefix.
                                $isLegacy = ! preg_match('/^[A-Z]{3}\d{2}-\d{5}$/', (string) $group->rv_no);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $group->rv_no ?: '—' }}</div>
                                    @if($isLegacy && $group->rv_no)
                                        <div class="gx-stock-spec">entered before RV numbering</div>
                                    @endif
                                </td>
                                <td class="small">{{ $group->challan_no ?: '—' }}</td>
                                <td class="small">{{ $group->purchase_date ? \Illuminate\Support\Carbon::parse($group->purchase_date)->format('d-M-Y') : '—' }}</td>
                                <td class="small">{{ $group->rcv_date ? \Illuminate\Support\Carbon::parse($group->rcv_date)->format('d-M-Y') : '—' }}</td>
                                <td class="small text-muted">{{ $group->supplier_name ?: '—' }}</td>
                                <td class="text-end fw-semibold">{{ $group->line_count }}</td>
                                <td class="text-end">{{ $qty($group->group_total_qty) }}</td>
                                <td class="text-end fw-semibold">{{ $money($group->group_total_value) }}</td>
                                <td class="text-end gx-stock-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                            data-bs-toggle="collapse" data-bs-target="#{{ $paneId }}"
                                            aria-expanded="false" aria-controls="{{ $paneId }}"
                                            data-rv-toggle>
                                        <i class="bi bi-list-ul me-1" aria-hidden="true"></i><span data-rv-toggle-text>View Items</span>
                                    </button>
                                </td>
                            </tr>
                            {{-- Inline detail rather than a modal: it keeps the
                                 receiving row in view and avoids rendering 25
                                 dialogs on every page load. --}}
                            <tr class="gx-rv-detail">
                                <td colspan="9" class="p-0">
                                    <div class="collapse" id="{{ $paneId }}">
                                        <div class="gx-rv-detail-body">
                                            <table class="table align-middle mb-0 gx-line-table">
                                                <thead>
                                                    <tr>
                                                        <th style="min-width:200px;">Item</th>
                                                        <th>Uom</th>
                                                        <th class="text-end">Qty</th>
                                                        <th class="text-end">Unit Price</th>
                                                        <th class="text-end">Total</th>
                                                        <th style="min-width:140px;">Remarks</th>
                                                        <th class="text-end gx-stock-actions">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($groupLines as $line)
                                                        <tr>
                                                            <td class="fw-semibold text-slate-900">{{ optional($line->stockItem)->name ?? '—' }}</td>
                                                            <td class="small">{{ optional($line->stockItem)->uom ?: '—' }}</td>
                                                            <td class="text-end fw-bold">{{ $qty($line->qty) }}</td>
                                                            <td class="text-end small">{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 2) : '—' }}</td>
                                                            <td class="text-end fw-semibold">{{ number_format($line->total_value, 2) }}</td>
                                                            <td class="small text-muted">{{ $line->remarks ?: '—' }}</td>
                                                            {{-- Delete is an Admin / Management right
                                                                 (store.delete); the controller enforces
                                                                 the same check server-side. Removes this
                                                                 one line, not the whole receiving. --}}
                                                            <td class="text-end gx-stock-actions">
                                                                @if($canDelete)
                                                                    <form method="POST" action="{{ route('store.stock.purchases.destroy', $line) }}" class="d-inline"
                                                                          onsubmit="return confirm('Remove {{ addslashes(optional($line->stockItem)->name ?? 'this item') }} from this receiving? The other items on it are kept.');">
                                                                        @csrf @method('DELETE')
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                                                    </form>
                                                                @else
                                                                    <span class="text-muted small">—</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="gx-stock-empty">
                                    <span class="gx-stock-empty-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                                    <div class="gx-stock-empty-title">{{ $hasFilters ? 'No receivings match this filter' : 'No purchases recorded yet' }}</div>
                                    <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or search.' : 'Record a goods receiving above.' }}</div>
                                </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $groups->links() }}</div>
        </div>
    </div>

    @include('store.stock._searchable')

    <style>
        /* Item lines table — same treatment as Record Issue. */
        .gx-line-scroll { overflow-x: auto; }
        .gx-line-table { border-collapse: separate; border-spacing: 0; }
        .gx-line-table thead th {
            font-size: .68rem; text-transform: uppercase; letter-spacing: .04em;
            font-weight: 750; color: #64748b; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0; padding: .6rem .55rem; white-space: nowrap;
        }
        .gx-line-table thead th:first-child { border-top-left-radius: 10px; }
        .gx-line-table thead th:last-child { border-top-right-radius: 10px; }
        .gx-line-table tbody td { padding: .55rem; border-bottom: 1px solid #eef2f7; vertical-align: top; }
        .gx-line-table tbody tr:nth-child(even) td { background: #fcfdff; }
        .gx-line-no { font-size: .78rem; font-weight: 700; color: #94a3b8; padding-top: .95rem !important; }
        .gx-line-action { padding-left: 1.25rem !important; }

        /* The expanded lines of one receiving. */
        .gx-rv-detail > td { border-bottom: 0 !important; background: #f8fafc !important; }
        .gx-rv-detail-body { padding: .85rem 1rem 1.1rem; }

        /* components.css styles every `.table` under .content, and additionally
           centres every cell of a row that holds a colspan cell (it assumes
           such a row is an "empty state" banner). This detail row uses colspan
           to span the full width, so both reach into the lines table: item
           names and money columns come out centred, and each cell is drawn as
           its own bordered pill. That selector carries a very high specificity,
           so !important is what it takes to put back the alignment and the flat
           rows this table was written with. Scoped to .gx-rv-detail only. */
        .gx-rv-detail .gx-line-table th,
        .gx-rv-detail .gx-line-table td { text-align: left !important; }
        .gx-rv-detail .gx-line-table th.text-end,
        .gx-rv-detail .gx-line-table td.text-end { text-align: right !important; }
        .gx-rv-detail .gx-line-table tbody td {
            border-radius: 0 !important;
            border-top: 0 !important;
            border-left: 0 !important;
            border-right: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
        }
    </style>

    <script>
        // Derived header field: Month from the challan date.
        (function () {
            var date = document.getElementById('challanDate');
            var month = document.getElementById('monthLabel');
            if (!date || !month) { return; }

            function sync() {
                if (!date.value) { month.value = ''; return; }
                var d = new Date(date.value + 'T00:00:00');
                month.value = d.toLocaleString('en-GB', { month: 'short' }) + '-' + String(d.getFullYear()).slice(-2);
            }

            date.addEventListener('change', sync);
            sync();
        })();

        // The item lines: add, remove, renumber, and the running total.
        (function () {
            var form = document.getElementById('purchaseForm');
            if (!form) { return; }

            var body = document.getElementById('purchaseLines');
            var tpl = document.getElementById('purchaseLineTemplate');
            // Both triggers — the one above the table and the one under the
            // last line. Selected by class so the two can never drift apart.
            var addBtns = form.querySelectorAll('.js-add-line');
            var grand = document.getElementById('grandTotal');

            function rows() {
                return Array.prototype.slice.call(body.querySelectorAll('tr.purchase-line'));
            }

            // Names carry the array index, so they are rewritten whenever a row
            // is removed — otherwise items[] would arrive with a gap.
            function renumber() {
                rows().forEach(function (row, i) {
                    row.querySelector('.js-line-number').textContent = i + 1;

                    row.querySelectorAll('[name]').forEach(function (field) {
                        field.name = field.name.replace(/items\[\d+\]/, 'items[' + i + ']');
                    });
                });

                // One line left means nothing to remove — a purchase needs at
                // least one item.
                var only = rows().length === 1;
                rows().forEach(function (row) { row.querySelector('.js-line-remove').disabled = only; });
            }

            function total() {
                var sum = 0;
                rows().forEach(function (row) {
                    var q = parseFloat(row.querySelector('.js-line-qty').value) || 0;
                    var p = parseFloat(row.querySelector('.js-line-price').value) || 0;
                    sum += q * p;
                });
                grand.value = sum.toFixed(2);
            }

            function wire(row) {
                var select = row.querySelector('.js-line-item');
                var uom = row.querySelector('.js-line-uom');
                var category = row.querySelector('.js-line-category');

                // Uom and Category follow the item; both are display-only.
                select.addEventListener('change', function () {
                    var opt = select.options[select.selectedIndex];
                    uom.value = opt ? (opt.dataset.uom || '') : '';
                    category.value = opt ? (opt.dataset.category || '') : '';
                });

                row.querySelector('.js-line-qty').addEventListener('input', total);
                row.querySelector('.js-line-price').addEventListener('input', total);

                row.querySelector('.js-line-remove').addEventListener('click', function () {
                    if (rows().length === 1) { return; }
                    row.remove();
                    renumber();
                    total();
                });

                // Wire up the searchable dropdown this row just gained.
                if (window.gxInitSearchable) { window.gxInitSearchable(row); }
            }

            function addRow() {
                var index = rows().length;
                var html = tpl.innerHTML.replace(/__INDEX__/g, index);
                var holder = document.createElement('tbody');
                holder.innerHTML = html.trim();

                var row = holder.querySelector('tr');
                body.appendChild(row);
                wire(row);
                renumber();
                total();

                return row;
            }

            addBtns.forEach(function (btn) { btn.addEventListener('click', addRow); });

            // Start with one empty line, the way the single-item form opened.
            addRow();
        })();

        // "View Items" / "Hide Items" — the label has to say what the button
        // will do next, so it flips with the panel.
        (function () {
            document.querySelectorAll('[data-rv-toggle]').forEach(function (btn) {
                var target = document.querySelector(btn.dataset.bsTarget);
                var label = btn.querySelector('[data-rv-toggle-text]');
                if (!target || !label) { return; }

                target.addEventListener('shown.bs.collapse', function () { label.textContent = 'Hide Items'; });
                target.addEventListener('hidden.bs.collapse', function () { label.textContent = 'View Items'; });
            });
        })();
    </script>
</div>
@endsection
