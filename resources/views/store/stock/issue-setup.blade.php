@extends('layouts.app')

@section('title', 'General Stock — Issue Setup')

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Issue Setup'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon gx-stock-hero-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Issue Setup</h3>
                    <p class="app-hero-copy mb-0">Master lists behind the Indent Section, Indent Person, Approved By and Category fields on the issue form.</p>
                </div>
            </div>
            <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary" title="Back to the issue screen"><i class="bi bi-box-arrow-up me-1" aria-hidden="true"></i>Issues</a>
        </div>
    </div>

    @include('store.stock._stock-ui')


    @include('store._flash')

    {{-- Rows the importer discarded as the file's own headings/notes, and
         entries a bulk delete refused because they are in use. Both are listed
         in full rather than reduced to a count. --}}
    @foreach ([
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

    <ul class="nav nav-pills mb-4" role="tablist">
        @foreach($groups as $type => $group)
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="pill"
                        data-bs-target="#pane-{{ $type }}" type="button" role="tab">
                    <i class="bi {{ $group['icon'] }} me-1" aria-hidden="true"></i>{{ $group['label'] }}
                    <span class="badge bg-white text-primary ms-1">{{ $group['rows']->count() }}</span>
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">
        @foreach($groups as $type => $group)
            <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="pane-{{ $type }}" role="tabpanel">
                <div class="row g-4">
                    <div class="col-12 col-xl-4">
                        <div class="card gx-stock-card">
                            <div class="gx-stock-card-body">
                                <h5 class="mb-3">Add {{ $group['label'] }}</h5>
                                <form method="POST" action="{{ route('store.stock.issue-setup.store', $type) }}">
                                    @csrf
                                    <label class="form-label">Name <span class="text-danger">*</span></label>
                                    <input name="name" class="form-control mb-3" maxlength="150" required
                                           placeholder="{{ $group['placeholder'] }}">
                                    <label class="form-label">Remarks</label>
                                    <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="500"></textarea>
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add</button>
                                </form>

                                {{-- Bulk add. Adding names is routine store work,
                                     so this needs no extra permission — same as
                                     the single-entry form above. It only ever
                                     inserts; existing names are skipped. --}}
                                <hr class="my-4">
                                <h6 class="mb-2">Bulk Upload</h6>
                                <form method="POST" action="{{ route('store.stock.issue-setup.bulk', $type) }}" enctype="multipart/form-data">
                                    @csrf
                                    <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required
                                           aria-label="CSV or Excel file of {{ $group['label'] }} names">
                                    <p class="gx-stock-help mb-2">
                                        CSV or Excel, names in the first column. A header row is skipped automatically.
                                        Names already in the list are left alone.
                                    </p>
                                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-upload me-1" aria-hidden="true"></i>Upload List</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-xl-8">
                        <div class="card gx-stock-card">
                            <div class="gx-stock-card-body">
                                <div class="gx-stock-card-head">
                                    <h5>{{ $group['label'] }} List</h5>
                                    @if($canDelete && $group['rows']->isNotEmpty())
                                        {{-- One confirmation for the whole batch. Entries in
                                             use are refused server-side and named back. --}}
                                        <button type="submit" form="bulk-{{ $type }}"
                                                class="btn btn-sm btn-outline-danger" data-bulk-delete="{{ $type }}" disabled>
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Selected
                                            <span class="badge bg-danger ms-1" data-bulk-count="{{ $type }}">0</span>
                                        </button>
                                    @endif
                                </div>

                                @if($canDelete)
                                    <form method="POST" id="bulk-{{ $type }}"
                                          action="{{ route('store.stock.issue-setup.bulk-delete', $type) }}"
                                          onsubmit="return confirm('Delete the selected ' + this.querySelectorAll('input[name=\'ids[]\']:checked').length + ' entr' + (this.querySelectorAll('input[name=\'ids[]\']:checked').length === 1 ? 'y' : 'ies') + '? This cannot be undone from the screen.');">
                                        @csrf @method('DELETE')
                                    </form>
                                @endif

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0" data-bulk-table="{{ $type }}">
                                        <thead>
                                            <tr>
                                                @if($canDelete)
                                                    <th style="width:34px;">
                                                        <input type="checkbox" class="form-check-input" data-bulk-all="{{ $type }}"
                                                               aria-label="Select all {{ $group['label'] }} entries">
                                                    </th>
                                                @endif
                                                <th>Name</th><th>Status</th>
                                                <th class="text-end">Used On</th>
                                                <th>Remarks</th>
                                                <th class="text-end gx-stock-actions">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($group['rows'] as $row)
                                                <tr>
                                                    @if($canDelete)
                                                        <td>
                                                            {{-- form= lets the checkbox live in the table
                                                                 while submitting with the bulk form above,
                                                                 which a nested <form> could not do. --}}
                                                            <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $row->id }}"
                                                                   form="bulk-{{ $type }}" data-bulk-row="{{ $type }}"
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
                                                    <td class="text-end small text-muted">{{ number_format($row->issues_count) }} issue(s)</td>
                                                    <td class="small text-muted">{{ $row->remarks ?: '—' }}</td>
                                                    {{-- Edit and Delete are Admin / Management
                                                         rights (store.edit / store.delete); both
                                                         controller methods enforce the same check
                                                         server-side. --}}
                                                    <td class="text-end gx-stock-actions">
                                                        @if($canEdit)
                                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                                                    data-bs-toggle="modal" data-bs-target="#edit-{{ $type }}-{{ $row->id }}"
                                                                    ><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</button>
                                                        @endif
                                                        @if($canDelete)
                                                            <form method="POST" action="{{ route('store.stock.issue-setup.destroy', [$type, $row->id]) }}" class="d-inline"
                                                                  onsubmit="return confirm('Remove &quot;{{ $row->name }}&quot; from the list?');">
                                                                @csrf @method('DELETE')
                                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                                            </form>
                                                        @endif
                                                        @if(! $canEdit && ! $canDelete)
                                                            <span class="text-muted small">—</span>
                                                        @endif
                                                    </td>
                                                </tr>

                                                {{-- Not rendered for a role that cannot submit it. --}}
                                                @if($canEdit)
                                                    <div class="modal fade" id="edit-{{ $type }}-{{ $row->id }}" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content gx-stock-card">
                                                                <form method="POST" action="{{ route('store.stock.issue-setup.update', [$type, $row->id]) }}">
                                                                    @csrf @method('PUT')
                                                                    <div class="modal-header"><h5 class="modal-title">Edit {{ $group['label'] }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                                                    <div class="modal-body">
                                                                        <label class="form-label">Name <span class="text-danger">*</span></label>
                                                                        <input name="name" value="{{ $row->name }}" class="form-control mb-3" maxlength="150" required>
                                                                        <div class="form-check mb-3">
                                                                            <input type="hidden" name="is_active" value="0">
                                                                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                                                                   id="active-{{ $type }}-{{ $row->id }}" {{ $row->is_active ? 'checked' : '' }}>
                                                                            <label class="form-check-label" for="active-{{ $type }}-{{ $row->id }}">Active (shown in the dropdown)</label>
                                                                        </div>
                                                                        <label class="form-label">Remarks</label>
                                                                        <textarea name="remarks" rows="2" class="form-control" maxlength="500">{{ $row->remarks }}</textarea>
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
                                                <tr><td colspan="{{ $canDelete ? 6 : 5 }}" class="gx-stock-empty">
                                                        <span class="gx-stock-empty-icon"><i class="bi bi-list-ul" aria-hidden="true"></i></span>
                                                        <div class="gx-stock-empty-title">No entries yet</div>
                                                        <div class="gx-stock-empty-hint">Add one on the left, or upload a list.</div>
                                                    </td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                <p class="gx-stock-help mt-3">
                                    Removing a name hides it from the dropdown. Issue records that already use it keep the name.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        // Select-all and the live selected count for each tab's bulk delete.
        // Each tab has its own form id, so the tabs never affect each other.
        (function () {
            document.querySelectorAll('[data-bulk-all]').forEach(function (selectAll) {
                var type = selectAll.dataset.bulkAll;
                var rows = Array.prototype.slice.call(
                    document.querySelectorAll('[data-bulk-row="' + type + '"]')
                );
                var button = document.querySelector('[data-bulk-delete="' + type + '"]');
                var count = document.querySelector('[data-bulk-count="' + type + '"]');

                function sync() {
                    var checked = rows.filter(function (row) { return row.checked; }).length;

                    if (count) { count.textContent = checked; }
                    // Nothing selected means nothing to delete — leaving the
                    // button live would only produce a validation error.
                    if (button) { button.disabled = checked === 0; }

                    selectAll.checked = checked > 0 && checked === rows.length;
                    selectAll.indeterminate = checked > 0 && checked < rows.length;
                }

                selectAll.addEventListener('change', function () {
                    rows.forEach(function (row) { row.checked = selectAll.checked; });
                    sync();
                });

                rows.forEach(function (row) { row.addEventListener('change', sync); });

                sync();
            });
        })();
    </script>
</div>
@endsection
