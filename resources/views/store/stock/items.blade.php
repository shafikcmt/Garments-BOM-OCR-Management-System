@extends('layouts.app')

@section('title', 'General Stock — Items')

@php
    use App\Services\GeneralStockReportService as StockStatus;

    // Presentation for the four statuses. The statuses themselves, and the
    // thresholds behind them, come from GeneralStockReportService — this array
    // only decides how each one is drawn. Labels match the Stock Report word
    // for word so the two screens never call the same state two things.
    $statusMeta = [
        StockStatus::STATUS_OUT => ['label' => 'Out of Stock', 'badge' => 'bg-danger text-white', 'bar' => '#dc2626'],
        StockStatus::STATUS_PLACE_ORDER => ['label' => 'Place Order', 'badge' => 'bg-danger-subtle text-danger', 'bar' => '#dc2626'],
        StockStatus::STATUS_LOW => ['label' => 'Low Stock', 'badge' => 'bg-warning-subtle text-warning-emphasis', 'bar' => '#d97706'],
        StockStatus::STATUS_OK => ['label' => 'Ok', 'badge' => 'bg-success-subtle text-success', 'bar' => '#16a34a'],
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

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Item Master</h3>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1" aria-hidden="true"></i>Stock Report</a>
                <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary"><i class="bi bi-truck me-1" aria-hidden="true"></i>Receiving</a>
                <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Issues</a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')


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

    {{-- Toolbar. Adding an item is now a button rather than a form that is
         always open, which gives the list below the full page width. --}}
    <div class="card gx-stock-card mb-3">
        <div class="gx-stock-card-body">
            <div class="gx-stock-toolbar mb-3">
                <h5>Item Master</h5>
                <span class="badge bg-primary-subtle text-primary">{{ $items->total() }} items</span>

                {{-- Counts across the whole master, not this page. Each one
                     re-runs the list filtered to itself. --}}
                @foreach ([StockStatus::STATUS_OUT, StockStatus::STATUS_PLACE_ORDER, StockStatus::STATUS_LOW] as $chip)
                    @if(($statusCounts[$chip] ?? 0) > 0)
                        <a href="{{ route('store.stock.items.index', array_merge($activeFilters, ['status' => $chip])) }}"
                           class="badge {{ $statusMeta[$chip]['badge'] }} text-decoration-none"
                           @if(($filters['status'] ?? '') === $chip) aria-current="true" @endif>
                            {{ $statusCounts[$chip] }} {{ $statusMeta[$chip]['label'] }}
                        </a>
                    @endif
                @endforeach

                <span class="gx-stock-toolbar-gap"></span>

                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importItems">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Import Items
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addItem">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Item
                </button>
            </div>

            <form method="GET" class="row g-3 gx-stock-filter">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="itemFilterSearch">Search</label>
                    <input id="itemFilterSearch" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control"
                           placeholder="Item, brand, size or category">
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
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>UOM</th>
                            <th class="text-end">Current Stock</th>
                            <th class="text-end">Safety / Re-order</th>
                            <th>Status</th>
                            <th class="text-end gx-stock-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($items as $item)
                            {{-- Lifetime balance: counted opening + everything
                                 received − everything issued. --}}
                            @php
                                $current = (float) $item->opening_qty + (float) $item->purchased_qty - (float) $item->issued_qty;

                                $status = StockStatus::statusFor(
                                    $current,
                                    $item->safety_stock_qty !== null ? (float) $item->safety_stock_qty : null,
                                    $item->reorder_level !== null ? (float) $item->reorder_level : null,
                                );

                                // Only drawn against a real re-order level. Where
                                // that is blank the level is calculated later from
                                // consumption, and a bar against a denominator we
                                // invented here would read as fact.
                                $reorder = $item->reorder_level !== null ? (float) $item->reorder_level : null;
                                $fill = $reorder > 0 ? max(0, min(100, ($current / $reorder) * 100)) : null;
                            @endphp
                            <tr @class(['table-danger' => $status === StockStatus::STATUS_OUT])>
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $item->name }}</div>
                                    {{-- Category · Brand · Size on one line, blanks
                                         dropped so a sparse item does not read as
                                         a row of dashes. --}}
                                    <div class="small text-muted">
                                        {{ collect([$item->category, $item->brand, $item->size])->filter()->implode(' · ') ?: '—' }}
                                    </div>
                                    @if($item->specification)
                                        <div class="gx-stock-spec text-truncate"
                                             title="{{ $item->specification }}">{{ $item->specification }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $item->uom ?: '—' }}</td>
                                <td class="text-end">
                                    <div class="fw-bold text-slate-900">{{ $qty($current) }}</div>
                                    @if($fill !== null)
                                        <div class="gx-stock-health ms-auto"
                                             title="{{ $qty($current) }} against a re-order level of {{ $qty($reorder) }}">
                                            <i style="width:{{ $fill }}%; background:{{ $statusMeta[$status]['bar'] }};"></i>
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end small text-muted">
                                    {{ $item->safety_stock_qty !== null ? $qty($item->safety_stock_qty) : '—' }}
                                    /
                                    {{ $item->reorder_level !== null ? $qty($item->reorder_level) : '—' }}
                                </td>
                                <td>
                                    <span class="badge {{ $statusMeta[$status]['badge'] }}">{{ $statusMeta[$status]['label'] }}</span>
                                    {{-- An inactive item still has a stock level, so
                                         the two are shown together rather than one
                                         replacing the other. --}}
                                    @unless($item->is_active)
                                        <div class="mt-1"><span class="badge bg-secondary-subtle text-secondary">Inactive</span></div>
                                    @endunless
                                </td>
                                {{-- Edit and Delete are Admin / Management rights
                                     (store.edit / store.delete); both controller
                                     methods enforce the same check server-side. --}}
                                <td class="text-end gx-stock-actions">
                                    @if($canEdit)
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editItem{{ $item->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                    @endif
                                    @if($canDelete)
                                        <form method="POST" action="{{ route('store.stock.items.destroy', $item) }}" class="d-inline" onsubmit="return confirm('Remove this item?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                        </form>
                                    @endif
                                    @if(! $canEdit && ! $canDelete)
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="gx-stock-empty">
                                    <span class="gx-stock-empty-icon"><i class="bi bi-{{ $hasFilters ? 'search' : 'box-seam' }}" aria-hidden="true"></i></span>
                                    @if($hasFilters)
                                        <div class="gx-stock-empty-title">No items match this filter</div>
                                        <div class="gx-stock-empty-hint">Try a different search, category or status.</div>
                                    @else
                                        <div class="gx-stock-empty-title">No stock items yet</div>
                                        <div class="gx-stock-empty-hint">Use Add Item above, or import a list.</div>
                                    @endif
                                </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $items->links() }}</div>
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

    {{-- Edit. Same fields, same grouping, same partial as Add. Not rendered at
         all for a role that cannot submit it, so the markup carries no action a
         non-admin could replay. --}}
    @if($canEdit)
        @foreach($items as $item)
            <div class="modal fade" id="editItem{{ $item->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content gx-stock-card">
                        <form method="POST" action="{{ route('store.stock.items.update', $item) }}">
                            @csrf @method('PUT')
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Item</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @include('store.stock._item-fields', ['item' => $item])
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">Update</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif

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
