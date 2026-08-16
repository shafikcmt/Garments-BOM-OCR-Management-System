@extends('layouts.app')

@section('title', 'General Stock — Items')

@php
    use App\Services\GeneralStockReportService as StockStatus;

    // Presentation for the four statuses. The statuses themselves, and the
    // thresholds behind them, come from GeneralStockReportService — this array
    // only decides how each one is drawn. Labels match the Stock Report word
    // for word so the two screens never call the same state two things.
    // `tone` names the health bar's colour class in _stock-ui rather than
    // repeating the hex inline on every row — the bar and the badge beside it
    // describe the same state, so they read the state from one place.
    $statusMeta = [
        StockStatus::STATUS_OUT => ['label' => 'Out of Stock', 'badge' => 'bg-danger text-white', 'tone' => 'danger'],
        StockStatus::STATUS_PLACE_ORDER => ['label' => 'Place Order', 'badge' => 'bg-danger-subtle text-danger', 'tone' => 'danger'],
        StockStatus::STATUS_LOW => ['label' => 'Low Stock', 'badge' => 'bg-warning-subtle text-warning-emphasis', 'tone' => 'warning'],
        StockStatus::STATUS_OK => ['label' => 'Ok', 'badge' => 'bg-success-subtle text-success', 'tone' => 'success'],
    ];

    // Trailing zeros trimmed so a whole-number qty reads "15", not "15.0000".
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4), '0'), '.');

    // Blank inputs are dropped so a chip link reads ?status=out rather than
    // ?search=&category=&status=out.
    $activeFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->all();
    $hasFilters = $activeFilters !== [];
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Item Master'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="box-seam" eyebrow="General Stock" title="Item Master"
                   copy="Every consumable the store carries, with what is on the shelf against its re-order level.">
        <x-slot:actions>
            <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Stock Report</a>
            <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary"><i class="bi bi-truck me-1" aria-hidden="true"></i>Receiving</a>
            <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Issues</a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    {{-- Per-row outcome of a bulk import. Errors block the whole file, so they
         are listed in full and the user re-uploads once they are fixed. Skips
         are informational — those rows were already in the master. --}}
    @foreach ([
        ['key' => 'import_errors', 'tone' => 'danger', 'icon' => 'x-circle-fill', 'heading' => 'These rows need correcting before the file can be imported:'],
        ['key' => 'import_skipped', 'tone' => 'warning', 'icon' => 'exclamation-triangle-fill', 'heading' => 'These rows were skipped:'],
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

    {{-- One card for the list: toolbar, then the filters that narrow it, then
         the rows. The toolbar used to sit in a card of its own directly above
         this one, which drew a seam across the screen between a heading and the
         table it heads, and spent a second card's padding saying nothing. --}}
    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-toolbar mb-3">
                <h5>Item Master</h5>
                <span class="badge bg-primary-subtle text-primary"><span data-list-count>{{ $items->total() }}</span> items</span>
                {{-- Sits beside the count it belongs to, so the feedback is where
                     the user is already looking while typing. --}}
                <span class="spinner-border spinner-border-sm text-primary d-none" role="status" data-list-spinner>
                    <span class="visually-hidden">Updating the list…</span>
                </span>

                {{-- Counts across the whole master, not this page. Each one
                     re-runs the list filtered to itself. Always rendered, and
                     hidden while zero: a live filter can empty one and fill it
                     again, and rebuilding the chip from JS would mean keeping a
                     copy of its markup in two places. --}}
                @foreach ([StockStatus::STATUS_OUT, StockStatus::STATUS_PLACE_ORDER, StockStatus::STATUS_LOW] as $chip)
                    <a href="{{ route('store.stock.items.index', array_merge($activeFilters, ['status' => $chip])) }}"
                       class="badge gx-stock-chip {{ $statusMeta[$chip]['badge'] }} {{ ($statusCounts[$chip] ?? 0) > 0 ? '' : 'd-none' }}"
                       data-list-chip="{{ $chip }}"
                       @if(($filters['status'] ?? '') === $chip) aria-current="true" @endif>
                        <span data-list-chip-count>{{ $statusCounts[$chip] ?? 0 }}</span> {{ $statusMeta[$chip]['label'] }}
                    </a>
                @endforeach

                <span class="gx-stock-toolbar-gap"></span>

                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importItems">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Import Items
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItem">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Item
                </button>
            </div>

            {{-- Stays a plain GET form with a working Filter button. The live
                 search is an enhancement on top: with JS off, or if a fetch
                 fails, submitting still reloads the page with the same filters. --}}
            <form method="GET" class="row g-3 gx-stock-filter mb-4" data-list-filters
                  action="{{ route('store.stock.items.index') }}">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="itemFilterSearch">Search</label>
                    <input id="itemFilterSearch" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control"
                           placeholder="Item, brand/specification or category">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="itemFilterCategory">Category</label>
                    <select id="itemFilterCategory" name="category" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->name }}" @selected(($filters['category'] ?? '') === $c->name)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="itemFilterStatus">Stock Status</label>
                    <select id="itemFilterStatus" name="status" class="form-select">
                        <option value="">All</option>
                        <option value="attention" @selected(($filters['status'] ?? '') === 'attention')>Needs Attention</option>
                        @foreach($statusMeta as $key => $meta)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 gx-stock-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            {{-- Everything the filters change lives in this container: the JS
                 swaps it for the same Blade re-rendered server-side. The Edit
                 modals ride along inside it — see _items-list. --}}
            <div id="stockItemsList" data-list-table>
                @include("store.stock._items-list")
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------
         Add Item. Was a permanent column on the left; the fields, their
         names and their order are unchanged, only grouped.
         --------------------------------------------------------------- --}}
    <div class="modal fade" id="addItem" tabindex="-1" aria-labelledby="addItemTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content gx-stock-card">
                <form method="POST" action="{{ route('store.stock.items.store') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="addItemTitle">Add Item</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('store.stock._item-fields', ['item' => null])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Bulk upload. Adding items is routine store work, so this needs no
         permission beyond reaching the screen — the same as Add Item. --}}
    <div class="modal fade" id="importItems" tabindex="-1" aria-labelledby="importItemsTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content gx-stock-card">
                <div class="modal-header">
                    <h5 class="modal-title" id="importItemsTitle">Import Items</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="gx-stock-help mb-3">
                        Upload many items at once. Start from the sample template so the columns line up.
                    </p>

                    <a href="{{ route('store.stock.items.template') }}" class="btn btn-outline-secondary w-100 mb-3">
                        <i class="bi bi-download me-1" aria-hidden="true"></i>Download Sample Template
                    </a>

                    <form method="POST" action="{{ route('store.stock.items.import') }}" enctype="multipart/form-data" id="importItemsForm">
                        @csrf
                        <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required
                               aria-label="CSV or Excel file of items to import">
                        <p class="gx-stock-help mb-0">
                            Item Name, Category and Uom are required on every row. Opening Stock defaults to 0,
                            Counted On to today, and Lead Time to
                            {{ config('stock.general_stock.default_lead_time_days', 7) }} days.
                            Leave Safety Stock and Re-order Level blank to calculate them automatically.
                        </p>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="importItemsForm" class="btn btn-primary"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import Items</button>
                </div>
            </div>
        </div>
    </div>

    @include('store.stock._searchable')

    {{-- A rejected form is redirected back with its errors. <x-flash> lists
         them at the top of the page either way, but the modal the user was
         typing in has closed by then — so the one they submitted is reopened,
         still carrying what they entered. The hidden `form` field says which. --}}
    @php
        $submitted = (string) old('form');

        $reopen = match (true) {
            $submitted === 'add' => 'addItem',
            str_starts_with($submitted, 'edit:') => 'editItem'.substr($submitted, 5),
            default => null,
        };
    @endphp

    @if($errors->any() && $reopen)
        <script>
            (function () {
                var el = document.getElementById(@json($reopen));
                if (el && window.bootstrap) {
                    bootstrap.Modal.getOrCreateInstance(el).show();
                }
            })();
        </script>
    @endif
</div>
@endsection
