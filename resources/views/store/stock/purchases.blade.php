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
                                    <th>Remarks</th>
                                    <th class="text-end gx-stock-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody id="purchaseLines"></tbody>
                            {{-- The sum sits in the Unit Price column, where the
                                 money on every line above it already is. It used
                                 to span the last three columns, which made the
                                 box twice the width of anything it was totalling
                                 and left the label stranded far to its left. --}}
                            <tfoot>
                                <tr>
                                    {{-- 6, not 5: the Brand/Specification column
                                         sits inside this span. --}}
                                    <td colspan="6" class="text-end gx-stock-total-label">Total Value</td>
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
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-primary js-add-line" id="addPurchaseLine">
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
                                        <option value="{{ $it->id }}" data-uom="{{ $it->uom }}" data-category="{{ $it->category }}" data-brand="{{ $it->brand }}">{{ $it->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            {{-- Derived from the item, never posted. --}}
                            <td><input type="text" class="form-control form-control-sm js-line-uom gx-stock-readonly text-center" readonly tabindex="-1" placeholder="—"></td>
                            <td><input type="text" class="form-control form-control-sm js-line-category gx-stock-readonly" readonly tabindex="-1" placeholder="—"></td>
                            <td><input type="text" class="form-control form-control-sm js-line-brand gx-stock-readonly" readonly tabindex="-1" placeholder="—"></td>
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
                <h5>Purchase History <span class="badge bg-primary-subtle text-primary ms-1">{{ $groups->total() }}</span></h5>
            </div>

            <form method="GET" class="row g-3 gx-stock-filter mb-4">
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

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th style="min-width:140px;">GRN No</th>
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
                                //
                                // Four OR five digits: the sequence was narrowed from
                                // five to four, and the numbers already issued keep the
                                // width they were issued with. Matching only one width
                                // would label half the generated numbers as legacy.
                                $isLegacy = ! preg_match('/^[A-Z]{3}\d{2}-\d{4,5}$/', (string) $group->rv_no);
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $group->rv_no ?: '—' }}</div>
                                    @if($isLegacy && $group->rv_no)
                                        <div class="gx-stock-spec">entered before GRN numbering</div>
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
                                    <button type="button" class="btn btn-sm btn-outline-primary"
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
                                            {{-- What the actions below reach.

                                                 A "delivery" is not a record — it is these rows,
                                                 grouped by GRN, challan and challan date. So every
                                                 action in this pane is per LINE, while two of the
                                                 fields on show above are shared by all of them. The
                                                 Edit dialog already says so once it is open; this
                                                 says it before anything is clicked, in the same
                                                 amber the dialog uses for the identical point. --}}
                                            @if($groupLines->count() > 1)
                                                <div class="gx-line-scope mb-2">
                                                    <span class="gx-edit-scope">All {{ $groupLines->count() }} items</span>
                                                    <span>
                                                        RCV Date and Supplier are shared by every item on this GRN.
                                                        Qty, Unit Price and Remarks belong to one line, and so do
                                                        Edit and Remove below.
                                                    </span>
                                                </div>
                                            @endif
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
                                                            {{-- Edit and Delete are Admin / Management
                                                                 rights (store.receiving.edit / .delete,
                                                                 or the flat store.edit / store.delete);
                                                                 the controller enforces the same checks
                                                                 server-side. Both act on this one line,
                                                                 not the whole receiving. --}}
                                                            <td class="text-end gx-stock-actions gx-row-actions">
                                                                @if($canEdit)
                                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPurchase{{ $line->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                                                @endif
                                                                @if($canDelete)
                                                                    {{-- "Remove", not "Delete": this takes one item
                                                                         OUT OF a delivery, and Delete beside a GRN
                                                                         number reads as removing the whole thing.
                                                                         Issue History keeps "Delete" — an issue is a
                                                                         record in its own right, not part of a set.

                                                                         The confirm has to branch, because on the LAST
                                                                         line the old wording was simply untrue. There
                                                                         is no delivery record to keep: the group is
                                                                         these rows, so removing the last one takes the
                                                                         GRN and challan with it. --}}
                                                                    @php
                                                                        $itemLabel = optional($line->stockItem)->name ?? 'this item';
                                                                        $grnLabel = $line->rv_no ?: 'this receiving';
                                                                        $others = $groupLines->count() - 1;

                                                                        $confirmText = $others > 0
                                                                            ? 'Remove '.$itemLabel.' from GRN '.$grnLabel.'? The other '
                                                                                .($others === 1 ? 'item on this receiving is kept.' : $others.' items on this receiving are kept.')
                                                                            : $itemLabel.' is the only item on GRN '.$grnLabel
                                                                                .'. Removing it deletes the whole receiving, including its GRN and challan record. Continue?';
                                                                    @endphp
                                                                    <form method="POST" action="{{ route('store.stock.purchases.destroy', $line) }}" class="d-inline"
                                                                          onsubmit="return confirm(@js($confirmText));">
                                                                        @csrf @method('DELETE')
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button>
                                                                    </form>
                                                                @endif
                                                                @if(! $canEdit && ! $canDelete)
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

    {{-- Correct one line of a recorded receiving.

         Rendered outside the history table rather than inside the collapse pane
         each Edit button sits in: a dialog nested in a <td> inherits the table's
         own overflow and stacking, and the pane it lives in can be collapsed
         while the dialog is open.

         THREE FIELDS IDENTIFY THE DELIVERY and are shown locked — GRN No,
         Challan No and Challan Date are what group these lines into one
         receiving, on this screen and in the Receiving Report alike. Two more,
         RCV Date and Supplier, describe the delivery rather than the line and
         are written to every line of it; they are marked so nobody is surprised
         by a change reaching rows they did not open. --}}
    @if($canEdit)
        @foreach($lines->flatten() as $line)
            @php
                // old() is global; this marker keeps a rejected edit from
                // repopulating every other dialog on the page.
                $formKey = 'edit:'.$line->id;
                $wasSubmitted = old('form') === $formKey;
                $value = fn (string $field, $stored = null) => $wasSubmitted ? old($field, $stored) : $stored;
                $lineQty = rtrim(rtrim(number_format((float) $line->qty, 4, '.', ''), '0'), '.');
                $siblingCount = $lines->get($line->group_key)?->count() ?? 1;
            @endphp
            <div class="modal fade" id="editPurchase{{ $line->id }}" tabindex="-1" aria-labelledby="editPurchaseLabel{{ $line->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content gx-stock-card">
                        <form method="POST" action="{{ route('store.stock.purchases.update', $line) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="form" value="{{ $formKey }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editPurchaseLabel{{ $line->id }}">Edit Receiving Line</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @if($wasSubmitted && $errors->any())
                                    <div class="gx-edit-alert mb-3" role="alert">
                                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                        <div>
                                            <div class="gx-edit-alert-title">This change was not saved</div>
                                            <div class="gx-edit-alert-body">
                                                @foreach($errors->all() as $error)
                                                    <span>{{ $error }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Item<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ optional($line->stockItem)->name ?? '—' }}</span>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="form-label">GRN No<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ $line->rv_no ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label">Challan No<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ $line->challan_no ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label">Challan Date<span class="gx-lock-tag">Locked</span></label>
                                        <div class="gx-edit-locked">
                                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                                            <span class="gx-edit-locked-value">{{ optional($line->purchase_date)->format('d-M-Y') ?: '—' }}</span>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="gx-edit-hint">
                                            These three identify the delivery and group its items together. To correct
                                            one of them, delete this receiving and enter it again.
                                        </div>
                                    </div>

                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editPurchaseQty{{ $line->id }}">Purchased Qty <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0001" min="0.0001" id="editPurchaseQty{{ $line->id }}" name="qty" class="form-control @if($wasSubmitted && $errors->has('qty')) is-invalid @endif" required
                                               value="{{ $value('qty', $lineQty) }}">
                                        <div class="gx-edit-hint">
                                            {{ optional($line->stockItem)->uom ? 'In '.$line->stockItem->uom.'. ' : '' }}Cannot be cut below what has already been issued.
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editPurchasePrice{{ $line->id }}">Unit Price</label>
                                        <input type="number" step="0.0001" min="0" id="editPurchasePrice{{ $line->id }}" name="unit_price" class="form-control @if($wasSubmitted && $errors->has('unit_price')) is-invalid @endif"
                                               value="{{ $value('unit_price', $line->unit_price !== null ? rtrim(rtrim(number_format((float) $line->unit_price, 4, '.', ''), '0'), '.') : '') }}">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editPurchaseRcv{{ $line->id }}">
                                            RCV Date <span class="text-danger">*</span>
                                            @if($siblingCount > 1)<span class="gx-edit-scope">All {{ $siblingCount }} items</span>@endif
                                        </label>
                                        <input type="date" id="editPurchaseRcv{{ $line->id }}" name="rcv_date" class="form-control @if($wasSubmitted && $errors->has('rcv_date')) is-invalid @endif" required
                                               value="{{ $value('rcv_date', optional($line->rcv_date)->toDateString()) }}">
                                    </div>
                                    <div class="col-12 col-md-8">
                                        <label class="form-label" for="editPurchaseSupplier{{ $line->id }}">
                                            Supplier
                                            @if($siblingCount > 1)<span class="gx-edit-scope">All {{ $siblingCount }} items</span>@endif
                                        </label>
                                        <select id="editPurchaseSupplier{{ $line->id }}" name="general_stock_supplier_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($suppliers as $supplier)
                                                <option value="{{ $supplier->id }}" @selected($value('general_stock_supplier_id', $line->general_stock_supplier_id) == $supplier->id)>{{ $supplier->name }}</option>
                                            @endforeach
                                        </select>
                                        @if($siblingCount > 1)
                                            <div class="gx-edit-hint">
                                                RCV Date and Supplier describe the whole delivery, so a change here is
                                                written to all {{ $siblingCount }} items received under this GRN.
                                            </div>
                                        @endif
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="editPurchaseRemarks{{ $line->id }}">Remarks</label>
                                        <textarea id="editPurchaseRemarks{{ $line->id }}" name="remarks" class="form-control" rows="2" maxlength="1000">{{ $value('remarks', $line->remarks) }}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Update Line</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif

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
