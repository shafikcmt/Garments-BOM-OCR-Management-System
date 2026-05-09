@extends('layouts.app')

@section('title', 'Excel Header Control')

@section('content')
@php
    $totalHeaders = $headers->count();
    $activeHeaders = $headers->where('is_active', true)->count();
    $requiredHeaders = $headers->where('is_required', true)->count();
    $formulaHeaders = $headers->filter(fn ($header) => in_array($header->value_mode ?? 'input', ['formula', 'conditional'], true))->count();
    $roleOptions = $headers->pluck('ownerRole.name')->filter()->unique()->sort()->values();
@endphp

<style>
    .header-page {
        --soft-border: #e9edf5;
        --soft-text: #5b6475;
        --soft-heading: #1f2a44;
        --soft-bg: #f6f8fc;
        --soft-card: #ffffff;
        --accent: #376ac3;
        overflow-x: hidden;
    }

    .header-page .page-hero {
        background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
        border: 1px solid #e4ecfb;
        border-radius: 14px;
        padding: 18px 20px;
        box-shadow: 0 8px 22px rgba(40, 72, 145, 0.07);
    }

    .header-page .page-title {
        font-size: 1.55rem;
        font-weight: 700;
        color: var(--soft-heading);
        margin-bottom: 4px;
    }

    .header-page .page-subtitle {
        color: var(--soft-text);
        margin-bottom: 0;
        font-size: 0.92rem;
    }

    .header-page .stat-card {
        background: var(--soft-card);
        border: 1px solid var(--soft-border);
        border-radius: 14px;
        padding: 14px 16px;
        box-shadow: 0 6px 18px rgba(31, 42, 68, 0.04);
        height: 100%;
    }

    .header-page .stat-label {
        font-size: 0.78rem;
        color: var(--soft-text);
        margin-bottom: 6px;
        display: block;
    }

    .header-page .stat-value {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--soft-heading);
        line-height: 1;
    }

    .header-page .filters-card,
    .header-page .table-card {
        background: var(--soft-card);
        border: 1px solid var(--soft-border);
        border-radius: 16px;
        box-shadow: 0 8px 22px rgba(31, 42, 68, 0.05);
    }

    .header-page .filters-card .card-body,
    .header-page .table-card .card-body {
        padding: 16px;
    }

    .header-page .form-label-sm {
        display: block;
        margin-bottom: 5px;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--soft-text);
    }

    .header-page .form-control,
    .header-page .form-select {
        border-radius: 10px;
        border-color: #dbe3f0;
        min-height: 40px;
        box-shadow: none;
        font-size: 0.92rem;
    }

    .header-page .form-control:focus,
    .header-page .form-select:focus {
        border-color: #8fb1ff;
        box-shadow: 0 0 0 0.15rem rgba(55, 106, 195, 0.12);
    }

    .header-page .table-wrap {
        max-height: 72vh;
        overflow-y: auto;
        overflow-x: hidden;
        border-radius: 12px;
    }

    .header-page .table {
        --bs-table-bg: transparent;
        margin-bottom: 0;
        width: 100%;
        table-layout: fixed;
    }

    .header-page .table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f4f7fc;
        color: var(--soft-heading);
        font-weight: 700;
        font-size: 0.82rem;
        border-bottom-width: 1px;
        white-space: normal;
        line-height: 1.25;
    }

    .header-page .table tbody tr {
        transition: all 0.2s ease;
    }

    .header-page .table tbody tr:hover {
        background: #fbfcff;
    }

    .header-page .table td,
    .header-page .table th {
        vertical-align: top;
        border-color: #edf1f7;
        padding: 10px 8px;
        word-wrap: break-word;
        word-break: break-word;
        min-width: 0 !important;
    }

    .header-page .header-name {
        font-weight: 700;
        color: var(--soft-heading);
        margin-bottom: 3px;
        font-size: 0.95rem;
        line-height: 1.25;
    }

    .header-page .header-key {
        display: inline-block;
        font-size: 0.74rem;
        color: #6b7280;
        background: #f3f4f6;
        border-radius: 999px;
        padding: 2px 8px;
        max-width: 100%;
    }

    .header-page .role-badge,
    .header-page .soft-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 0.73rem;
        font-weight: 600;
        border: 1px solid transparent;
        white-space: normal;
        line-height: 1.2;
        text-align: center;
    }

    .header-page .role-badge {
        background: #eef4ff;
        color: #2f5fae;
        border-color: #d8e5ff;
    }

    .header-page .soft-badge-success {
        background: #ecfdf3;
        color: #047857;
        border-color: #c7f1d8;
    }

    .header-page .soft-badge-danger {
        background: #fff1f2;
        color: #be123c;
        border-color: #ffd3dc;
    }

    .header-page .soft-badge-warning {
        background: #fff7ed;
        color: #c2410c;
        border-color: #fed7aa;
    }

    .header-page .soft-badge-info {
        background: #eff6ff;
        color: #1d4ed8;
        border-color: #dbeafe;
    }

    .header-page .soft-badge-secondary {
        background: #f5f3ff;
        color: #6d28d9;
        border-color: #e9d5ff;
    }

    .header-page .soft-badge-muted {
        background: #f8fafc;
        color: #475569;
        border-color: #e2e8f0;
    }

    .header-page .access-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: flex-start;
    }

    .header-page .btn {
        border-radius: 10px;
        font-weight: 600;
    }

    .header-page .btn-primary {
        box-shadow: 0 6px 16px rgba(55, 106, 195, 0.16);
    }

    .header-page .btn-outline-secondary,
    .header-page .btn-outline-warning,
    .header-page .btn-outline-danger {
        border-width: 1px;
    }

    .header-page .btn-sm {
        padding: 0.28rem 0.65rem;
        font-size: 0.76rem;
    }

    .header-page .empty-state {
        padding: 36px 14px;
        text-align: center;
        color: var(--soft-text);
    }

    .header-page .search-hint,
    .header-page .formula-key-note {
        font-size: 0.76rem;
        color: var(--soft-text);
        line-height: 1.35;
    }

    .header-page .section-title {
        font-size: 1.05rem;
        font-weight: 700;
        margin-bottom: 2px;
        color: var(--soft-heading);
    }

    .header-page .section-subtitle {
        color: var(--soft-text);
        margin-bottom: 0;
        font-size: 0.88rem;
    }

    .header-page .actions-stack {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .header-page #headerControlTable th:nth-child(1),
    .header-page #headerControlTable td:nth-child(1) { width: 7%; }
    .header-page #headerControlTable th:nth-child(2),
    .header-page #headerControlTable td:nth-child(2) { width: 23%; }
    .header-page #headerControlTable th:nth-child(3),
    .header-page #headerControlTable td:nth-child(3) { width: 12%; }
    .header-page #headerControlTable th:nth-child(4),
    .header-page #headerControlTable td:nth-child(4) { width: 10%; }
    .header-page #headerControlTable th:nth-child(5),
    .header-page #headerControlTable td:nth-child(5) { width: 14%; }
    .header-page #headerControlTable th:nth-child(6),
    .header-page #headerControlTable td:nth-child(6) { width: 22%; }
    .header-page #headerControlTable th:nth-child(7),
    .header-page #headerControlTable td:nth-child(7) { width: 12%; }

    @media (max-width: 1199.98px) {
        .header-page .page-hero {
            padding: 16px;
        }

        .header-page .table-wrap {
            overflow-x: auto;
        }

        .header-page .table {
            table-layout: auto;
            min-width: 980px;
        }
    }

    @media (max-width: 767.98px) {
        .header-page .page-title {
            font-size: 1.3rem;
        }

        .header-page .filters-card .card-body,
        .header-page .table-card .card-body {
            padding: 14px;
        }

        .header-page .table-wrap {
            max-height: none;
            overflow-x: auto;
        }

        .header-page .table {
            min-width: 920px;
        }
    }
</style>

<div class="container-fluid py-2 px-2 px-md-3 header-page">
    <div class="page-hero mb-3">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h3 class="page-title">Excel Header Control</h3>
                <p class="page-subtitle">Header search, filter, and control panel for input, formula, and conditional fields.</p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.headers.create') }}" class="btn btn-primary px-3">
                    + Add New Header
                </a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-label">Total Headers</span>
                <div class="stat-value">{{ $totalHeaders }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-label">Active Headers</span>
                <div class="stat-value">{{ $activeHeaders }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-label">Required Headers</span>
                <div class="stat-value">{{ $requiredHeaders }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-label">Formula / Conditional</span>
                <div class="stat-value">{{ $formulaHeaders }}</div>
            </div>
        </div>
    </div>

    <div class="card filters-card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-lg-4">
                    <label class="form-label-sm" for="headerSearchInput">Search Header</label>
                    <input
                        type="text"
                        id="headerSearchInput"
                        class="form-control"
                        placeholder="Search by name, key, role, type..."
                    >
                    <div class="search-hint mt-1">Name, key, role, type, formula key sobkichu diye search korte parben.</div>
                </div>

                <div class="col-md-3 col-lg-2">
                    <label class="form-label-sm" for="roleFilter">Owner Role</label>
                    <select id="roleFilter" class="form-select">
                        <option value="">All Roles</option>
                        @foreach($roleOptions as $roleName)
                            <option value="{{ strtolower($roleName) }}">{{ ucfirst(str_replace('_', ' ', $roleName)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3 col-lg-2">
                    <label class="form-label-sm" for="modeFilter">Value Mode</label>
                    <select id="modeFilter" class="form-select">
                        <option value="">All Modes</option>
                        <option value="input">Input</option>
                        <option value="formula">Formula</option>
                        <option value="conditional">Conditional</option>
                    </select>
                </div>

                <div class="col-md-2 col-lg-2">
                    <label class="form-label-sm" for="statusFilter">Status</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="col-md-12 col-lg-2 d-grid">
                    <button type="button" id="resetHeaderFilters" class="btn btn-outline-secondary">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card table-card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div>
                    <h5 class="section-title">Header List</h5>
                    <p class="section-subtitle">Search and manage all configured sheet headers from one place.</p>
                </div>

                <span class="soft-badge soft-badge-info">
                    Showing <span id="visibleHeaderCount">{{ $totalHeaders }}</span> of {{ $totalHeaders }}
                </span>
            </div>

            <div class="table-wrap">
                <table class="table table-sm align-middle" id="headerControlTable">
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Header</th>
                            <th>Owner Role</th>
                            <th>Type</th>
                            <th>Value Mode</th>
                            <th>Access &amp; Flags</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($headers as $header)
                            @php
                                $roleName = strtolower(optional($header->ownerRole)->name ?? '');
                                $valueMode = strtolower($header->value_mode ?? 'input');
                                $statusValue = $header->is_active ? 'active' : 'inactive';
                            @endphp
                            <tr
                                class="header-row"
                                data-search="{{ strtolower(trim(($header->header_name ?? '') . ' ' . ($header->header_key ?? '') . ' ' . (optional($header->ownerRole)->name ?? '') . ' ' . ($header->field_type ?? '') . ' ' . ($header->value_mode ?? 'input') . ' ' . ($header->formula_key ?? ''))) }}"
                                data-role="{{ $roleName }}"
                                data-mode="{{ $valueMode }}"
                                data-status="{{ $statusValue }}"
                            >
                                <td>
                                    <span class="soft-badge soft-badge-muted">#{{ $header->position }}</span>
                                </td>
                                <td>
                                    <div class="header-name">{{ $header->header_name }}</div>
                                    <span class="header-key">{{ $header->header_key }}</span>
                                </td>
                                <td>
                                    <span class="role-badge">
                                        {{ ucfirst(str_replace('_', ' ', optional($header->ownerRole)->name ?? 'N/A')) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="soft-badge soft-badge-info">{{ ucfirst($header->field_type) }}</span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        @if($valueMode === 'formula')
                                            <span class="soft-badge soft-badge-secondary">Formula</span>
                                        @elseif($valueMode === 'conditional')
                                            <span class="soft-badge soft-badge-warning">Conditional</span>
                                        @else
                                            <span class="soft-badge soft-badge-success">Input</span>
                                        @endif

                                        <small class="formula-key-note">
                                            {{ $header->formula_key ?: 'No formula key' }}
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <div class="access-grid">
                                        <span class="soft-badge {{ $header->is_required ? 'soft-badge-warning' : 'soft-badge-muted' }}">
                                            {{ $header->is_required ? 'Required' : 'Optional' }}
                                        </span>
                                        <span class="soft-badge {{ $header->is_active ? 'soft-badge-success' : 'soft-badge-danger' }}">
                                            {{ $header->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                        <span class="soft-badge {{ $header->can_view_all ? 'soft-badge-info' : 'soft-badge-muted' }}">
                                            {{ $header->can_view_all ? 'View All' : 'Restricted View' }}
                                        </span>
                                        <span class="soft-badge {{ $header->can_edit_owner_only ? 'soft-badge-secondary' : 'soft-badge-info' }}">
                                            {{ $header->can_edit_owner_only ? 'Owner Edit Only' : 'Shared Edit' }}
                                        </span>
                                        <span class="soft-badge {{ $header->merchant_can_upload ? 'soft-badge-success' : 'soft-badge-muted' }}">
                                            {{ $header->merchant_can_upload ? 'Merchant Upload' : 'No Merchant Upload' }}
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="actions-stack">
                                        <a href="{{ route('admin.headers.edit', $header->id) }}" class="btn btn-sm btn-outline-warning">
                                            Edit
                                        </a>

                                        <form action="{{ route('admin.headers.destroy', $header->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this header?')">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr id="headerEmptyStateRow">
                                <td colspan="7" class="empty-state">No excel headers found.</td>
                            </tr>
                        @endforelse

                        <tr id="headerNoMatchRow" style="display: none;">
                            <td colspan="7" class="empty-state">No matching headers found for the current search/filter.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const tableRows = Array.from(document.querySelectorAll('#headerControlTable .header-row'));
    const searchInput = document.getElementById('headerSearchInput');
    const roleFilter = document.getElementById('roleFilter');
    const modeFilter = document.getElementById('modeFilter');
    const statusFilter = document.getElementById('statusFilter');
    const resetButton = document.getElementById('resetHeaderFilters');
    const visibleCount = document.getElementById('visibleHeaderCount');
    const noMatchRow = document.getElementById('headerNoMatchRow');

    function applyHeaderFilters() {
        const searchValue = (searchInput?.value || '').toLowerCase().trim();
        const roleValue = (roleFilter?.value || '').toLowerCase().trim();
        const modeValue = (modeFilter?.value || '').toLowerCase().trim();
        const statusValue = (statusFilter?.value || '').toLowerCase().trim();

        let matched = 0;

        tableRows.forEach(function (row) {
            const searchText = row.dataset.search || '';
            const rowRole = row.dataset.role || '';
            const rowMode = row.dataset.mode || '';
            const rowStatus = row.dataset.status || '';

            const matchesSearch = !searchValue || searchText.includes(searchValue);
            const matchesRole = !roleValue || rowRole === roleValue;
            const matchesMode = !modeValue || rowMode === modeValue;
            const matchesStatus = !statusValue || rowStatus === statusValue;

            const shouldShow = matchesSearch && matchesRole && matchesMode && matchesStatus;
            row.style.display = shouldShow ? '' : 'none';

            if (shouldShow) {
                matched++;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = matched;
        }

        if (noMatchRow) {
            noMatchRow.style.display = matched === 0 && tableRows.length > 0 ? '' : 'none';
        }
    }

    [searchInput, roleFilter, modeFilter, statusFilter].forEach(function (element) {
        if (!element) return;
        element.addEventListener('input', applyHeaderFilters);
        element.addEventListener('change', applyHeaderFilters);
    });

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (searchInput) searchInput.value = '';
            if (roleFilter) roleFilter.value = '';
            if (modeFilter) modeFilter.value = '';
            if (statusFilter) statusFilter.value = '';
            applyHeaderFilters();
        });
    }

    applyHeaderFilters();
});
</script>
@endsection
