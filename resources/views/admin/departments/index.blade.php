@extends('layouts.app')

@section('title', 'Departments')

@php
    $userLabel = fn ($u) => $u->name.(trim((string) ($u->designation ?? '')) !== '' ? ' — '.$u->designation : '');
@endphp

@section('content')
<div class="container-fluid">
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
                    <p class="app-hero-copy mb-0">Each department, its roles and head, and the sections under it. A department's requisition goes to its section head, else its department head, before it reaches Store.</p>
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

    <div class="row g-4">
        @foreach($departments as $department)
            @php
                $isManagement = $department->key === 'management';
                $formId = 'dept-'.$department->key;
            @endphp
            <div class="col-12">
                <div class="card border-0 shadow-sm {{ $department->is_active ? '' : 'opacity-75' }}" style="border-radius:var(--gx-radius);">
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('admin.departments.update', $department) }}" id="{{ $formId }}">
                            @csrf
                            @method('PUT')

                            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <h5 class="mb-0">{{ $department->label }}</h5>
                                    <span class="badge bg-light text-secondary border font-monospace">{{ $department->key }}</span>
                                    @unless($department->is_active)
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>
                                    @endunless
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    @unless($isManagement)
                                        <div class="form-check form-switch mb-0 me-2">
                                            <input type="hidden" name="is_active" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="{{ $formId }}-active" @checked($department->is_active)>
                                            <label class="form-check-label small" for="{{ $formId }}-active">Active</label>
                                        </div>
                                    @endunless
                                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-12 col-md-4 col-xl-3">
                                    <label class="form-label fw-semibold" for="{{ $formId }}-label">Department name</label>
                                    <input type="text" name="label" id="{{ $formId }}-label" class="form-control" value="{{ $department->label }}" maxlength="80" required>
                                </div>
                                <div class="col-12 col-md-5 col-xl-4">
                                    <label class="form-label fw-semibold" for="{{ $formId }}-head">Department Head</label>
                                    <select name="head_user_id" id="{{ $formId }}-head" class="form-select js-searchable">
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
                                    <label class="form-label fw-semibold" for="{{ $formId }}-order">Order</label>
                                    <input type="number" name="sort_order" id="{{ $formId }}-order" class="form-control" value="{{ $department->sort_order }}" min="0" max="65535">
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
                                        <div class="border rounded p-2 overflow-auto" style="max-height:132px;" id="{{ $formId }}-roles">
                                            @foreach($roles as $name => $label)
                                                @php $home = $roleHomes[$name] ?? null; @endphp
                                                <div class="form-check form-check-sm mb-1">
                                                    <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $name }}" id="{{ $formId }}-role-{{ $name }}" @checked($home === $department->key)>
                                                    <label class="form-check-label small" for="{{ $formId }}-role-{{ $name }}">
                                                        {{ $label }}
                                                        @if($home && $home !== $department->key)
                                                            <span class="text-muted">— now in {{ $departments->firstWhere('key', $home)?->label ?? $home }}</span>
                                                        @endif
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="form-text">A role belongs to one department. Ticking a role here moves it out of its current one.</div>
                                    @endif
                                </div>
                            </div>

                            @unless($isManagement)
                                <hr class="my-4">

                                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-2">
                                    <h6 class="mb-0">Sections</h6>
                                    <span class="text-muted small">A requisition stops at the nearest head only: its section head when one is named, otherwise the department head.</span>
                                </div>

                                @if($department->sections->isEmpty())
                                    <p class="text-muted small mb-3">No sections yet. Requisitions from this department go to the Department Head.</p>
                                @else
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width:36px;">#</th>
                                                    <th style="min-width:200px;">Section</th>
                                                    <th style="min-width:260px;">Section Head</th>
                                                    <th style="width:150px;" class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($department->sections as $i => $section)
                                                    <tr>
                                                        <td class="text-muted">{{ $i + 1 }}</td>
                                                        <td>
                                                            <input type="hidden" name="sections[{{ $i }}][id]" value="{{ $section->id }}">
                                                            <input type="text" name="sections[{{ $i }}][name]" class="form-control form-control-sm" value="{{ $section->name }}" maxlength="255" required>
                                                        </td>
                                                        <td>
                                                            <select name="sections[{{ $i }}][head_user_id]" class="form-select form-select-sm js-searchable">
                                                                <option value="">— No head (goes to Department Head) —</option>
                                                                @foreach($users as $u)
                                                                    <option value="{{ $u->id }}" @selected((int) $section->head_user_id === $u->id)>{{ $userLabel($u) }}</option>
                                                                @endforeach
                                                                @if($section->head && ! $users->contains('id', $section->head_user_id))
                                                                    <option value="{{ $section->head_user_id }}" selected>{{ $userLabel($section->head) }} (inactive)</option>
                                                                @endif
                                                            </select>
                                                        </td>
                                                        <td class="text-end">
                                                            {{-- Submits the detach form below; it cannot nest inside this one. --}}
                                                            <button type="submit" form="detach-section-{{ $section->id }}" class="btn btn-sm btn-outline-danger"
                                                                    onclick="return confirm('Remove {{ addslashes($section->name) }} from {{ addslashes($department->label) }}? It stays on the issue register.');">
                                                                <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Remove
                                                            </button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            @endunless
                        </form>

                        @unless($isManagement)
                            @foreach($department->sections as $section)
                                <form method="POST" action="{{ route('admin.departments.sections.detach', $section) }}" id="detach-section-{{ $section->id }}" class="d-none">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            @endforeach

                            <form method="POST" action="{{ route('admin.departments.sections.store', $department) }}" class="row g-2 align-items-end">
                                @csrf
                                <div class="col-12 col-md-4 col-xl-3">
                                    <label class="form-label small fw-semibold mb-1" for="{{ $formId }}-new-section">Add section</label>
                                    <input type="text" name="name" id="{{ $formId }}-new-section" class="form-control form-control-sm" placeholder="e.g. Finishing" maxlength="255" required>
                                </div>
                                <div class="col-12 col-md-5 col-xl-4">
                                    <label class="form-label small fw-semibold mb-1" for="{{ $formId }}-new-section-head">Section Head</label>
                                    <select name="head_user_id" id="{{ $formId }}-new-section-head" class="form-select form-select-sm js-searchable">
                                        <option value="">— No head —</option>
                                        @foreach($users as $u)
                                            <option value="{{ $u->id }}">{{ $userLabel($u) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Section</button>
                                </div>
                            </form>
                        @endunless
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

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
                    <select name="head_user_id" id="newDeptHead" class="form-select">
                        <option value="">— No head configured —</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $userLabel($u) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold" for="newDeptRoles">Roles in this department</label>
                    <div class="border rounded p-2 overflow-auto" style="max-height:180px;" id="newDeptRoles">
                        @foreach($roles as $name => $label)
                            @php $home = $roleHomes[$name] ?? null; @endphp
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $name }}" id="new-role-{{ $name }}">
                                <label class="form-check-label small" for="new-role-{{ $name }}">
                                    {{ $label }}
                                    @if($home)
                                        <span class="text-muted">— now in {{ $departments->firstWhere('key', $home)?->label ?? $home }}</span>
                                    @endif
                                </label>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-text">Optional. A role ticked here leaves the department it is in now. Roles are created on Admin > Roles.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Department</button>
            </div>
        </form>
    </div>
</div>

@include('store.stock._searchable')

@if($errors->has('label') && old('label') !== null && ! old('_method'))
    {{-- The Add Department modal failed validation: reopen it with what was typed. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('addDepartmentModal');
            if (el && window.bootstrap) { new bootstrap.Modal(el).show(); }
        });
    </script>
@endif
@endsection
