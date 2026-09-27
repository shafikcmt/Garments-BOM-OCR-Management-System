@extends('layouts.app')

@section('title', 'Departments')

@php
    $userLabel = fn ($u) => $u->name.(trim((string) ($u->designation ?? '')) !== '' ? ' — '.$u->designation : '');
    $departmentLabelOf = fn ($key) => $departments->firstWhere('key', $key)?->label ?? $key;
@endphp

@section('content')
<div class="container-fluid gx-departments">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Settings'],
        ['label' => 'Departments'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Settings</div>
                    <h3 class="app-hero-title mb-0">Departments</h3>
                    <p class="app-hero-copy mb-0">Each department, its roles and head, and the sections under it. A requisition stops at the nearest head only: its section head when one is named, otherwise the department head.</p>
                </div>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Department
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex flex-column gap-3">
        @foreach($departments as $department)
            @php
                $isManagement = $department->key === 'management';
                $formId = 'dept-'.$department->key;
                $panelId = 'panel-'.$department->key;
                $sectionCount = $department->sections->count();
            @endphp
            <div class="card border-0 shadow-sm gx-dept-card {{ $department->is_active ? '' : 'is-inactive' }}" style="border-radius:var(--gx-radius);" id="{{ $formId }}-card" data-dept-key="{{ $department->key }}">

                {{-- Summary row. The whole row toggles the panel; the Active
                     switch inside it belongs to the card's form (form=) and
                     stops the click from also toggling. The hidden 0 sits
                     BEFORE the checkbox so a ticked box wins (last value). --}}
                <div class="gx-dept-summary" role="button" tabindex="0" data-bs-toggle="collapse" data-bs-target="#{{ $panelId }}" aria-expanded="false" aria-controls="{{ $panelId }}">
                    <span class="gx-dept-chevron"><i class="bi bi-chevron-right" aria-hidden="true"></i></span>
                    <span class="gx-dept-name">{{ $department->label }}</span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle font-monospace">{{ $department->key }}</span>
                    <span class="gx-dept-meta">
                        <i class="bi bi-person-badge me-1" aria-hidden="true"></i>
                        @if($department->head)
                            {{ $department->head->name }}
                        @else
                            <span class="text-muted">No head configured</span>
                        @endif
                    </span>
                    @unless($isManagement)
                        <span class="gx-dept-meta text-muted">
                            <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>{{ $sectionCount }} {{ \Illuminate\Support\Str::plural('section', $sectionCount) }}
                        </span>
                    @endunless
                    <span class="ms-auto d-flex align-items-center gap-3">
                        @unless($department->is_active)
                            <span class="badge bg-warning-subtle text-warning-emphasis">Inactive</span>
                        @endunless
                        @unless($isManagement)
                            <span class="form-check form-switch mb-0" data-stop-toggle>
                                <input type="hidden" name="is_active" value="0" form="{{ $formId }}">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="{{ $formId }}-active" form="{{ $formId }}" @checked($department->is_active)>
                                <label class="form-check-label small" for="{{ $formId }}-active">Active</label>
                            </span>
                        @endunless
                    </span>
                </div>

                <div class="collapse" id="{{ $panelId }}">
                    <div class="card-body p-4 pt-3 border-top">
                        <form method="POST" action="{{ route('admin.departments.update', $department) }}" id="{{ $formId }}" data-dept-form data-dept-label="{{ $department->label }}">
                            @csrf
                            @method('PUT')

                            <div class="gx-dept-section-title">Department</div>

                            <div class="row g-3 align-items-start">
                                <div class="col-12 col-md-4 col-xl-3">
                                    <label class="form-label fw-semibold" for="{{ $formId }}-label">Department name</label>
                                    <input type="text" name="label" id="{{ $formId }}-label" class="form-control" value="{{ $department->label }}" maxlength="80" required data-dirty-field>
                                </div>
                                <div class="col-12 col-md-5 col-xl-4">
                                    <label class="form-label fw-semibold" for="{{ $formId }}-head">Department Head</label>
                                    <select name="head_user_id" id="{{ $formId }}-head" class="form-select js-searchable-deferred" data-dirty-field>
                                        <option value="">— No head configured —</option>
                                        @foreach($users as $u)
                                            <option value="{{ $u->id }}" @selected((int) $department->head_user_id === $u->id)>{{ $userLabel($u) }}</option>
                                        @endforeach
                                        @if($department->head && ! $users->contains('id', $department->head_user_id))
                                            <option value="{{ $department->head_user_id }}" selected>{{ $userLabel($department->head) }} (inactive)</option>
                                        @endif
                                    </select>
                                    <div class="form-text">Checks a requisition when its section has no head. Leave blank and the sheet goes straight to Store.</div>
                                </div>
                                <div class="col-6 col-md-3 col-xl-2">
                                    <label class="form-label fw-semibold" for="{{ $formId }}-order">Display order</label>
                                    <input type="number" name="sort_order" id="{{ $formId }}-order" class="form-control" value="{{ $department->sort_order }}" min="0" max="65535" data-dirty-field>
                                </div>
                                <div class="col-12 col-xl-3">
                                    <span class="form-label fw-semibold d-block">Roles in this department</span>
                                    @if($isManagement)
                                        <div class="pt-1">
                                            @foreach($department->roleNames() as $role)
                                                <span class="badge bg-light text-dark border me-1">{{ \Illuminate\Support\Str::headline($role) }}</span>
                                            @endforeach
                                        </div>
                                        <div class="form-text">Fixed. Management and Admin oversee every department.</div>
                                    @else
                                        <select name="roles[]" id="{{ $formId }}-roles" class="form-select js-searchable-deferred" multiple placeholder="Type to add a role…" data-chip-separator=" — now in " data-dirty-field>
                                            @foreach($roles as $name => $label)
                                                @php $home = $roleHomes[$name] ?? null; @endphp
                                                <option value="{{ $name }}" @selected($home === $department->key)>{{ $label }}@if($home && $home !== $department->key) — now in {{ $departmentLabelOf($home) }}@endif</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text">A role belongs to one department. Ticking a role here moves it out of its current one.</div>
                                    @endif
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                            </div>

                            @unless($isManagement)
                                <hr class="my-4">

                                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-2">
                                    <div>
                                        <div class="gx-dept-section-title mb-0">Sections</div>
                                        <div class="form-text mt-0">A section with a head checks its own requisitions; one without sends them to the Department Head. Drag the handle to reorder.</div>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-add-section data-action="{{ route('admin.departments.sections.store', $department) }}" data-dept-label="{{ $department->label }}">
                                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Section
                                    </button>
                                </div>

                                @if($department->sections->isEmpty())
                                    <p class="text-muted small mb-0">No sections yet. Requisitions from this department go to the Department Head.</p>
                                @else
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0 gx-sections-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width:32px;"><span class="visually-hidden">Reorder</span></th>
                                                    <th style="width:36px;">#</th>
                                                    <th>Section</th>
                                                    <th>Section Head</th>
                                                    <th style="width:190px;" class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody data-sections-body>
                                                @foreach($department->sections as $i => $section)
                                                    <tr data-section-row>
                                                        <td>
                                                            <span class="gx-drag-handle" title="Drag to reorder" aria-label="Drag to reorder" data-drag-handle><i class="bi bi-grip-vertical" aria-hidden="true"></i></span>
                                                        </td>
                                                        <td class="text-muted" data-seq>{{ $i + 1 }}</td>
                                                        <td>
                                                            <input type="hidden" name="sections[{{ $i }}][id]" value="{{ $section->id }}" data-field="id">
                                                            <input type="hidden" name="sections[{{ $i }}][name]" value="{{ $section->name }}" data-field="name">
                                                            <input type="hidden" name="sections[{{ $i }}][head_user_id]" value="{{ $section->head_user_id ?? '' }}" data-field="head_user_id">
                                                            <span class="fw-semibold" data-display="name">{{ $section->name }}</span>
                                                        </td>
                                                        <td>
                                                            <span data-display="head" class="{{ $section->head ? '' : 'text-muted' }}">{{ $section->head?->name ?? 'No head — goes to Department Head' }}</span>
                                                        </td>
                                                        <td class="text-end text-nowrap">
                                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-edit-section>
                                                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                                            </button>
                                                            <button type="submit" form="detach-section-{{ $section->id }}" class="btn btn-sm btn-outline-danger ms-1"
                                                                    onclick="return confirm('Remove {{ addslashes($section->name) }} from {{ addslashes($department->label) }}? It stays on the issue register.');">
                                                                <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Remove
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between gap-3 mt-2 d-none" data-order-changed>
                                        <span class="small text-warning-emphasis"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Order changed — click Save to apply.</span>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                                    </div>
                                @endif
                            @endunless
                        </form>

                        @unless($isManagement)
                            @foreach($department->sections as $section)
                                <form method="POST" action="{{ route('admin.departments.sections.detach', $section) }}#dept-{{ $department->key }}" id="detach-section-{{ $section->id }}" class="d-none">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endforeach
                        @endunless
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Add Department --}}
<div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-labelledby="addDepartmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.departments.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="addDepartmentModalLabel">Add Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="newDeptLabel">Department name</label>
                    <input type="text" name="label" id="newDeptLabel" class="form-control" value="{{ old('label') }}" maxlength="80" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="newDeptHead">Department Head</label>
                    <select name="head_user_id" id="newDeptHead" class="form-select js-searchable">
                        <option value="">— No head configured —</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $userLabel($u) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold" for="newDeptRoles">Roles in this department</label>
                    <select name="roles[]" id="newDeptRoles" class="form-select js-searchable" multiple placeholder="Type to add a role…" data-chip-separator=" — now in ">
                        @foreach($roles as $name => $label)
                            @php $home = $roleHomes[$name] ?? null; @endphp
                            <option value="{{ $name }}">{{ $label }}@if($home) — now in {{ $departmentLabelOf($home) }}@endif</option>
                        @endforeach
                    </select>
                    <div class="form-text">Optional. A role belongs to one department; one chosen here moves out of its current one. Roles are created on Admin > Roles.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Department</button>
            </div>
        </form>
    </div>
</div>

{{-- Add Section: one modal, pointed at the department whose button opened it. --}}
<div class="modal fade" id="addSectionModal" tabindex="-1" aria-labelledby="addSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="" class="modal-content" id="addSectionForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="addSectionModalLabel">Add Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="newSectionName">Section name</label>
                    <input type="text" name="name" id="newSectionName" class="form-control" placeholder="e.g. Finishing" maxlength="255" required>
                    <div class="form-text">A name already on the issue register is filed here rather than created twice.</div>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold" for="newSectionHead">Section Head</label>
                    <select name="head_user_id" id="newSectionHead" class="form-select js-searchable">
                        <option value="">— No head — goes to Department Head —</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $userLabel($u) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Section</button>
            </div>
        </form>
    </div>
</div>

{{-- Edit Section: one modal; writes into the row's hidden fields and then
     submits that department's form, so the save goes through the same
     request as the Save button. --}}
<div class="modal fade" id="editSectionModal" tabindex="-1" aria-labelledby="editSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editSectionModalLabel">Edit Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning border-0 small py-2 d-none" data-edit-dirty-warning>
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                    This will also save other unsaved changes in <span data-edit-dept-label></span>.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="editSectionName">Section name</label>
                    <input type="text" id="editSectionName" class="form-control" maxlength="255" required>
                    <div class="invalid-feedback">Give the section a name.</div>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold" for="editSectionHead">Section Head</label>
                    <select id="editSectionHead" class="form-select js-searchable">
                        <option value="">— No head — goes to Department Head —</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $userLabel($u) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" data-edit-section-save><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Section</button>
            </div>
        </div>
    </div>
</div>

@include('store.stock._searchable')

<style>
    .gx-departments .gx-dept-summary {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        padding: .9rem 1.25rem;
        cursor: pointer;
        user-select: none;
        border-radius: var(--gx-radius);
    }
    .gx-departments .gx-dept-summary:hover { background: rgba(0, 0, 0, .015); }
    .gx-departments .gx-dept-summary:focus-visible { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
    .gx-departments .gx-dept-chevron { color: var(--bs-secondary); transition: transform .15s ease; display: inline-flex; }
    .gx-departments .gx-dept-summary[aria-expanded="true"] .gx-dept-chevron { transform: rotate(90deg); }
    .gx-departments .gx-dept-name { font-weight: 600; font-size: 1.02rem; }
    .gx-departments .gx-dept-meta { font-size: .875rem; }
    .gx-departments .gx-dept-card.is-inactive .gx-dept-name { color: var(--bs-secondary); }
    .gx-departments .gx-dept-section-title {
        font-size: .75rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
        color: var(--bs-secondary); margin-bottom: .75rem;
    }
    .gx-departments .gx-drag-handle {
        display: inline-flex; align-items: center; justify-content: center;
        width: 24px; height: 24px; border-radius: 6px; color: var(--bs-secondary); cursor: grab;
    }
    .gx-departments .gx-drag-handle:hover { background: rgba(0, 0, 0, .05); }
    .gx-departments tr.is-dragging { opacity: .45; }
    .gx-departments tr.is-drop-target td { box-shadow: inset 0 2px 0 var(--bs-primary); }
    /* TomSelect chips for the role picker: same tone as the app's badges. */
    .gx-departments .ts-wrapper.multi .ts-control > .item,
    .modal .ts-wrapper.multi .ts-control > .item {
        background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis);
        border: 1px solid var(--bs-primary-border-subtle); border-radius: 6px; padding: .1rem .35rem .1rem .5rem;
    }
    .gx-departments .ts-wrapper.multi .ts-control > .item .remove,
    .modal .ts-wrapper.multi .ts-control > .item .remove { border-left: 0; padding: 0 .35rem; }
</style>

<script>
(function () {
    var searchableInit = function (root) { if (window.gxInitSearchable) { window.gxInitSearchable(root); } };

    // ---- Collapse: build the selects only once a panel is open -----------
    // TomSelect measures a display:none element badly, so a panel's selects
    // are marked deferred and built the first time the panel is shown.
    document.addEventListener('shown.bs.collapse', function (event) {
        var panel = event.target;
        panel.querySelectorAll('select.js-searchable-deferred').forEach(function (el) {
            el.classList.remove('js-searchable-deferred');
            el.classList.add('js-searchable');
        });
        searchableInit(panel);
        snapshotForm(panel.querySelector('[data-dept-form]'));
    });

    // The Active switch lives in the summary row; toggling it must not
    // also open or close the panel.
    document.querySelectorAll('[data-stop-toggle]').forEach(function (el) {
        el.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    // Keyboard: Enter / Space on a focused summary row toggles it.
    document.querySelectorAll('.gx-dept-summary').forEach(function (row) {
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); }
        });
    });

    // Reopen the card the user was working in after a save redirect, or the
    // one a link on another screen pointed at (#dept-<key>).
    function openFromHash() {
        var m = (location.hash || '').match(/^#dept-([a-z0-9_]+)/i);
        if (!m) { return; }
        var card = document.getElementById('dept-' + m[1] + '-card');
        var panel = card && card.querySelector('.collapse');
        if (panel && window.bootstrap) {
            bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).show();
            card.scrollIntoView({ block: 'start' });
        }
    }
    // The layout loads bootstrap.bundle AFTER the page content, so this
    // waits for the document rather than running inline.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openFromHash);
    } else {
        openFromHash();
    }
    window.addEventListener('hashchange', openFromHash);

    // Every form redirects back here; carry the card key in the hash so the
    // same card is open when the page returns.
    document.querySelectorAll('[data-dept-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var key = form.closest('.gx-dept-card').getAttribute('data-dept-key');
            if (!/#dept-/.test(form.action)) { form.action += '#dept-' + key; }
        });
    });

    // ---- Dirty tracking: what a department form looked like when opened --
    function fieldValues(form) {
        var values = Array.prototype.map.call(form.querySelectorAll('[data-dirty-field]'), function (el) {
            if (el.multiple) { return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }).sort().join(','); }
            return el.value;
        });
        var active = document.getElementById(form.id + '-active');
        values.push(active ? String(active.checked) : '');
        return values.join('|');
    }
    function snapshotForm(form) {
        if (form && !form.dataset.snapshot) { form.dataset.snapshot = fieldValues(form); }
    }
    function formIsDirty(form) {
        return form.dataset.snapshot !== undefined && form.dataset.snapshot !== fieldValues(form);
    }

    // ---- Add Section: point the shared modal at the right department -----
    var addSectionModal = document.getElementById('addSectionModal');
    document.querySelectorAll('[data-add-section]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var key = btn.closest('.gx-dept-card').getAttribute('data-dept-key');
            document.getElementById('addSectionForm').action = btn.getAttribute('data-action') + '#dept-' + key;
            document.getElementById('addSectionModalLabel').textContent = 'Add Section — ' + btn.getAttribute('data-dept-label');
            document.getElementById('newSectionName').value = '';
            var head = document.getElementById('newSectionHead');
            if (head.tomselect) { head.tomselect.clear(true); } else { head.value = ''; }
            bootstrap.Modal.getOrCreateInstance(addSectionModal).show();
        });
    });
    addSectionModal.addEventListener('shown.bs.modal', function () { document.getElementById('newSectionName').focus(); });

    // ---- Edit Section: fill from the row, write back, submit the form ----
    var editModal = document.getElementById('editSectionModal');
    var editing = null;

    document.querySelectorAll('[data-edit-section]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('[data-section-row]');
            var form = row.closest('[data-dept-form]');
            editing = row;

            var nameInput = document.getElementById('editSectionName');
            nameInput.value = row.querySelector('[data-field="name"]').value;
            nameInput.classList.remove('is-invalid');

            var head = document.getElementById('editSectionHead');
            var headId = row.querySelector('[data-field="head_user_id"]').value;
            if (head.tomselect) { head.tomselect.setValue(headId, true); } else { head.value = headId; }

            // Other fields changed in this card but not yet saved? Say so:
            // Save Section submits the whole card.
            editModal.querySelector('[data-edit-dirty-warning]').classList.toggle('d-none', !formIsDirty(form));
            editModal.querySelector('[data-edit-dept-label]').textContent = form.getAttribute('data-dept-label');

            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
    });
    editModal.addEventListener('shown.bs.modal', function () { document.getElementById('editSectionName').focus(); });

    editModal.querySelector('[data-edit-section-save]').addEventListener('click', function () {
        if (!editing) { return; }
        var nameInput = document.getElementById('editSectionName');
        var name = nameInput.value.trim();
        if (!name) { nameInput.classList.add('is-invalid'); nameInput.focus(); return; }

        var head = document.getElementById('editSectionHead');
        var headId = head.value || '';
        var headText = headId ? head.options[head.selectedIndex].textContent : '';

        editing.querySelector('[data-field="name"]').value = name;
        editing.querySelector('[data-field="head_user_id"]').value = headId;
        editing.querySelector('[data-display="name"]').textContent = name;
        var headCell = editing.querySelector('[data-display="head"]');
        headCell.textContent = headId ? headText : 'No head — goes to Department Head';
        headCell.classList.toggle('text-muted', !headId);

        var form = editing.closest('[data-dept-form]');
        bootstrap.Modal.getInstance(editModal).hide();
        form.requestSubmit();
    });

    // ---- Drag to reorder sections ----------------------------------------
    // Only the handle starts a drag: the row is made draggable on mousedown
    // over it and un-made on dragend, so selecting text in a cell does not
    // start moving rows. After a drop the hidden field names are renumbered
    // — the server reads order from the index — and the Save prompt appears.
    document.querySelectorAll('[data-sections-body]').forEach(function (body) {
        var dragging = null;

        body.querySelectorAll('[data-drag-handle]').forEach(function (handle) {
            var row = handle.closest('tr');
            handle.addEventListener('mousedown', function () { row.draggable = true; });
            handle.addEventListener('mouseup', function () { row.draggable = false; });
        });

        body.addEventListener('dragstart', function (e) {
            var row = e.target.closest('tr');
            if (!row || !row.draggable) { e.preventDefault(); return; }
            dragging = row;
            row.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });

        body.addEventListener('dragover', function (e) {
            if (!dragging) { return; }
            e.preventDefault();
            var over = e.target.closest('tr');
            if (!over || over === dragging) { return; }
            body.querySelectorAll('tr').forEach(function (r) { r.classList.remove('is-drop-target'); });
            over.classList.add('is-drop-target');
        });

        body.addEventListener('drop', function (e) {
            if (!dragging) { return; }
            e.preventDefault();
            var over = e.target.closest('tr');
            if (over && over !== dragging) {
                var rect = over.getBoundingClientRect();
                var after = (e.clientY - rect.top) > rect.height / 2;
                body.insertBefore(dragging, after ? over.nextSibling : over);
                renumber(body);
                var prompt = body.closest('[data-dept-form]').querySelector('[data-order-changed]');
                if (prompt) { prompt.classList.remove('d-none'); }
            }
        });

        body.addEventListener('dragend', function () {
            body.querySelectorAll('tr').forEach(function (r) { r.classList.remove('is-drop-target', 'is-dragging'); r.draggable = false; });
            dragging = null;
        });
    });

    function renumber(body) {
        Array.prototype.forEach.call(body.querySelectorAll('tr'), function (row, i) {
            row.querySelector('[data-seq]').textContent = i + 1;
            row.querySelectorAll('input[name^="sections["]').forEach(function (el) {
                el.name = el.name.replace(/^sections\[\d+\]/, 'sections[' + i + ']');
            });
        });
    }

    @if($errors->has('label') && old('label') !== null && ! old('_method'))
        // The Add Department modal failed validation: reopen it with what was typed.
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addDepartmentModal')).show();
    @endif
})();
</script>
@endsection
