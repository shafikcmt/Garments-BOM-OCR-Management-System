@extends('layouts.app')

@section('title', 'General Stock — Receiving')

@php
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);

    // Drives the Clear button only — the filtering itself is the controller's.
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // Item lines as they were last submitted, so a rejected receiving comes
    // back filled in rather than blank — and so a resumed draft, which is put
    // into old() by the same route, brings its lines with it.
    $oldPurchaseLines = collect(old('items', []))
        ->map(fn ($line) => [
            'stock_item_id' => $line['stock_item_id'] ?? '',
            'qty' => $line['qty'] ?? '',
            'unit_price' => $line['unit_price'] ?? '',
            'remarks' => $line['remarks'] ?? '',
        ])
        ->values();
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Receiving'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="truck" eyebrow="General Stock" title="Receiving"
                   copy="Goods received against a challan, recorded one delivery at a time.">
        <x-slot:actions>
            <a href="{{ route('store.stock.purchases.report') }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-bar-graph me-1" aria-hidden="true"></i>Report
            </a>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importReceivingModal">
                <i class="bi bi-upload me-1" aria-hidden="true"></i>Import
            </button>
            <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Stock Report</a>
            <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Items</a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    {{-- Per-delivery outcome of a bulk import, in the same three-block shape the
         item-master import uses. Errors name the challans that were left out —
         the rest of the file still went in, so they are a to-do list, not a
         failure notice. --}}
    @foreach ([
        ['key' => 'import_errors', 'tone' => 'danger', 'icon' => 'x-circle-fill', 'heading' => 'These deliveries were NOT imported. Correct them and upload the file again:'],
        ['key' => 'import_skipped', 'tone' => 'warning', 'icon' => 'exclamation-triangle-fill', 'heading' => 'These were skipped:'],
        ['key' => 'import_notes', 'tone' => 'info', 'icon' => 'info-circle-fill', 'heading' => 'Imported, with these notes:'],
    ] as $report)
        @if(session($report['key']))
            <div class="alert alert-{{ $report['tone'] }} d-flex align-items-start gap-2" role="alert">
                <i class="bi bi-{{ $report['icon'] }}" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold mb-1">{{ $report['heading'] }}</div>
                    <ul class="mb-0 ps-3 small" style="max-height:220px; overflow-y:auto;">
                        @foreach(session($report['key']) as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    @endforeach

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
                    {{-- Which draft this form came from, so saving again updates
                         it instead of leaving a second copy, and recording it for
                         real clears it away. Empty on a form that was not
                         resumed. --}}
                    <input type="hidden" name="draft_id" value="{{ old('draft_id') }}">
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
                            {{-- Follows the Challan Date. The "auto" tag says so on
                                 the label, because the muted fill alone was not
                                 stopping people clicking in and waiting. --}}
                            <label class="form-label" for="monthLabel">Month <span class="gx-stock-auto">auto</span></label>
                            <input type="text" id="monthLabel" class="form-control gx-stock-readonly" readonly tabindex="-1">
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            {{-- Allocated by the system on save. Shown as a preview,
                                 never typed: whoever saves first takes this number,
                                 so it is not promised to this form. --}}
                            <label class="form-label" for="rvPreview">GRN No <span class="gx-stock-auto">auto</span></label>
                            <input type="text" id="rvPreview" class="form-control gx-stock-readonly" readonly tabindex="-1" value="{{ $nextRv }}">
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <label class="form-label" for="challanNo">Challan / Invoice No</label>
                            <input name="challan_no" id="challanNo" value="{{ old('challan_no') }}" class="form-control" maxlength="100"
                                   data-challan-status-url="{{ route('store.stock.purchases.challan-status') }}"
                                   autocomplete="off">
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

                    {{-- Already recorded under this challan number.

                         Nothing else catches a delivery entered twice: every
                         submission takes a fresh GRN by design, because the same
                         challan can genuinely arrive in two batches, so two
                         identical entries become two receivings and two
                         additions to stock with no complaint from anywhere.

                         WARNS, NEVER BLOCKS, and says so — a partial receipt
                         against a challan already recorded is a real thing to be
                         doing. Filled by the script at the foot of this file. --}}
                    <div id="challanDuplicateNotice" class="gx-edit-alert mb-3 d-none" role="alert">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <div>
                            <div class="gx-edit-alert-title">This challan number is already recorded</div>
                            <div class="gx-edit-alert-body" data-challan-matches></div>
                            <div class="gx-edit-alert-body mt-1">
                                Carry on if this is a further delivery against the same challan. If it is the
                                same goods being entered a second time, the stock will be counted twice.
                            </div>
                        </div>
                    </div>

                    {{-- One "Add Another Item", not two. It sits under the last
                         line rather than up here, because that is where the
                         operator's cursor already is once a line is filled in —
                         a button by this heading sends them back up the form
                         after every item. --}}
                    <div class="mb-2">
                        <h6 class="gx-stock-subhead mb-0">Items</h6>
                        <span class="gx-stock-help">Everything received on this challan. All lines share the GRN No above.</span>
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
                                {{-- Amount. Same width as Purchased Qty, so the
                                     two figures either side of Unit Price line
                                     up rather than one column drifting wider. --}}
                                <col style="width:120px;">
                                <col style="width:104px;">
                            </colgroup>
                            <thead>
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Item Name <span class="text-danger">*</span></th>
                                    <th>Uom <span class="gx-stock-auto">auto</span></th>
                                    <th>Category <span class="gx-stock-auto">auto</span></th>
                                    {{-- Two brands can share an item name, so without
                                         this the operator cannot tell which one they
                                         picked until after saving. --}}
                                    <th style="min-width:140px;">Brand/Specification <span class="gx-stock-auto">auto</span></th>
                                    <th>Purchased Qty <span class="text-danger">*</span></th>
                                    <th>Unit Price</th>
                                    {{-- Qty x Unit Price, filled in by the script.
                                         Carries the same "auto" badge as Uom,
                                         Category and Brand: it is derived and
                                         read-only, and that badge is already how
                                         this form says so. --}}
                                    <th class="text-end">Amount <span class="gx-stock-auto">auto</span></th>
                                    <th>Remarks</th>
                                    <th class="text-end gx-stock-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody id="purchaseLines"></tbody>
                            {{-- The sum sits under Amount, the column it actually
                                 totals. It used to sit under Unit Price, which
                                 was the nearest money column before Amount
                                 existed; a total standing under the per-unit rate
                                 rather than under the line values it adds up now
                                 reads as the wrong sum. --}}
                            <tfoot>
                                <tr>
                                    {{-- 7, not 6: Unit Price joined the span when
                                         the total moved one column right. --}}
                                    <td colspan="7" class="text-end gx-stock-total-label">Total Value</td>
                                    <td>
                                        <input type="text" id="grandTotal" class="form-control form-control-sm gx-stock-readonly fw-bold text-end" readonly tabindex="-1" value="0.00">
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    {{-- Directly under the last line, which is where the operator
                         is looking once they have filled one in. Bound by class —
                         see the .js-add-line binding in the script below — so this
                         still works whether there is one of them or several. --}}
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary js-add-line" id="addPurchaseLine">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                        </button>
                        {{-- Beside the single-item button, not instead of it.
                             One item at a time is still the quicker way to add
                             one item; this is for the delivery that arrives with
                             a dozen. --}}
                        <button type="button" class="btn btn-sm btn-outline-secondary js-add-many" id="addPurchaseLines">
                            <i class="bi bi-list-check me-1" aria-hidden="true"></i>Add Multiple Items
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
                                        <option value="{{ $it->id }}" data-uom="{{ $it->uom }}" data-category="{{ $it->category }}" data-brand="{{ $it->brand }}">{{ $it->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            {{-- Derived from the item, never posted. --}}
                            <td><input type="text" class="form-control form-control-sm js-line-uom gx-stock-readonly text-center" readonly tabindex="-1" placeholder="—"></td>
                            <td><input type="text" class="form-control form-control-sm js-line-category gx-stock-readonly" readonly tabindex="-1" placeholder="—"></td>
                            <td><input type="text" class="form-control form-control-sm js-line-brand gx-stock-readonly" readonly tabindex="-1" placeholder="—"></td>
                            {{-- Numeric, but NOT type="number".
                                 A number input refuses to report a caret
                                 position — selectionStart reads null and
                                 setSelectionRange throws — so there is no way to
                                 tell "cursor is at the end of the figure" from
                                 "cursor is in the middle of it", and Left/Right
                                 could not move between fields without breaking
                                 the typing of a figure.
                                 The three guards that type="number" was giving
                                 are kept: `required` is still native, `pattern`
                                 refuses a value that is not a plain figure, and
                                 data-min / data-step are enforced by the shared
                                 script in _line-grid, which also blocks a
                                 non-numeric keystroke before it lands. The
                                 SERVER's rules are untouched and remain the
                                 thing that actually decides. inputmode keeps the
                                 numeric keypad on a tablet. --}}
                            <td><input type="text" inputmode="decimal" pattern="\d*\.?\d*" data-step="0.0001" data-min="0.0001" class="form-control form-control-sm js-line-qty js-numeric" name="items[__INDEX__][qty]" required placeholder="0"></td>
                            <td><input type="text" inputmode="decimal" pattern="\d*\.?\d*" data-step="0.0001" data-min="0" class="form-control form-control-sm js-line-price js-numeric" name="items[__INDEX__][unit_price]" placeholder="0.00"></td>
                            {{-- Qty x Unit Price. No name attribute — it is
                                 derived on the server from the two fields that
                                 ARE posted, so posting it would invite the two to
                                 disagree. readonly + tabindex="-1" matches Uom,
                                 Category and Brand: display only, and skipped by
                                 Tab so it never interrupts typing a line. --}}
                            <td><input type="text" class="form-control form-control-sm js-line-amount gx-stock-readonly text-end" readonly tabindex="-1" value="0.00"></td>
                            <td><input type="text" class="form-control form-control-sm js-line-remarks" name="items[__INDEX__][remarks]" maxlength="1000" placeholder="Optional"></td>
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
                        {{-- Same form, a different action. formnovalidate lets a
                             half-filled form through, which is the point: a
                             draft exists because somebody was interrupted.
                             Nothing on that path writes stock or takes a GRN. --}}
                        <button type="submit" class="btn btn-outline-secondary"
                                formaction="{{ route('store.stock.purchases.drafts.save') }}"
                                formnovalidate>
                            <i class="bi bi-bookmark me-1" aria-hidden="true"></i>Save Draft
                        </button>
                        <p class="gx-stock-help mb-0" style="max-width:600px;">
                            The GRN No is generated on save and shared by every line on this challan.
                            Each line is recorded as its own purchase against its item.
                        </p>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- Half-finished forms, this user's own.

         Between the form and the history on purpose: it belongs to the act of
         recording a receiving, not to the record of ones already made. Only
         rendered when there is something in it.

         A draft is NOT a receiving. Nothing here has touched stock and nothing
         here holds a GRN — the number is taken on save, so a draft has none to
         show and is named by the challan instead. --}}
    @if($drafts->isNotEmpty())
        <div class="card gx-stock-card mb-4">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5>Saved Drafts <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $drafts->count() }}</span></h5>
                </div>
                <p class="gx-edit-hint mt-0 mb-3">
                    Unfinished Record Receiving forms you saved. Nothing here has been received, no stock
                    has moved, and no GRN has been taken — resume one to carry on where you left off.
                </p>
                <div class="table-responsive">
                    <table class="table align-middle gx-stock-table mb-0">
                        <thead>
                            <tr>
                                <th style="min-width:240px;">Draft</th>
                                <th>Last saved</th>
                                <th class="text-end gx-stock-actions">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($drafts as $draft)
                                <tr>
                                    <td class="fw-semibold text-slate-900">{{ $draft->label ?: 'Untitled draft' }}</td>
                                    <td class="small text-muted">{{ $draft->updated_at?->format('d-M-Y H:i') }}</td>
                                    <td class="text-end gx-stock-actions gx-row-actions">
                                        {{-- POST, not a link: it changes what the form
                                             will show, and a prefetcher must not fire it. --}}
                                        <form method="POST" action="{{ route('store.stock.purchases.drafts.resume', $draft) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Resume
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('store.stock.purchases.drafts.destroy', $draft) }}" class="d-inline"
                                              onsubmit="return confirm(@js('Delete this draft? '.($draft->label ?: 'Untitled draft').' has not been received, so nothing recorded is affected — but what was typed into it is gone.'));">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         Purchase History — one row per goods-receiving event, expandable.
         ------------------------------------------------------------------ --}}
    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5>
                    Purchase History
                    <span class="badge bg-primary-subtle text-primary ms-1"><span data-list-count>{{ $groups->total() }}</span></span>
                    {{-- Beside the count it belongs to, so the feedback is where
                         the user is already looking while typing. --}}
                    <span class="spinner-border spinner-border-sm text-primary ms-2 d-none" role="status" data-list-spinner>
                        <span class="visually-hidden">Updating the list…</span>
                    </span>
                </h5>
            </div>

            {{-- Stays a plain GET form with a working Filter button: with JS
                 off, or if a fetch fails, submitting reloads the page with the
                 same filters. The script is an enhancement on top of it, and it
                 reads this form as the single source of truth for the query —
                 so a control added here later needs no JS change.

                 The action is named explicitly. A GET form with none posts back
                 to the current URL, query string and all, which would fold the
                 previous page number into every new search. --}}
            <form method="GET" action="{{ route('store.stock.purchases.index') }}"
                  class="row g-3 gx-stock-filter mb-4" data-list-filters>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="purchaseFilterMonth">Month</label>
                    <input type="month" id="purchaseFilterMonth" name="month" value="{{ $filters['month'] ?? '' }}" class="form-control">
                </div>
                {{-- Search takes the room the row has left rather than stopping
                     at a third of it and stranding the rest. --}}
                <div class="col-6 col-md-7">
                    <label class="form-label" for="purchaseFilterSearch">Search</label>
                    <input id="purchaseFilterSearch" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control"
                           placeholder="Item, challan, GRN, supplier">
                </div>
                <div class="col-12 col-md-2 gx-stock-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>
            {{-- Swapped wholesale by stock-list-table.js. Everything keyed to
                 the rows on screen — including the per-line Edit dialogs —
                 lives inside the partial, so a filter or a page change replaces
                 them together. --}}
            <div data-list-table>
                @include('store.stock._purchases-list')
            </div>
        </div>
    </div>

    {{-- Bulk receiving upload. Same shape as the item-master import modal, so
         the two screens are learnt once. --}}
    <div class="modal fade" id="importReceivingModal" tabindex="-1" aria-labelledby="importReceivingLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importReceivingLabel">Import Receiving</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="gx-stock-help mb-3">
                        Upload many deliveries at once. Rows sharing the same Challan No, Challan Date and
                        Supplier are recorded together as one delivery, and each gets its own GRN No on save.
                    </p>

                    <a href="{{ route('store.stock.purchases.template') }}" class="btn btn-outline-secondary w-100 mb-3">
                        <i class="bi bi-download me-1" aria-hidden="true"></i>Download Sample Template
                    </a>

                    <form method="POST" action="{{ route('store.stock.purchases.import') }}" enctype="multipart/form-data" id="importReceivingForm">
                        @csrf
                        <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required
                               aria-label="CSV or Excel file of receivings to import">
                        <p class="gx-stock-help mb-0">
                            Challan Date, Item Name and Purchased Qty are required on every row. Item names must
                            already exist under Items. Blank RCV Date follows the Challan Date. The file's GRN No,
                            Month, Uom, Category and Total Value are read for checking only — the item master and
                            this system's own GRN numbering are used. A delivery already in Purchase History is
                            skipped, so re-uploading a corrected file is safe.
                        </p>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="importReceivingForm" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import Receiving</button>
                </div>
            </div>
        </div>
    </div>

    @include('store.stock._searchable')
    @include('store.stock._line-grid')

    <style>
        {{-- The item-lines table (.gx-line-scroll / .gx-line-table / .gx-line-no
             / .gx-line-action) was declared here AND on the Record Issue screen,
             and the two copies had already drifted — one had a row hover, the
             other did not. Both now read the one definition in _stock-ui. Only
             what is genuinely local to this screen is left below. --}}

        {{-- The expanded lines of one receiving.

             This block used to be six rules of !important, all of them fighting
             the app-wide table rule in components.css: the bordered pill on each
             cell, the drop shadow, the white fill, the centred text, the hover
             lift. Both .gx-line-table and .gx-stock-table are now listed in that
             rule's own opt-out chain, so none of that reaches here any more and
             the workarounds are gone with it. What is left is only what this
             screen actually wants. --}}

        /* Written as a child of .gx-stock-table so it outweighs that table's own
           row rules in _stock-ui without needing !important to do it. */
        .gx-stock-table > tbody > tr.gx-rv-detail > td {
            border-bottom: 0;
            background: #f8fafc;
        }

        .gx-rv-detail-body { padding: .55rem .35rem .65rem; }

        /* The lines sit on the panel's own grey rather than a white band. */
        .gx-rv-detail .gx-line-table > tbody > tr > td { background: transparent; }
        .gx-rv-detail .gx-line-table > tbody > tr:hover > td { background: rgba(255, 255, 255, .6); }
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

            // The lines to open with: what a rejected submission carried, or
            // what a resumed draft was saved with. Both arrive through old().
            var oldLines = @json($oldPurchaseLines);

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

            // Every line's Amount, then the sum of them.
            //
            // ONE pass, deliberately: the row figures and the total are computed
            // from the same multiplication in the same loop, so the column can
            // never add up to something other than the box under it. The total
            // used to be its own sum of q * p with no per-row figure to check it
            // against; now the operator can see both, which means they have to
            // agree.
            //
            // Rounding happens once, at display. Each Amount is rounded to two
            // decimals for the screen, but the SUM is taken from the unrounded
            // products — totalling the rounded figures instead would drift by a
            // paisa or two over a long receiving and leave a total that does not
            // match the column the operator can add up by hand. Two decimals
            // throughout, matching Unit Price and the existing Total Value.
            function total() {
                var sum = 0;

                rows().forEach(function (row) {
                    var q = parseFloat(row.querySelector('.js-line-qty').value) || 0;
                    var p = parseFloat(row.querySelector('.js-line-price').value) || 0;
                    var amount = q * p;

                    sum += amount;

                    var box = row.querySelector('.js-line-amount');
                    if (box) { box.value = amount.toFixed(2); }
                });

                grand.value = sum.toFixed(2);
            }

            function wire(row) {
                var select = row.querySelector('.js-line-item');
                var uom = row.querySelector('.js-line-uom');
                var category = row.querySelector('.js-line-category');
                var brand = row.querySelector('.js-line-brand');

                // Uom, Category and Brand follow the item; all three are
                // display-only. An item with no brand leaves the box empty and
                // its placeholder shows the em dash, same as the other two.
                select.addEventListener('change', function () {
                    var opt = select.options[select.selectedIndex];
                    uom.value = opt ? (opt.dataset.uom || '') : '';
                    category.value = opt ? (opt.dataset.category || '') : '';
                    brand.value = opt ? (opt.dataset.brand || '') : '';
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

            function addRow(values) {
                var index = rows().length;
                var html = tpl.innerHTML.replace(/__INDEX__/g, index);
                var holder = document.createElement('tbody');
                holder.innerHTML = html.trim();

                var row = holder.querySelector('tr');

                // Values go in BEFORE wire(), because wire() hands the item
                // select to TomSelect and it reads whatever is selected at that
                // moment as its starting value.
                if (values) {
                    var set = function (selector, value) {
                        var field = row.querySelector(selector);
                        if (field && value !== undefined && value !== null && value !== '') {
                            field.value = value;
                        }
                    };

                    set('.js-line-item', values.stock_item_id);
                    set('.js-line-qty', values.qty);
                    set('.js-line-price', values.unit_price);
                    set('[name$="[remarks]"]', values.remarks);
                }

                body.appendChild(row);
                wire(row);

                // Uom, Category and Brand follow the item through its change
                // event, and setting .value in code does not fire one — so a
                // resumed line showed its item with the three boxes beside it
                // still empty. Nudged once, after wiring, so a restored row
                // reads exactly like one the user picked.
                if (values && values.stock_item_id) {
                    row.querySelector('.js-line-item').dispatchEvent(new Event('change', { bubbles: true }));
                }

                renumber();
                total();

                return row;
            }

            addBtns.forEach(function (btn) { btn.addEventListener('click', function () { addRow(); }); });

            /*
             * Add several items in one go.
             *
             * The picker hands back one id at a time and each goes through
             * addRow({stock_item_id}) — the same call a resumed draft uses, and
             * the one that already nudges the item select's change event so Uom,
             * Category and Brand fill in exactly as they do for a hand-picked
             * item. Qty, Unit Price and Remarks are left blank, and the running
             * total is recalculated by addRow itself.
             *
             * Single-item search on the row is untouched: this is a second way
             * in, not a replacement.
             *
             * The list is read from the row template's own <option> elements
             * rather than from a second copy of the item master, so the picker
             * cannot offer something the row select does not have. Brand comes
             * from data-brand because the option's LABEL here is the bare name —
             * two brands of one item look identical in the dropdown, and in a
             * list of tick boxes that is how the wrong one gets added.
             */
            if (window.gxItemPicker) {
                window.gxItemPicker({
                    trigger: form.querySelectorAll('.js-add-many'),
                    title: 'Add Multiple Items',
                    items: function () {
                        return Array.prototype.slice
                            .call(tpl.content.querySelectorAll('.js-line-item option'))
                            .filter(function (option) { return option.value !== ''; })
                            .map(function (option) {
                                return {
                                    value: option.value,
                                    name: option.textContent.trim(),
                                    brand: option.dataset.brand || '',
                                    uom: option.dataset.uom || '',
                                    category: option.dataset.category || '',
                                };
                            });
                    },
                    onPick: function (id) { addRow({ stock_item_id: id }); },
                });
            }

            // Spreadsheet-style arrow keys, in the order Tab already visits the
            // fields. Uom, Category, Brand and Amount are readonly with
            // tabindex="-1", so they are not in the list for the same reason Tab
            // skips them. The Tab-to-add-row handler below is a separate
            // listener and is unaffected.
            if (window.gxArrowNav) {
                window.gxArrowNav({
                    body: body,
                    row: 'tr.purchase-line',
                    fields: ['.js-line-item', '.js-line-qty', '.js-line-price', '.js-line-remarks'],
                });
            }

            /*
             * Tab out of the last line's Remarks to start the next line.
             *
             * For an operator typing a delivery note straight in, the row ends
             * at Remarks and the next thing they want is another row — reaching
             * for the mouse to click "Add Another Item" between every line is
             * the slow part of this screen.
             *
             * FOUR conditions, and each one is what keeps normal tabbing intact:
             *
             *   plain Tab       no Shift, no Ctrl/Alt/Meta. Shift+Tab must move
             *                   backwards as it always has and must never add a
             *                   row; a modifier combination belongs to the
             *                   browser.
             *   Remarks         the last field of the line. Tab anywhere else
             *                   moves within the row, untouched.
             *   the LAST row    tabbing out of an earlier row's Remarks still
             *                   moves into that row's Remove button exactly as
             *                   before. Only the bottom of the grid grows.
             *   nothing pending no IME composition in progress.
             *
             * Delegated from the tbody rather than bound per row, so a row added
             * by this handler, by the button, or by a resumed draft all behave
             * the same with nothing to re-bind.
             *
             * The button is untouched and still works; both call addRow().
             */
            body.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) {
                    return;
                }

                if (event.isComposing) { return; }

                var field = event.target;
                if (!field || !field.classList || !field.classList.contains('js-line-remarks')) { return; }

                var row = field.closest('tr.purchase-line');
                var all = rows();
                if (!row || row !== all[all.length - 1]) { return; }

                // Only from here on is the browser's own Tab replaced.
                event.preventDefault();

                focusItem(addRow());
            });

            /**
             * Put the cursor in a new row's Item Name.
             *
             * The select is a TomSelect on this form, and a TomSelect hides the
             * original <select> — calling focus() on it puts the cursor nowhere
             * and the operator's next keystroke goes to the page. The instance
             * hangs off the element as .tomselect once initialised, so ask it
             * when it is there and fall back to the plain element when it is not.
             */
            function focusItem(row) {
                var select = row && row.querySelector('.js-line-item');
                if (!select) { return; }

                if (select.tomselect) {
                    select.tomselect.focus();

                    return;
                }

                select.focus();
            }

            // Come back with the lines that were typed, not an empty grid.
            //
            // This form used to open with one blank row whatever had just
            // happened, so a rejected receiving lost every line the operator had
            // entered and a resumed draft would have come back with only its
            // header. Both are fixed by the same few lines: old() carries a
            // rejected submission, and a resumed draft is put into old() too.
            if (oldLines.length) {
                oldLines.forEach(function (line) { addRow(line); });
            } else {
                addRow();
            }
        })();

        // "View Items" / "Hide Items" — the label has to say what the button
        // will do next, so it flips with the panel.
        //
        // DELEGATED FROM THE DOCUMENT, not bound to each button. The rows are
        // swapped wholesale on every search and every page change, so listeners
        // attached to the panes that happened to exist at page load would stop
        // firing the moment the table was replaced — the panes would still open
        // and close, and every button would go on saying "View Items" while its
        // pane stood open.
        //
        // Bootstrap's collapse events bubble, so one listener on the document
        // reaches every pane there will ever be, including ones fetched later,
        // and there is nothing to re-run after a swap.
        (function () {
            var flip = function (event, text) {
                var pane = event.target;
                if (!pane || !pane.id) { return; }

                // The button controlling this pane, found from the pane rather
                // than remembered — the button may itself be newly swapped in.
                var btn = document.querySelector('[data-rv-toggle][data-bs-target="#' + pane.id + '"]');
                var label = btn && btn.querySelector('[data-rv-toggle-text]');

                if (label) { label.textContent = text; }
            };

            document.addEventListener('shown.bs.collapse', function (e) { flip(e, 'Hide Items'); });
            document.addEventListener('hidden.bs.collapse', function (e) { flip(e, 'View Items'); });
        })();
    </script>

    {{-- Warn when the challan number has been recorded before.

         Debounced, because it fires as the number is typed and a challan is
         short enough that every keystroke would otherwise be a request. Scoped
         to the chosen supplier when there is one — the same number from two
         different suppliers is a coincidence, and warning about it would teach
         people to ignore this.

         A failed lookup shows nothing. This is advice on top of the form, and
         it must never be the reason a delivery cannot be recorded. --}}
    <script>
        (function () {
            var input = document.getElementById('challanNo');
            var notice = document.getElementById('challanDuplicateNotice');

            if (!input || !notice) { return; }

            var body = notice.querySelector('[data-challan-matches]');
            var supplier = document.getElementById('supplierSelect');
            var timer = null;
            var seq = 0;

            function hide() { notice.classList.add('d-none'); }

            function show(matches) {
                body.innerHTML = '';

                matches.forEach(function (m) {
                    var line = document.createElement('span');
                    var bits = ['GRN ' + m.rv_no];

                    if (m.purchase_date) { bits.push(m.purchase_date); }
                    if (m.supplier_name) { bits.push(m.supplier_name); }
                    bits.push(m.lines + ' item' + (m.lines === 1 ? '' : 's') + ', qty ' + m.qty);

                    // textContent, not innerHTML: these are supplier and item
                    // names out of the database.
                    line.textContent = bits.join(' · ');
                    body.appendChild(line);
                });

                notice.classList.remove('d-none');
            }

            function check() {
                var value = input.value.trim();

                if (!value) { hide(); return; }

                var mine = ++seq;
                var url = new URL(input.dataset.challanStatusUrl, window.location.origin);

                url.searchParams.set('challan_no', value);

                var supplierId = supplier
                    ? (supplier.tomselect ? supplier.tomselect.getValue() : supplier.value)
                    : '';

                if (supplierId) { url.searchParams.set('general_stock_supplier_id', supplierId); }

                fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        // A slower earlier request must not overwrite a newer answer.
                        if (mine !== seq) { return; }

                        if (data && data.matches && data.matches.length) { show(data.matches); }
                        else { hide(); }
                    })
                    // Advice only — a lookup that fails says nothing at all.
                    .catch(hide);
            }

            function queue() {
                window.clearTimeout(timer);
                timer = window.setTimeout(check, 400);
            }

            input.addEventListener('input', queue);
            input.addEventListener('blur', check);

            if (supplier) { supplier.addEventListener('change', check); }

            // A form that came back filled in — rejected, or resumed from a
            // draft — should show the warning without waiting to be retyped.
            if (input.value.trim()) { check(); }
        })();
    </script>

    {{-- A refused correction closes the dialog it was typed in. Reopen it,
         still carrying what was entered and saying why it was refused. The
         hidden `form` field names which one. --}}
    @php
        $reopenEdit = str_starts_with((string) old('form'), 'edit:')
            ? 'editPurchase'.substr((string) old('form'), 5)
            : null;
    @endphp

    @if($errors->any() && $reopenEdit)
        <script>
            (function () {
                var el = document.getElementById(@json($reopenEdit));
                if (el && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(el).show();
                }
            })();
        </script>
    @endif
</div>
@endsection
