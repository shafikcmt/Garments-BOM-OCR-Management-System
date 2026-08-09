@extends('layouts.app')

@section('title', 'General Stock — Master Setup')

@php
    // Where each tab's forms post. Suppliers is the odd one out: its routes take
    // the record, the other four take {type} then the id.
    $isSuppliers = fn (string $key) => $key === 'suppliers';

    $storeUrl = fn (string $key) => $isSuppliers($key)
        ? route('store.stock.purchase-setup.store')
        : route('store.stock.issue-setup.store', $key);

    $importUrl = fn (string $key) => $isSuppliers($key)
        ? route('store.stock.purchase-setup.import')
        : route('store.stock.issue-setup.bulk', $key);

    $templateUrl = fn (string $key) => $isSuppliers($key)
        ? route('store.stock.purchase-setup.template')
        : route('store.stock.issue-setup.template', $key);

    $bulkDeleteUrl = fn (string $key) => $isSuppliers($key)
        ? route('store.stock.purchase-setup.bulk-delete')
        : route('store.stock.issue-setup.bulk-delete', $key);

    $updateUrl = fn (string $key, $row) => $isSuppliers($key)
        ? route('store.stock.purchase-setup.update', $row->id)
        : route('store.stock.issue-setup.update', [$key, $row->id]);

    $destroyUrl = fn (string $key, $row) => $isSuppliers($key)
        ? route('store.stock.purchase-setup.destroy', $row->id)
        : route('store.stock.issue-setup.destroy', [$key, $row->id]);
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Master Setup'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Master Setup</h3>
                    <p class="app-hero-copy mb-0">The lists behind the purchase, issue and item forms.</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary"><i class="bi bi-truck me-1" aria-hidden="true"></i>Receiving</a>
                <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Issues</a>
                <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Items</a>
            </div>
        </div>
    </div>

    @include('store.stock._stock-ui')


    @include('store._flash')

    {{-- Both screens' reports are shown here, because either kind can now
         arrive on this one page: the first two come from the supplier import,
         the last two from an issue-master upload or a refused bulk delete. --}}
    @foreach ([
        ['key' => 'import_errors', 'tone' => 'danger', 'icon' => 'x-circle-fill', 'heading' => 'These rows need correcting before the file can be imported:'],
        ['key' => 'import_skipped', 'tone' => 'warning', 'icon' => 'exclamation-triangle-fill', 'heading' => 'These rows were skipped:'],
        ['key' => 'bulk_blocked', 'tone' => 'warning', 'icon' => 'exclamation-triangle-fill', 'heading' => 'These entries were kept because issue records use them:'],
        ['key' => 'import_ignored', 'tone' => 'info', 'icon' => 'info-circle-fill', 'heading' => 'These rows were ignored as headings or notes:'],
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

    {{-- Which tab is open is carried in the URL, so a redirect after a save,
         the two old bookmarks and a browser refresh all land back on the list
         the user was working in. --}}
    <ul class="nav gx-seg mb-0" role="tablist">
        @foreach($tabs as $key => $tab)
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $active === $key ? 'active' : '' }}" type="button" role="tab"
                        data-bs-toggle="tab" data-bs-target="#pane-{{ $key }}" data-setup-tab="{{ $key }}"
                        aria-selected="{{ $active === $key ? 'true' : 'false' }}"
                        aria-controls="pane-{{ $key }}">
                    <i class="bi {{ $tab['icon'] }}" aria-hidden="true"></i>{{ $tab['label'] }}
                    <span class="gx-seg-count">{{ $tab['rows']->count() }}</span>
                </button>
            </li>
        @endforeach
    </ul>
    <p class="gx-seg-note" id="setupTabNote">{{ $tabs[$active]['note'] }}</p>

    <div class="tab-content">
        @foreach($tabs as $key => $tab)
            @php
                $usedCount = $tab['used_count'];
                $canBulkDelete = $canDelete && $tab['rows']->isNotEmpty();
            @endphp
            <div class="tab-pane fade {{ $active === $key ? 'show active' : '' }}" id="pane-{{ $key }}" role="tabpanel">
                <div class="row g-4">

                    {{-- ---------------- Add + Import ---------------- --}}
                    <div class="col-12 col-xl-4">
                        <div class="card gx-stock-card">
                            <div class="gx-stock-card-body">
                                <h5 class="mb-3">Add {{ $tab['singular'] }}</h5>

                                <form method="POST" action="{{ $storeUrl($key) }}">
                                    @csrf
                                    <label class="form-label" for="name-{{ $key }}">Name <span class="text-danger">*</span></label>
                                    <input id="name-{{ $key }}" name="name" class="form-control mb-3"
                                           maxlength="{{ $isSuppliers($key) ? 255 : 150 }}" required
                                           placeholder="{{ $tab['placeholder'] }}">

                                    {{-- Suppliers has no remarks column. --}}
                                    @if($tab['has_remarks'])
                                        <label class="form-label" for="remarks-{{ $key }}">Remarks</label>
                                        <textarea id="remarks-{{ $key }}" name="remarks" rows="2" class="form-control mb-3" maxlength="500"></textarea>
                                    @endif

                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add {{ $tab['singular'] }}</button>
                                </form>

                                {{-- Bulk upload. Adding names is routine store work, so
                                     this needs no permission beyond reaching the screen —
                                     the same as the single Add form above. --}}
                                <hr class="my-4">
                                <h6 class="mb-1">Import {{ $tab['label'] }}</h6>
                                <p class="gx-stock-help mb-3">{{ $tab['import_help'] }}</p>

                                <a href="{{ $templateUrl($key) }}" class="btn btn-outline-secondary w-100 mb-3">
                                    <i class="bi bi-download me-1" aria-hidden="true"></i>Download Sample Template
                                </a>

                                <form method="POST" action="{{ $importUrl($key) }}" enctype="multipart/form-data">
                                    @csrf
                                    <input type="file" name="file" class="form-control mb-3" accept=".csv,.txt,.xlsx,.xls" required
                                           aria-label="CSV or Excel file of {{ $tab['label'] }} to import">
                                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import {{ $tab['label'] }}</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- ---------------- The list ---------------- --}}
                    <div class="col-12 col-xl-8">
                        <div class="card gx-stock-card">
                            <div class="gx-stock-card-body">
                                <div class="gx-stock-card-head">
                                    <h5>{{ $tab['label'] }} <span class="badge bg-primary-subtle text-primary ms-1" data-list-count="{{ $key }}">{{ $tab['rows']->count() }}</span></h5>
                                    @if($canBulkDelete)
                                        {{-- One confirmation for the whole batch. --}}
                                        <button type="submit" form="bulk-{{ $key }}"
                                                class="btn btn-sm btn-outline-danger" data-bulk-delete="{{ $key }}" disabled>
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Selected
                                            <span class="badge bg-danger ms-1" data-bulk-count="{{ $key }}">0</span>
                                        </button>
                                    @endif
                                </div>

                                @if($canBulkDelete)
                                    <form method="POST" id="bulk-{{ $key }}" action="{{ $bulkDeleteUrl($key) }}"
                                          onsubmit="return confirm('Delete the selected ' + this.querySelectorAll('input[name=\'ids[]\']:checked').length + ' entr' + (this.querySelectorAll('input[name=\'ids[]\']:checked').length === 1 ? 'y' : 'ies') + '? This cannot be undone from the screen.');">
                                        @csrf @method('DELETE')
                                    </form>
                                @endif

                                {{-- Filters the rows already on the page. The whole list is
                                     rendered — this screen has never paginated — so a
                                     client-side match is complete rather than a partial
                                     view of one page, and it costs no reload, which is
                                     what keeps ?tab= and the open tab where they are.
                                     Deliberately outside every <form> on the card so
                                     Enter cannot submit an add or a bulk delete. --}}
                                <div class="row g-3 gx-stock-filter mb-3">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label" for="search-{{ $key }}">Search</label>
                                        <input type="search" id="search-{{ $key }}" class="form-control"
                                               data-setup-search="{{ $key }}" autocomplete="off"
                                               placeholder="Search {{ strtolower($tab['label']) }} by name">
                                    </div>
                                    <div class="col-12 col-md-7 d-flex align-items-end">
                                        <p class="gx-stock-help mb-0" data-search-status="{{ $key }}" role="status" aria-live="polite"></p>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table align-middle gx-stock-table">
                                        <thead>
                                            <tr>
                                                @if($canBulkDelete)
                                                    <th style="width:34px;">
                                                        <input type="checkbox" class="form-check-input" data-bulk-all="{{ $key }}"
                                                               aria-label="Select all {{ $tab['label'] }} entries">
                                                    </th>
                                                @endif
                                                <th style="min-width:200px;">Name</th>
                                                <th>Status</th>
                                                <th class="text-end">Used On</th>
                                                <th>{{ $tab['has_remarks'] ? 'Remarks' : 'Added' }}</th>
                                                <th class="text-end gx-stock-actions">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($tab['rows'] as $row)
                                                @php $used = (int) ($row->{$usedCount} ?? 0); @endphp
                                                {{-- The name is carried on the row so the search matches the
                                                     stored value, not whatever the cell happens to render. --}}
                                                <tr data-setup-row="{{ $key }}" data-row-name="{{ \Illuminate\Support\Str::lower($row->name) }}">
                                                    @if($canBulkDelete)
                                                        <td>
                                                            {{-- form= lets the checkbox live in the table while
                                                                 submitting with the bulk form above, which a
                                                                 nested <form> could not do. --}}
                                                            <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $row->id }}"
                                                                   form="bulk-{{ $key }}" data-bulk-row="{{ $key }}"
                                                                   aria-label="Select {{ $row->name }}">
                                                        </td>
                                                    @endif
                                                    <td class="fw-semibold text-slate-900">{{ $row->name }}</td>
                                                    <td>
                                                        @if($row->is_active)
                                                            <span class="badge bg-success-subtle text-success">Active</span>
                                                        @else
                                                            <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                                        @endif
                                                    </td>
                                                    <td class="text-end small text-muted">{{ number_format($used) }} {{ $tab['used_word'] }}(s)</td>
                                                    <td class="small text-muted">
                                                        {{ $tab['has_remarks']
                                                            ? ($row->remarks ?: '—')
                                                            : (optional($row->created_at)->format('d-M-Y') ?? '—') }}
                                                    </td>
                                                    {{-- Edit and Delete are Admin / Management rights
                                                         (store.edit / store.delete); both controller
                                                         methods enforce the same check server-side. --}}
                                                    <td class="text-end gx-stock-actions">
                                                        @if($canEdit)
                                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                                                    data-bs-toggle="modal" data-bs-target="#edit-{{ $key }}-{{ $row->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                                        @endif
                                                        @if($canDelete)
                                                            <form method="POST" action="{{ $destroyUrl($key, $row) }}" class="d-inline"
                                                                  onsubmit="return confirm('Remove &quot;{{ $row->name }}&quot; from the list?');">
                                                                @csrf @method('DELETE')
                                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                                            </form>
                                                        @endif
                                                        @if(! $canEdit && ! $canDelete)
                                                            <span class="text-muted small">—</span>
                                                        @endif
                                                    </td>
                                                </tr>

                                                {{-- Not rendered for a role that cannot submit it. --}}
                                                @if($canEdit)
                                                    <div class="modal fade" id="edit-{{ $key }}-{{ $row->id }}" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content gx-stock-card">
                                                                <form method="POST" action="{{ $updateUrl($key, $row) }}">
                                                                    @csrf @method('PUT')
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title">Edit {{ $tab['singular'] }}</h5>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <label class="form-label" for="edit-name-{{ $key }}-{{ $row->id }}">Name <span class="text-danger">*</span></label>
                                                                        <input id="edit-name-{{ $key }}-{{ $row->id }}" name="name" value="{{ $row->name }}"
                                                                               class="form-control mb-3" maxlength="{{ $isSuppliers($key) ? 255 : 150 }}" required>
                                                                        <div class="form-check mb-3">
                                                                            <input type="hidden" name="is_active" value="0">
                                                                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                                                   id="active-{{ $key }}-{{ $row->id }}" {{ $row->is_active ? 'checked' : '' }}>
                                                                            <label class="form-check-label" for="active-{{ $key }}-{{ $row->id }}">Active (shown in the dropdown)</label>
                                                                        </div>
                                                                        @if($tab['has_remarks'])
                                                                            <label class="form-label" for="edit-remarks-{{ $key }}-{{ $row->id }}">Remarks</label>
                                                                            <textarea id="edit-remarks-{{ $key }}-{{ $row->id }}" name="remarks" rows="2"
                                                                                      class="form-control" maxlength="500">{{ $row->remarks }}</textarea>
                                                                        @endif
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" class="btn btn-primary">Update</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            @empty
                                                <tr><td colspan="{{ $canBulkDelete ? 6 : 5 }}" class="gx-stock-empty">
                                                        <span class="gx-stock-empty-icon"><i class="bi {{ $tab['icon'] }}" aria-hidden="true"></i></span>
                                                        <div class="gx-stock-empty-title">No {{ strtolower($tab['label']) }} yet</div>
                                                        <div class="gx-stock-empty-hint">Add one on the left, or import a list.</div>
                                                    </td></tr>
                                            @endforelse

                                            {{-- Shown by the search only when a term matches nothing. --}}
                                            <tr data-no-match="{{ $key }}" hidden>
                                                <td colspan="{{ $canBulkDelete ? 6 : 5 }}" class="gx-stock-empty">
                                                    <span class="gx-stock-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>
                                                    <div class="gx-stock-empty-title">No match</div>
                                                    <div class="gx-stock-empty-hint">No {{ strtolower($tab['label']) }} name contains <strong data-no-match-term="{{ $key }}"></strong>.</div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p class="gx-stock-help mt-3">
                                    @if($tab['blocks_delete_when_used'])
                                        A name already used on an issue record cannot be removed — edit it and untick Active
                                        to hide it from the dropdown instead. Issue records keep the name either way.
                                    @else
                                        Removing a supplier hides it from the purchase dropdown. Purchases that already use it keep the name.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        @endforeach
    </div>

    <script>
        (function () {
            var notes = @json(collect($tabs)->map->note);

            // Keep ?tab= in step with the open tab, so a refresh, a redirect
            // after saving and the browser Back button all stay put. replaceState
            // rather than push: switching tabs is not a navigation step.
            document.querySelectorAll('[data-setup-tab]').forEach(function (btn) {
                btn.addEventListener('shown.bs.tab', function () {
                    var key = btn.dataset.setupTab;
                    var url = new URL(window.location.href);
                    url.searchParams.set('tab', key);
                    window.history.replaceState({}, '', url);

                    var note = document.getElementById('setupTabNote');
                    if (note && notes[key]) { note.textContent = notes[key]; }
                });
            });

            // Each tab's sync(), so the search can re-run it after hiding rows.
            var syncers = {};

            // Select-all and the live selected count for each tab's bulk delete.
            // Each tab has its own form id, so the tabs never affect each other.
            document.querySelectorAll('[data-bulk-all]').forEach(function (selectAll) {
                var type = selectAll.dataset.bulkAll;
                var rows = Array.prototype.slice.call(
                    document.querySelectorAll('[data-bulk-row="' + type + '"]')
                );
                var button = document.querySelector('[data-bulk-delete="' + type + '"]');
                var count = document.querySelector('[data-bulk-count="' + type + '"]');

                // A row filtered out by the search is not on screen, so it must
                // not be selectable and must not be swept up by Select all —
                // deleting something the user cannot see is the one outcome
                // this screen must never produce.
                function visible() {
                    return rows.filter(function (row) {
                        var tr = row.closest('tr');
                        return tr && !tr.hidden;
                    });
                }

                function sync() {
                    var vis = visible();
                    var checked = rows.filter(function (row) { return row.checked; }).length;

                    if (count) { count.textContent = checked; }
                    // Nothing selected means nothing to delete — leaving the
                    // button live would only produce a validation error.
                    if (button) { button.disabled = checked === 0; }

                    var visChecked = vis.filter(function (row) { return row.checked; }).length;
                    selectAll.checked = vis.length > 0 && visChecked === vis.length;
                    selectAll.indeterminate = visChecked > 0 && visChecked < vis.length;
                    selectAll.disabled = vis.length === 0;
                }

                selectAll.addEventListener('change', function () {
                    visible().forEach(function (row) { row.checked = selectAll.checked; });
                    sync();
                });

                rows.forEach(function (row) { row.addEventListener('change', sync); });

                syncers[type] = sync;
                sync();
            });

            // Per-tab search over the rows already on the page.
            document.querySelectorAll('[data-setup-search]').forEach(function (input) {
                var type = input.dataset.setupSearch;
                var rows = Array.prototype.slice.call(
                    document.querySelectorAll('[data-setup-row="' + type + '"]')
                );
                var noMatch = document.querySelector('[data-no-match="' + type + '"]');
                var noMatchTerm = document.querySelector('[data-no-match-term="' + type + '"]');
                var status = document.querySelector('[data-search-status="' + type + '"]');
                var countBadge = document.querySelector('[data-list-count="' + type + '"]');
                var total = rows.length;

                function apply() {
                    var term = input.value.trim().toLowerCase();
                    var shown = 0;

                    rows.forEach(function (tr) {
                        var hit = term === '' || (tr.dataset.rowName || '').indexOf(term) !== -1;
                        tr.hidden = !hit;

                        // Clear the tick on anything that just left the screen,
                        // so a hidden row can never ride along on a bulk delete.
                        if (!hit) {
                            var box = tr.querySelector('[data-bulk-row]');
                            if (box) { box.checked = false; }
                        }

                        if (hit) { shown++; }
                    });

                    if (noMatch) { noMatch.hidden = !(term !== '' && shown === 0); }
                    if (noMatchTerm) { noMatchTerm.textContent = input.value.trim(); }
                    if (countBadge) { countBadge.textContent = term === '' ? total : shown; }
                    if (status) {
                        status.textContent = term === '' ? '' : 'Showing ' + shown + ' of ' + total + '.';
                    }

                    // Selection state depends on what is visible, so re-run it.
                    if (syncers[type]) { syncers[type](); }
                }

                input.addEventListener('input', apply);
                // A search field's own clear "x" fires search, not input.
                input.addEventListener('search', apply);
            });
        })();
    </script>
</div>
@endsection
