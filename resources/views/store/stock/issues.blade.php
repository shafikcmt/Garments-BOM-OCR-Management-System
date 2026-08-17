@extends('layouts.app')

@section('title', 'General Stock — Issues')

@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

    // Drives the Clear button only — the filtering itself is the controller's.
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // Item lines as they were submitted, so a rejected requisition comes back
    // filled in rather than blank. The original array key is kept for the error
    // lookup, because that is what the validator's message keys point at.
    $oldLines = collect(old('items', []))
        ->map(fn ($line, $index) => [
            'stock_item_id' => $line['stock_item_id'] ?? '',
            'item_category_id' => $line['item_category_id'] ?? '',
            'qty' => $line['qty'] ?? '',
            'requisition_type' => $line['requisition_type'] ?? '',
            'remarks' => $line['remarks'] ?? '',
            'errors' => array_filter([
                'item' => $errors->first('items.'.$index.'.stock_item_id'),
                'qty' => $errors->first('items.'.$index.'.qty'),
                'category' => $errors->first('items.'.$index.'.item_category_id'),
                'type' => $errors->first('items.'.$index.'.requisition_type'),
            ]),
        ])
        ->values();

    // The item list handed to the script. Built here rather than inline in
    // @json, which cannot parse a multi-line array argument.
    $itemOptions = $items->map(function ($it) {
        $suffix = trim((string) $it->brand);

        return [
            'value' => (string) $it->id,
            'text' => $it->name.($suffix !== '' ? ' ('.$suffix.')' : ''),
            'uom' => (string) ($it->uom ?? ''),
            'category_id' => $it->item_category_id ? (string) $it->item_category_id : '',
            // Shown read-only on the line, so the brand stays visible after the
            // dropdown closes and its "Name (Brand)" label is out of sight.
            'brand' => $suffix,
        ];
    })->values();

    // A value the user typed rather than picked posts as "new:<name>" and
    // matches no option, so it needs one adding back or it is lost on reload.
    $typedOption = function (?string $value) {
        return is_string($value) && str_starts_with($value, 'new:')
            ? ['value' => $value, 'label' => substr($value, 4)]
            : null;
    };
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Issues'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="box-arrow-up" eyebrow="General Stock" title="Issues (Consumption)"
                   copy="What has left the store, against the requisition that asked for it.">
        <x-slot:actions>
            <a href="{{ route('store.stock.issues.report') }}" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-bar-graph me-1" aria-hidden="true"></i>Report
            </a>
            {{-- Import needs the same right as recording an issue by hand,
                 because that is what it does. Hidden rather than shown and
                 refused for a view-only user. --}}
            @can('store.issues.create')
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importIssuesModal">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Import
                </button>
            @endcan
            <a href="{{ route('store.stock.issue-setup.index') }}" class="btn btn-outline-secondary"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Issue Setup</a>
            <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Items</a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    {{-- What the bulk upload has to say for itself, in the same two bands the
         receiving screen uses. import_errors is deliberately absent: the layout
         already prints it for every screen, and repeating it here would show
         the same list twice.

         "Skipped" and "notes" had nowhere to appear on this screen before, so
         the importer's own account of what it did — including the Issue Setup
         entries a file created — was being thrown away. --}}
    @foreach ([
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

    {{-- The rows that did not go in, as a file to fix and upload again.

         Sits under the error and skipped lists because it is what the user does
         about them: a 754-row upload with 39 unusable rows is not something
         anybody should have to find by reading the list above against the
         original spreadsheet.

         The count is of ROWS, which is larger than the number of complaints
         above — a requisition is imported whole or not at all, so its clean
         lines are in the file too. Saying so here stops the difference reading
         as a bug. --}}
    @if(session('import_skipped_rows'))
        @php($skippedRowCount = session('import_skipped_row_count', count(session('import_skipped_rows'))))
        @php($skippedRowsInFile = count(session('import_skipped_rows')))
        <div class="alert alert-secondary d-flex flex-wrap align-items-center gap-3" role="alert">
            <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
            <div class="flex-grow-1 small">
                <div class="fw-semibold">{{ $skippedRowCount }} {{ $skippedRowCount === 1 ? 'row was' : 'rows were' }} not imported.</div>
                Download them as a spreadsheet in the upload format, correct them, and upload that
                file again. Every row of an affected requisition is included, with the reason in the
                last column.
                @if($skippedRowsInFile < $skippedRowCount)
                    <span class="text-danger d-block mt-1">
                        Only the first {{ $skippedRowsInFile }} are in the file. Correct these and upload again to see the rest.
                    </span>
                @endif
            </div>
            <a href="{{ route('store.stock.issues.skipped-rows') }}" class="btn btn-sm btn-outline-dark">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Download Skipped Rows
            </a>
        </div>
    @endif

    {{-- Per-item stock warnings raised by the submission just saved, so a
         multi-line requisition names exactly which items need reordering. --}}
    @if(session('issue_stock_warnings'))
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold mb-1">These issued items need a purchase requisition:</div>
                <ul class="mb-0 ps-3 small">
                    @foreach(session('issue_stock_warnings') as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-12">
            <div class="card gx-stock-card">
                <div class="gx-stock-card-body">
                    <h5 class="mb-3">Record Issue</h5>

                    @if($items->isEmpty())
                        <div class="alert alert-info small py-2 mb-0">
                            Add a stock item first — an issue must be recorded against an item in the
                            <a href="{{ route('store.stock.items.index') }}" class="alert-link">item master</a>.
                        </div>
                    @else
                        @if($sections->isEmpty() && $persons->isEmpty() && $approvers->isEmpty())
                            <div class="alert alert-info small py-2">
                                Set up the Indent Section, Indent Person and Approved By lists first —
                                <a href="{{ route('store.stock.issue-setup.index') }}" class="alert-link">Issue Setup</a>.
                            </div>
                        @endif

                        {{-- Header entered once, then one or more item lines. Each
                             line is still saved as its own issue record carrying a
                             copy of this header, so the history table and the
                             consumption report are unchanged. --}}
                        <form method="POST" action="{{ route('store.stock.issues.store') }}" id="issueForm">
                            @csrf

                            <div class="row g-3 mb-4">
                                <div class="col-6 col-md-3 col-xl-2">
                                    <label class="form-label">Issue Date <span class="text-danger">*</span></label>
                                    <input type="date" name="issue_date" id="issueDate" value="{{ old('issue_date', now()->toDateString()) }}" class="form-control" required>
                                </div>
                                <div class="col-6 col-md-3 col-xl-1">
                                    <label class="form-label">Month</label>
                                    <input type="text" id="monthLabel" class="form-control gx-stock-readonly" readonly tabindex="-1">
                                </div>
                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label">Indent Section</label>
                                    <select name="indent_section_id" class="form-select js-creatable">
                                        <option value="">Select or type…</option>
                                        @if($typed = $typedOption(old('indent_section_id')))
                                            <option value="{{ $typed['value'] }}" selected>{{ $typed['label'] }}</option>
                                        @endif
                                        @foreach($sections as $s)
                                            <option value="{{ $s->id }}" @selected(old('indent_section_id') == $s->id)>{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label">Indent Person</label>
                                    <select name="indent_person_id" class="form-select js-creatable">
                                        <option value="">Select or type…</option>
                                        @if($typed = $typedOption(old('indent_person_id')))
                                            <option value="{{ $typed['value'] }}" selected>{{ $typed['label'] }}</option>
                                        @endif
                                        @foreach($persons as $p)
                                            <option value="{{ $p->id }}" @selected(old('indent_person_id') == $p->id)>{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label">Approved By</label>
                                    <select name="issue_approver_id" class="form-select js-creatable">
                                        <option value="">Select or type…</option>
                                        @if($typed = $typedOption(old('issue_approver_id')))
                                            <option value="{{ $typed['value'] }}" selected>{{ $typed['label'] }}</option>
                                        @endif
                                        @foreach($approvers as $a)
                                            <option value="{{ $a->id }}" @selected(old('issue_approver_id') == $a->id)>{{ $a->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                {{-- Type is NOT here. New / Replace is decided per
                                     item — one requisition can replace a worn part
                                     and issue a new one on the next line — so it
                                     lives in the Items table below. --}}
                                <div class="col-6 col-md-3 col-xl-3">
                                    <label class="form-label">Requisition Number</label>
                                    <input name="requisition_no" value="{{ old('requisition_no') }}" class="form-control" maxlength="100">
                                </div>
                            </div>

                            {{-- One "Add Another Item", under the last line —
                                 the same as Record Receiving. It used to appear
                                 here as well, which meant two buttons doing the
                                 identical thing on one form. --}}
                            <div class="mb-2">
                                <h6 class="gx-stock-subhead mb-0">Items</h6>
                                <span class="gx-stock-help">Pick a category to narrow the item list, then choose the item.</span>
                            </div>

                            {{-- Shortfall summary. Filled by the server when it
                                 refuses a submission, and by the script before
                                 one is even sent. --}}
                            @php($stockErrors = session('issue_stock_errors', []))
                            <div id="issueStockBlock"
                                 class="alert alert-danger py-2 small {{ $stockErrors ? '' : 'd-none' }}"
                                 role="alert">
                                @if($stockErrors)
                                    <strong class="d-block mb-1">Not enough stock to issue.</strong>
                                    @foreach($stockErrors as $line)
                                        <span class="d-block">{{ $line }}</span>
                                    @endforeach
                                @endif
                            </div>

                            {{-- This container must scroll sideways on a narrow
                                 screen, and any element that does will also clip
                                 vertically: CSS computes overflow-y to auto as
                                 soon as overflow-x is not visible, so declaring
                                 overflow-y:visible here has no effect. That is
                                 exactly why the row dropdowns are rendered on
                                 <body> instead (see _searchable) — there is no
                                 way to keep them inside a scrolling table and
                                 unclipped at the same time. --}}
                            <div class="gx-line-scroll">
                                <table class="table align-middle mb-0 gx-line-table">
                                    {{-- Widths are load-bearing here: the table is
                                         table-layout:fixed and its headers are
                                         white-space:nowrap, so a column narrower than
                                         its own heading does not clip or wrap — the
                                         text spills into the next cell and the two
                                         headings read as one word.

                                         Measured on the rendered page, the headings
                                         need: Brand/Specification + its auto badge
                                         202px, Remarks 72px. Brand is set to 210 and
                                         the two percentage columns give up four points
                                         each to pay for it, which also lifts Remarks
                                         (the leftover column) from 51px to 100px. The
                                         table's total is unchanged, so this costs no
                                         extra sideways scrolling. --}}
                                    <colgroup>
                                        <col style="width:44px;">   {{-- # --}}
                                        <col style="width:26%;">    {{-- Item Name --}}
                                        <col style="width:88px;">   {{-- Uom --}}
                                        <col style="width:16%;">    {{-- Category --}}
                                        <col style="width:210px;">  {{-- Brand/Specification --}}
                                        <col style="width:130px;">  {{-- Issued Qty --}}
                                        <col style="width:116px;">  {{-- Type --}}
                                        <col>                       {{-- Remarks, takes the remainder --}}
                                        <col style="width:104px;">  {{-- Action --}}
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th class="text-center">#</th>
                                            <th>Item Name <span class="text-danger">*</span></th>
                                            <th>Uom</th>
                                            <th>Category</th>
                                            {{-- Two brands can share an item name. The
                                                 dropdown label carries the brand in
                                                 brackets, but only while the list is
                                                 open — this keeps it on the row. --}}
                                            <th style="min-width:140px;">Brand/Specification <span class="gx-stock-auto">auto</span></th>
                                            <th>Issued Qty <span class="text-danger">*</span></th>
                                            <th>Type</th>
                                            <th>Remarks</th>
                                            <th class="text-end gx-stock-actions">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="issueLines"></tbody>
                                </table>
                            </div>

                            {{-- Directly under the last line, which is where the
                                 operator is looking once they have filled one in.
                                 Bound by class — see the .js-add-line binding
                                 below — so this works whether there is one of
                                 them or several. --}}
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-primary js-add-line" id="addIssueLine">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Another Item
                                </button>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-3 mt-4 pt-3 border-top">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Record Issue
                                </button>
                                <p class="gx-stock-help" style="max-width:640px;">
                                    Section, Person and Approved By accept a new value — type it in and it is saved to the list
                                    for next time. Each item line is recorded as its own issue under this requisition.
                                </p>
                            </div>
                        </form>

                        {{-- Row template. Cloned by the script below, with
                             __INDEX__ replaced by the line's array index. --}}
                        <template id="issueLineTemplate">
                            <tr class="issue-line">
                                <td class="text-center js-line-number gx-line-no"></td>
                                <td>
                                    <select class="form-select form-select-sm js-line-item" name="items[__INDEX__][stock_item_id]" required
                                            data-status-url="{{ route('store.stock.issues.item-status', ['stockItem' => '__ID__']) }}">
                                        <option value="">Select item…</option>
                                        @foreach($items as $it)
                                            <option value="{{ $it->id }}"
                                                    data-uom="{{ $it->uom }}"
                                                    data-category-id="{{ $it->item_category_id }}">{{ $it->name }}@if($it->brand) ({{ $it->brand }})@endif</option>
                                        @endforeach
                                    </select>
                                    <div class="js-line-alert mt-1"></div>
                                </td>
                                <td><input type="text" class="form-control form-control-sm js-line-uom gx-stock-readonly text-center" readonly tabindex="-1" placeholder="—"></td>
                                <td>
                                    {{-- Double duty: narrows the item list on this
                                         line, and is the category saved for it. --}}
                                    <select class="form-select form-select-sm js-line-category" name="items[__INDEX__][item_category_id]">
                                        <option value="">All categories</option>
                                        @foreach($categories as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                {{-- Derived from the item, never posted — the same
                                     read-only treatment as Uom. Category above is a
                                     real input here, so Uom is the pattern to copy. --}}
                                <td><input type="text" class="form-control form-control-sm js-line-brand gx-stock-readonly" readonly tabindex="-1" placeholder="—"></td>
                                <td><input type="number" step="0.0001" min="0" class="form-control form-control-sm text-end js-line-qty" name="items[__INDEX__][qty]" required placeholder="0"></td>
                                {{-- New / Replace, per line. Left as a plain select
                                     (no TomSelect): two fixed options need no search
                                     box, and it keeps the column narrow. --}}
                                <td>
                                    <select class="form-select form-select-sm js-line-type" name="items[__INDEX__][requisition_type]">
                                        <option value="">—</option>
                                        @foreach($requisitionTypes as $type)
                                            <option value="{{ $type }}">{{ $type }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" class="form-control form-control-sm" name="items[__INDEX__][remarks]" maxlength="1000" placeholder="Optional"></td>
                                {{-- Padded away from the Remarks field so a fast
                                     click on the input cannot land on Remove. --}}
                                <td class="text-end gx-line-action">
                                    <button type="button" class="btn btn-sm btn-outline-danger js-line-remove">
                                        <i class="bi bi-trash me-1" aria-hidden="true"></i>Remove
                                    </button>
                                </td>
                            </tr>
                        </template>

                        <style>
                            {{-- The item-lines table's own look — header, row
                                 tint, hover, line number, action padding — was
                                 declared here AND on the Receiving screen. It
                                 now lives once in _stock-ui. What is left below
                                 is only what this screen genuinely needs on top
                                 of it. --}}

                            /* The <colgroup> above declares the column widths,
                               but under the default `table-layout: auto` they
                               are only hints: the browser widens a column to
                               fit its widest content. Category and Item Name
                               hold TomSelect controls whose intrinsic width
                               comes from the longest option text, so with real
                               category and item names those two columns grow
                               past their declared 20% / 30% and push the rest
                               of the row out of step with the header layout.
                               Fixed layout makes the <colgroup> authoritative,
                               so every column sits under its own header no
                               matter how many lines are added or how long the
                               names are. min-width keeps the columns usable on
                               a small screen — .gx-line-scroll scrolls instead
                               of crushing them. */
                            .gx-line-table {
                                table-layout: fixed;
                                /* 1216 + the 150 the Brand/Specification column
                                   adds. Without the increase the fixed layout
                                   squeezes the same total into the old width and
                                   the columns run into each other. */
                                min-width: 1366px !important;
                            }

                            {{-- The row's auto-filled Uom uses the shared
                                 .gx-stock-readonly (plus .text-center) instead of
                                 a local copy that set the same five properties. --}}

                            {{-- The opt-out that used to live here, rescuing only
                                 this form's submit button from the icon-only
                                 collapse in components.css, now covers every
                                 General Stock button from _stock-ui. Removed
                                 rather than left to duplicate it. --}}
                        </style>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card gx-stock-card">
                <div class="gx-stock-card-body">
                    <div class="gx-stock-card-head">
                        <h5>Issue History <span class="badge bg-primary-subtle text-primary ms-1">{{ $issues->total() }}</span></h5>
                    </div>

                    {{-- Same labelled filter row as the Item Master and the
                         Consumable Stock Report: full-size controls, a visible
                         label over each, and the shared .gx-stock-filter rules
                         doing the alignment. --}}
                    <form method="GET" class="row g-3 gx-stock-filter mb-4">
                        <div class="col-6 col-md-3">
                            <label class="form-label" for="issueFilterMonth">Month</label>
                            <input type="month" id="issueFilterMonth" name="month" value="{{ $filters['month'] ?? '' }}" class="form-control">
                        </div>
                        {{-- Search takes the room the row has left rather than
                             stopping at a third of it and stranding the rest. --}}
                        <div class="col-6 col-md-7">
                            <label class="form-label" for="issueFilterSearch">Search</label>
                            <input id="issueFilterSearch" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control"
                                   placeholder="Item or requisition no">
                        </div>
                        <div class="col-12 col-md-2 gx-stock-filter-actions">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                            @if($hasFilters)
                                <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                            @endif
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table align-middle gx-stock-table">
                            <thead>
                                <tr>
                                    <th>Date</th><th>Section</th><th>Person</th><th>Approved By</th>
                                    <th>Req No</th><th>Type</th>
                                    <th style="min-width:160px;">Item</th>
                                    <th>Category</th>
                                    <th class="text-end">Issued Qty</th>
                                    <th class="text-end gx-stock-actions">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($issues as $i)
                                    <tr>
                                        <td class="small">{{ optional($i->issue_date)->format('d-M-Y') ?? '—' }}</td>
                                        {{-- Fall back to the older free-text columns so
                                             records entered before the masters existed
                                             still show who indented them. --}}
                                        <td class="small">{{ optional($i->indentSection)->name ?: ($i->department ?: '—') }}</td>
                                        <td class="small">{{ optional($i->indentPerson)->name ?: ($i->issued_to ?: '—') }}</td>
                                        <td class="small text-muted">{{ optional($i->approver)->name ?: '—' }}</td>
                                        <td class="small">{{ $i->requisition_no ?: '—' }}</td>
                                        <td class="small">
                                            @if($i->requisition_type)
                                                <span class="badge bg-secondary-subtle text-secondary">{{ $i->requisition_type }}</span>
                                            @else — @endif
                                        </td>
                                        <td><div class="fw-semibold">{{ optional($i->stockItem)->name ?? '—' }}</div></td>
                                        <td class="small text-muted">{{ optional($i->itemCategory)->name ?: (optional($i->stockItem)->category ?: '—') }}</td>
                                        <td class="text-end fw-bold">{{ $qty($i->qty) }}</td>
                                        {{-- Edit and Delete are Admin / Management
                                             rights (store.issues.edit / .delete, or
                                             the flat store.edit / store.delete); the
                                             controller enforces the same checks
                                             server-side. --}}
                                        <td class="text-end gx-stock-actions">
                                            @if($canEdit)
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editIssue{{ $i->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                            @endif
                                            @if($canDelete)
                                                <form method="POST" action="{{ route('store.stock.issues.destroy', $i) }}" onsubmit="return confirm('Remove this issue?');">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                                </form>
                                            @endif
                                            @if(! $canEdit && ! $canDelete)
                                                <span class="text-muted small">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    {{-- Says which of the two empties this is, the
                                         same as every other General Stock list:
                                         "nothing matched" and "nothing yet" call
                                         for different next steps. --}}
                                    <tr><td colspan="10" class="gx-stock-empty">
                                            <span class="gx-stock-empty-icon"><i class="bi bi-{{ $hasFilters ? 'search' : 'box-arrow-up' }}" aria-hidden="true"></i></span>
                                            <div class="gx-stock-empty-title">{{ $hasFilters ? 'No issues match this filter' : 'No issues recorded yet' }}</div>
                                            <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or search.' : 'Record an issue using the form above.' }}</div>
                                        </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $issues->links() }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Correct one recorded issue. Keyed by issue id and rendered only for a
         role that can submit it, the same arrangement the Item Master edit
         modals use — markup that carries no action a non-admin could replay.

         ONE ROW, not the requisition. A requisition is several rows sharing a
         header and the table lists it a row at a time, so this edits the row the
         user clicked and leaves its siblings alone.

         The Item is shown but not editable: moving an issue to another item is
         one balance undone and another created, which is a delete and a
         re-record, not a correction. The masters are plain dropdowns rather than
         the create form's type-to-add ones — a correction is not the place to
         coin a new Section, and Issue Setup is one click away. --}}
    @if($canEdit)
        @foreach($issues as $i)
            {{-- old() is global, so without this marker a rejected edit would
                 repopulate EVERY modal on the page with the failed row's values.
                 Same idiom the Item Master modals use.

                 Written in the INLINE php form on purpose, never the block
                 form. Blade pairs raw PHP blocks by matching an opening php
                 directive to the next closing one, and this file already uses
                 the inline form further up — so one closing directive added
                 below it pairs with THAT opener and swallows the whole page
                 in between. The same reason this note spells the directives
                 out rather than writing them. --}}
            @php($formKey = 'edit:'.$i->id)
            @php($wasSubmitted = old('form') === $formKey)
            @php($value = fn ($field, $stored = null) => $wasSubmitted ? old($field, $stored) : $stored)
            @php($qtyValue = rtrim(rtrim(number_format((float) $i->qty, 4, '.', ''), '0'), '.'))
            <div class="modal fade" id="editIssue{{ $i->id }}" tabindex="-1" aria-labelledby="editIssueLabel{{ $i->id }}" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content gx-stock-card">
                        <form method="POST" action="{{ route('store.stock.issues.update', $i) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="form" value="{{ $formKey }}">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editIssueLabel{{ $i->id }}">Edit Issue</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                {{-- The refusal, where the user is looking. The page
                                     banner behind this dialog says the same thing,
                                     but it is out of sight once the modal reopens. --}}
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
                                            <span class="gx-edit-locked-value">{{ optional($i->stockItem)->name ?? '—' }}</span>
                                        </div>
                                        <div class="gx-edit-hint">
                                            Moving this issue to another item changes two stock balances, so it is a
                                            delete and a fresh entry rather than an edit.
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editIssueDate{{ $i->id }}">Issue Date <span class="text-danger">*</span></label>
                                        <input type="date" id="editIssueDate{{ $i->id }}" name="issue_date" class="form-control @if($wasSubmitted && $errors->has('issue_date')) is-invalid @endif" required
                                               value="{{ $value('issue_date', optional($i->issue_date)->toDateString()) }}">
                                    </div>
                                    <div class="col-6 col-md-4">
                                        <label class="form-label" for="editIssueQty{{ $i->id }}">Issued Qty <span class="text-danger">*</span></label>
                                        <input type="number" step="0.0001" min="0.0001" id="editIssueQty{{ $i->id }}" name="qty" class="form-control @if($wasSubmitted && $errors->has('qty')) is-invalid @endif" required
                                               value="{{ $value('qty', $qtyValue) }}">
                                        <div class="gx-edit-hint">
                                            {{ optional($i->stockItem)->uom ? 'In '.$i->stockItem->uom.'. ' : '' }}Cannot take this item below zero.
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueReqNo{{ $i->id }}">Requisition Number</label>
                                        <input type="text" id="editIssueReqNo{{ $i->id }}" name="requisition_no" class="form-control" maxlength="100"
                                               value="{{ $value('requisition_no', $i->requisition_no) }}">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueSection{{ $i->id }}">Indent Section</label>
                                        <select id="editIssueSection{{ $i->id }}" name="indent_section_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($sections as $s)
                                                <option value="{{ $s->id }}" @selected($value('indent_section_id', $i->indent_section_id) == $s->id)>{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssuePerson{{ $i->id }}">Indent Person</label>
                                        <select id="editIssuePerson{{ $i->id }}" name="indent_person_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($persons as $p)
                                                <option value="{{ $p->id }}" @selected($value('indent_person_id', $i->indent_person_id) == $p->id)>{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueApprover{{ $i->id }}">Approved By</label>
                                        <select id="editIssueApprover{{ $i->id }}" name="issue_approver_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($approvers as $a)
                                                <option value="{{ $a->id }}" @selected($value('issue_approver_id', $i->issue_approver_id) == $a->id)>{{ $a->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueCategory{{ $i->id }}">Category</label>
                                        <select id="editIssueCategory{{ $i->id }}" name="item_category_id" class="form-select">
                                            <option value="">—</option>
                                            @foreach($categories as $c)
                                                <option value="{{ $c->id }}" @selected($value('item_category_id', $i->item_category_id) == $c->id)>{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label" for="editIssueType{{ $i->id }}">Type</label>
                                        <select id="editIssueType{{ $i->id }}" name="requisition_type" class="form-select">
                                            <option value="">—</option>
                                            @foreach($requisitionTypes as $type)
                                                <option value="{{ $type }}" @selected($value('requisition_type', $i->requisition_type) === $type)>{{ $type }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="editIssueRemarks{{ $i->id }}">Remarks</label>
                                        <textarea id="editIssueRemarks{{ $i->id }}" name="remarks" class="form-control" rows="2" maxlength="1000">{{ $value('remarks', $i->remarks) }}</textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Update Issue</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    {{-- Bulk consumption upload. Same shape as the receiving import modal, so
         the two screens are learnt once. --}}
    @can('store.issues.create')
        <div class="modal fade" id="importIssuesModal" tabindex="-1" aria-labelledby="importIssuesLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="importIssuesLabel">Import Issues</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="gx-stock-help mb-3">
                            Upload many requisitions at once. Rows sharing the same Issue Date, Requisition
                            Number, Indent Section, Indent Person and Approved By are recorded together as one
                            requisition.
                        </p>

                        <a href="{{ route('store.stock.issues.template') }}" class="btn btn-outline-secondary w-100 mb-3">
                            <i class="bi bi-download me-1" aria-hidden="true"></i>Download Sample Template
                        </a>

                        <form method="POST" action="{{ route('store.stock.issues.import') }}" enctype="multipart/form-data" id="importIssuesForm">
                            @csrf
                            <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required
                                   aria-label="CSV or Excel file of issues to import">
                            <p class="gx-stock-help mb-0">
                                Issue Date, Item Name and Issued Qty are required on every row. Item names, and any
                                Indent Section, Indent Person, Approved By or Category named, must already exist —
                                add them under Items and Issue Setup first. Month and Uom are read for checking
                                only. A requisition is refused if the file would issue more of an item than is in
                                stock, counting every row in the file together.
                            </p>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" form="importIssuesForm" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import Issues</button>
                    </div>
                </div>
            </div>
        </div>
    @endcan

    @include('store.stock._searchable')

    <script>
        (function () {
            var form = document.getElementById('issueForm');
            var template = document.getElementById('issueLineTemplate');
            if (!form || !template) { return; }

            var body = document.getElementById('issueLines');
            var blocker = document.getElementById('issueStockBlock');
            var date = document.getElementById('issueDate');
            var month = document.getElementById('monthLabel');
            var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            // Lines from a submission the server rejected, with the message for
            // whichever field on each line failed.
            var oldLines = @json($oldLines);

            // The item list as data. TomSelect keeps its own option store, so
            // the row's Uom and category are looked up here rather than off a
            // DOM <option> that TomSelect may have rebuilt.
            var allItems = @json($itemOptions);

            function itemInfo(id) {
                for (var i = 0; i < allItems.length; i++) {
                    if (String(allItems[i].value) === String(id)) { return allItems[i]; }
                }
                return null;
            }

            // Only ever increases, so removing line 2 cannot make a new line
            // reuse its index and collide in the posted items[] array.
            var nextIndex = 0;

            function syncMonth() {
                var parts = (date.value || '').split('-');
                month.value = parts.length === 3
                    ? MONTHS[parseInt(parts[1], 10) - 1] + '-' + parts[0].slice(2)
                    : '';
            }

            /** Every item line currently on the form. */
            function rows() {
                return Array.prototype.slice.call(body.querySelectorAll('tr.issue-line'));
            }

            function renumber() {
                var rows = body.querySelectorAll('tr.issue-line');
                rows.forEach(function (row, i) {
                    row.querySelector('.js-line-number').textContent = i + 1;
                    // The last line cannot be removed — a requisition with no
                    // items is not a thing worth submitting.
                    row.querySelector('.js-line-remove').disabled = rows.length === 1;
                });
            }

            /**
             * Show only the items belonging to the category picked on this row.
             *
             * Setting option.hidden/disabled and calling sync() does not work:
             * TomSelect keeps its own option store and sync() re-reads every
             * <option> regardless of those attributes, so the hidden ones came
             * straight back. The list has to be rebuilt in TomSelect itself.
             */
            function applyCategoryFilter(row) {
                var category = row.querySelector('.js-line-category');
                var item = row.querySelector('.js-line-item');
                var wanted = category.value;

                // A category the user just typed has no items against it yet,
                // so filtering by it would empty the list entirely.
                if (String(wanted).indexOf('new:') === 0) { wanted = ''; }

                var filtered = allItems.filter(function (entry) {
                    return !wanted || String(entry.category_id) === String(wanted);
                });

                var ts = item.tomselect;
                var current = ts ? ts.getValue() : item.value;
                var keeps = current && filtered.some(function (entry) {
                    return String(entry.value) === String(current);
                });

                if (ts) {
                    ts.clearOptions();
                    ts.addOptions(filtered);
                    ts.refreshOptions(false);
                    ts.setValue(keeps ? current : '', true);
                } else {
                    Array.prototype.forEach.call(item.options, function (option) {
                        if (!option.value) { return; }
                        var match = !wanted || option.dataset.categoryId === String(wanted);
                        option.hidden = !match;
                        option.disabled = !match;
                    });
                    if (!keeps) { item.value = ''; }
                }

                // A selection the new filter drops must not stay submitted while
                // invisible, and its Uom / stock badge has to go with it.
                if (current && !keeps) { loadStatus(row); }
            }

            function setSelectValue(select, value) {
                // A value the user typed rather than picked ("new:Cutting") has
                // no option behind it, so one is added before selecting it.
                if (value && String(value).indexOf('new:') === 0) {
                    var label = String(value).slice(4);

                    if (select.tomselect) {
                        select.tomselect.addOption({ value: value, text: label });
                    } else {
                        var option = document.createElement('option');
                        option.value = value;
                        option.textContent = label;
                        select.appendChild(option);
                    }
                }

                if (select.tomselect) { select.tomselect.setValue(value, true); }
                else { select.value = value; }
            }

            function showAlert(row, tone, html) {
                row.querySelector('.js-line-alert').innerHTML =
                    '<span class="badge bg-' + tone + '-subtle text-' + tone + '-emphasis">' + html + '</span>';
            }

            function clearAlert(row) {
                row.querySelector('.js-line-alert').innerHTML = '';
            }

            /**
             * Warn on this line only — the user needs to see which of five items
             * is short, not a single message for the whole form.
             *
             * A line asking for more than is on hand is now an ERROR, not a
             * warning: the server refuses the submission, so saying anything
             * softer here would just promise something that cannot happen. The
             * qty asked is summed across every line naming the same item, the
             * same way the server sums it.
             */
            function renderAlert(row) {
                var status = row._stock;
                if (!status) { clearAlert(row); return; }

                var wanted = wantedFor(row);
                var short = status.stock !== null && wanted > 0 && wanted > status.stock;

                if (status.status === 'out') {
                    showAlert(row, 'danger', 'Out of Stock');
                } else if (status.status === 'place_order') {
                    showAlert(row, 'danger', 'Low Stock · ' + status.stock + ' left');
                } else if (status.status === 'low') {
                    showAlert(row, 'warning', 'Below re-order level · ' + status.stock + ' left');
                } else if (!short) {
                    clearAlert(row);
                    return;
                } else {
                    clearAlert(row);
                }

                if (short) {
                    row.querySelector('.js-line-alert').innerHTML +=
                        '<span class="badge bg-danger-subtle text-danger-emphasis ms-1">Only '
                        + status.stock + ' in stock — cannot issue ' + wanted + '</span>';
                }

                row.querySelector('.js-line-qty').classList.toggle('is-invalid', short);
            }

            /**
             * How much this submission asks for of THIS row's item — summed
             * across every row naming it. Two lines of 30 against a stock of 50
             * are individually fine and together are not, and it is the total
             * that leaves the shelf.
             */
            function wantedFor(row) {
                var id = itemValue(row);
                if (!id) { return parseFloat(row.querySelector('.js-line-qty').value) || 0; }

                return rows().reduce(function (sum, other) {
                    return itemValue(other) === id
                        ? sum + (parseFloat(other.querySelector('.js-line-qty').value) || 0)
                        : sum;
                }, 0);
            }

            /** The chosen item id on a row, through TomSelect when it is there. */
            function itemValue(row) {
                var item = row.querySelector('.js-line-item');
                var value = item.tomselect ? item.tomselect.getValue() : item.value;

                return value ? String(value) : '';
            }

            /**
             * Every line that asks for more than is on hand, as ready-to-show
             * messages. Empty when nothing is short — or when a status lookup
             * failed, in which case the server still refuses the submission.
             */
            function shortLines() {
                var seen = {};
                var messages = [];

                rows().forEach(function (row) {
                    var status = row._stock;
                    var id = itemValue(row);

                    if (!status || !id || status.stock === null || seen[id]) { return; }

                    var wanted = wantedFor(row);
                    if (wanted <= status.stock) { return; }

                    seen[id] = true;

                    var name = (itemInfo(id) || {}).text || 'This item';
                    messages.push(name.replace(/\s*\(.*\)$/, '') + ' — only ' + status.stock
                        + ' in stock, cannot issue ' + wanted + '.');
                });

                return messages;
            }

            function renderAllAlerts() {
                rows().forEach(renderAlert);
            }

            function loadStatus(row) {
                var item = row.querySelector('.js-line-item');
                var value = item.tomselect ? item.tomselect.getValue() : item.value;
                var info = value ? itemInfo(value) : null;

                row.querySelector('.js-line-uom').value = info ? (info.uom || '') : '';
                // Blank brand leaves the box empty, so its placeholder shows the
                // em dash — the same as Uom.
                row.querySelector('.js-line-brand').value = info ? (info.brand || '') : '';

                // Back-fill the category from the chosen item when the row's
                // filter was left on "All categories".
                var category = row.querySelector('.js-line-category');
                if (info && info.category_id && !category.value) {
                    setSelectValue(category, info.category_id);
                }

                if (!value) { row._stock = null; clearAlert(row); return; }

                fetch(item.dataset.statusUrl.replace('__ID__', encodeURIComponent(value)), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) { row._stock = data; renderAllAlerts(); })
                    // A failed lookup must never block recording an issue.
                    .catch(function () { row._stock = null; clearAlert(row); });
            }

            /** Has anything been entered on this line worth warning about? */
            function rowHasData(row) {
                var item = row.querySelector('.js-line-item');
                var value = item.tomselect ? item.tomselect.getValue() : item.value;

                return !!value
                    || !!row.querySelector('.js-line-qty').value
                    || !!row.querySelector('input[name$="[remarks]"]').value;
            }

            /** Flag a field the server rejected, with its message underneath. */
            function markInvalid(field, message) {
                if (!field || !message) { return; }

                field.classList.add('is-invalid');

                var note = document.createElement('div');
                note.className = 'invalid-feedback d-block';
                note.style.fontSize = '.72rem';
                note.textContent = message;

                // TomSelect replaces the select visually, so the note goes after
                // the wrapper it built rather than the hidden original.
                (field.tomselect ? field.tomselect.wrapper : field).insertAdjacentElement('afterend', note);
            }

            /**
             * @param values optional {stock_item_id, item_category_id, qty,
             *        remarks, errors} to prefill, used when restoring a
             *        rejected submission.
             */
            function addLine(values) {
                var html = template.innerHTML.split('__INDEX__').join(nextIndex);
                nextIndex++;

                var holder = document.createElement('tbody');
                holder.innerHTML = html.trim();
                var row = holder.querySelector('tr');
                body.appendChild(row);

                row.querySelector('.js-line-category').addEventListener('change', function () { applyCategoryFilter(row); });
                row.querySelector('.js-line-item').addEventListener('change', function () { loadStatus(row); });
                // Every row, not just this one: the qty asked is summed per
                // item, so typing on one line can put another line over.
                row.querySelector('.js-line-qty').addEventListener('input', renderAllAlerts);
                row.querySelector('.js-line-remove').addEventListener('click', function () {
                    // Only ask when there is something to lose — confirming an
                    // empty row every time trains people to click through it.
                    if (rowHasData(row) && ! window.confirm('Remove this item line? What you entered on it will be lost.')) {
                        return;
                    }

                    row.remove();
                    renumber();
                });

                // Rows created after page load need their own TomSelect.
                if (window.gxInitSearchable) {
                    row.querySelectorAll('.js-line-item, .js-line-category').forEach(function (el) {
                        el.classList.add('js-searchable');
                    });
                    window.gxInitSearchable(row);
                }

                // Restore a rejected line. Category goes first so the item list
                // is filtered before the item is put back into it, otherwise the
                // filter would find the selection hidden and clear it again.
                if (values) {
                    var category = row.querySelector('.js-line-category');
                    var item = row.querySelector('.js-line-item');

                    if (values.item_category_id) { setSelectValue(category, values.item_category_id); }
                    applyCategoryFilter(row);
                    if (values.stock_item_id) { setSelectValue(item, values.stock_item_id); }

                    row.querySelector('.js-line-qty').value = values.qty || '';
                    row.querySelector('.js-line-type').value = values.requisition_type || '';
                    row.querySelector('input[name$="[remarks]"]').value = values.remarks || '';

                    var errors = values.errors || {};
                    markInvalid(item, errors.item);
                    markInvalid(row.querySelector('.js-line-qty'), errors.qty);
                    markInvalid(category, errors.category);
                    markInvalid(row.querySelector('.js-line-type'), errors.type);

                    loadStatus(row);
                } else {
                    applyCategoryFilter(row);
                }

                renumber();
                return row;
            }

            // Both triggers — above the table and under the last line. Selected
            // by class so the two can never drift apart.
            document.querySelectorAll('.js-add-line').forEach(function (btn) {
                btn.addEventListener('click', function () { addLine(); });
            });

            /**
             * Stop a submission that would take an item negative, before it
             * costs a round trip.
             *
             * This is a courtesy, not the safeguard: it can only judge lines
             * whose stock lookup succeeded, and it is client-side either way.
             * StockIssueController::assertWithinStock is what actually refuses
             * the write, and it re-reads the position at the moment of saving.
             */
            form.addEventListener('submit', function (event) {
                var short = shortLines();

                // Cleared unconditionally first, so a summary left from an
                // earlier attempt cannot survive on top of a corrected form.
                blocker.classList.add('d-none');

                if (! short.length) { return; }

                event.preventDefault();
                renderAllAlerts();

                blocker.innerHTML = '<strong class="d-block mb-1">Not enough stock to issue.</strong>'
                    + short.map(function (m) {
                        return '<span class="d-block">' + m.replace(/[<>&]/g, '') + '</span>';
                    }).join('');
                blocker.classList.remove('d-none');

                blocker.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });

            // Cleared as soon as the user starts fixing the lines, so a stale
            // list of shortfalls is never left sitting above a corrected form.
            body.addEventListener('input', function () { blocker.classList.add('d-none'); });
            body.addEventListener('change', function () { blocker.classList.add('d-none'); });
            date.addEventListener('change', syncMonth);

            syncMonth();

            // Come back with the lines the user typed, not an empty grid.
            if (oldLines.length) {
                oldLines.forEach(function (line) { addLine(line); });
            } else {
                addLine();
            }
        })();
    </script>

    {{-- A refused correction is redirected back, which closes the dialog the
         user was typing in. Reopen it, still carrying what they entered and
         showing why it was refused. Same arrangement as the Item Master modals;
         the hidden `form` field says which one was submitted. --}}
    @php($reopenEdit = str_starts_with((string) old('form'), 'edit:') ? 'editIssue'.substr((string) old('form'), 5) : null)

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
