@extends('layouts.app')

@section('title', 'Vendor Control')

@section('styles')
<style>
    .supplier-page {
        --vendor-ink: #0f172a;
        --vendor-muted: #64748b;
        --vendor-border: #e2e8f0;
        --vendor-soft: #f8fafc;
        --vendor-blue: #2563eb;
    }
    .supplier-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(191, 219, 254, .9);
        border-radius: 24px;
        background: linear-gradient(135deg, #ffffff 0%, #f8fbff 55%, #eef5ff 100%);
        box-shadow: 0 22px 55px rgba(15, 23, 42, .07);
        padding: 24px;
    }
    .supplier-hero::after {
        content: '';
        position: absolute;
        top: -72px;
        right: -66px;
        width: 230px;
        height: 230px;
        border-radius: 999px;
        background: rgba(37, 99, 235, .10);
    }
    .supplier-hero > * { position: relative; z-index: 1; }
    .supplier-hero-icon {
        width: 54px;
        height: 54px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        box-shadow: 0 14px 28px rgba(37, 99, 235, .22);
        font-size: 24px;
    }
    .supplier-eyebrow { color: var(--vendor-blue); font-size: 11px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
    .supplier-title { margin: 4px 0; color: var(--vendor-ink); font-size: clamp(1.45rem, 2vw, 1.9rem); font-weight: 850; letter-spacing: -.04em; }
    .supplier-copy { color: var(--vendor-muted); margin: 0; font-size: 14px; }
    .supplier-stat-card {
        height: 100%;
        border: 1px solid var(--vendor-border);
        border-radius: 18px;
        background: rgba(255,255,255,.94);
        box-shadow: 0 14px 34px rgba(15,23,42,.055);
        padding: 16px;
    }
    .supplier-stat-label { color: var(--vendor-muted); font-size: 12px; font-weight: 800; }
    .supplier-stat-value { color: var(--vendor-ink); font-size: 1.55rem; line-height: 1; font-weight: 900; letter-spacing: -.04em; }
    .supplier-control-card {
        border: 1px solid var(--vendor-border);
        border-radius: 22px;
        background: rgba(255,255,255,.94);
        box-shadow: 0 18px 45px rgba(15,23,42,.06);
        overflow: hidden;
    }
    .supplier-toolbar {
        padding: 16px;
        border-bottom: 1px solid var(--vendor-border);
        background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
    }
    .supplier-search { position: relative; }
    .supplier-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
    }
    .supplier-search .form-control { padding-left: 42px; min-height: 44px; }
    .supplier-table-wrap { max-height: 72vh; overflow: auto; }
    .supplier-table {
        margin-bottom: 0;
        min-width: 1120px;
        border-color: #e5eef7 !important;
    }
    .supplier-table thead th {
        position: sticky;
        top: 0;
        z-index: 4;
        background: linear-gradient(180deg, #eff6ff 0%, #eaf2ff 100%) !important;
        color: #1e3a8a;
        border-color: #d7e5f7 !important;
        font-size: 11px;
        font-weight: 900;
        letter-spacing: .06em;
        text-transform: uppercase;
        padding: 14px 12px;
        white-space: nowrap;
    }
    .supplier-table tbody td {
        padding: 14px 12px;
        border-color: #edf2f8 !important;
        color: #334155;
        font-size: 13px;
        vertical-align: middle;
    }
    .supplier-table tbody tr {
        background: #fff;
        transition: background .18s ease, box-shadow .18s ease, transform .18s ease;
    }
    .supplier-table tbody tr:nth-child(even) { background: #fcfdff; }
    .supplier-table tbody tr:hover {
        background: #f8fbff;
        box-shadow: inset 4px 0 0 #2563eb;
    }
    .supplier-sl-badge,
    .supplier-status-badge,
    .supplier-code-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 850;
        line-height: 1;
        border: 1px solid transparent;
    }
    .supplier-sl-badge { min-width: 34px; height: 26px; color: #1d4ed8; background: #eff6ff; border-color: #dbeafe; }
    .supplier-code-badge { padding: 5px 9px; color: #475569; background: #f8fafc; border-color: #e2e8f0; }
    .supplier-status-badge { padding: 6px 9px; }
    .supplier-status-active { color: #047857; background: #ecfdf5; border-color: #bbf7d0; }
    .supplier-status-inactive { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
    .supplier-name { color: var(--vendor-ink); font-size: 13px; font-weight: 850; letter-spacing: -.015em; }
    .supplier-sub { color: var(--vendor-muted); font-size: 12px; line-height: 1.35; }
    .supplier-action-btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px !important;
        border: 1px solid transparent;
        box-shadow: none !important;
    }
    .supplier-action-edit { background: #fff7ed !important; color: #c2410c !important; border-color: #fed7aa !important; }
    .supplier-action-edit:hover { background: #ffedd5 !important; color: #9a3412 !important; }
    .supplier-action-delete { background: #fef2f2 !important; color: #dc2626 !important; border-color: #fecaca !important; }
    .supplier-action-delete:hover { background: #fee2e2 !important; color: #991b1b !important; }
    .supplier-empty {
        min-height: 240px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: var(--vendor-muted);
    }
</style>
@endsection

@section('content')
@php
    $supplierCollection = $suppliers->getCollection();
    $pageTotal = $supplierCollection->count();
    $activeTotal = $supplierCollection->where('is_active', true)->count();
    $inactiveTotal = max(0, $pageTotal - $activeTotal);
@endphp

<div class="container-fluid supplier-page">
    <div class="supplier-hero mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="supplier-hero-icon"><i class="bi bi-buildings"></i></span>
                <div>
                    <div class="supplier-eyebrow">Booking Setup</div>
                    <h3 class="supplier-title">Vendor / Supplier Control</h3>
                    <p class="supplier-copy">Manage supplier master data, booking defaults, incoterm and shipping information from one clean table.</p>
                </div>
            </div>
            <a href="{{ route('admin.suppliers.create') }}" class="btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-circle"></i> Add Vendor
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="supplier-stat-card">
                <div class="supplier-stat-label">Showing Vendors</div>
                <div class="supplier-stat-value">{{ $pageTotal }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="supplier-stat-card">
                <div class="supplier-stat-label">Active</div>
                <div class="supplier-stat-value">{{ $activeTotal }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="supplier-stat-card">
                <div class="supplier-stat-label">Inactive</div>
                <div class="supplier-stat-value">{{ $inactiveTotal }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="supplier-stat-card">
                <div class="supplier-stat-label">Total Records</div>
                <div class="supplier-stat-value">{{ method_exists($suppliers, 'total') ? $suppliers->total() : $pageTotal }}</div>
            </div>
        </div>
    </div>

    <div class="supplier-control-card">
        <div class="supplier-toolbar">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <h5 class="mb-1 fw-bold text-slate-900">Vendor List</h5>
                    <div class="small text-muted">Search by supplier name, code, contact, email, address, item type, incoterm or ship mode.</div>
                </div>
                <div class="supplier-search" style="min-width:min(100%, 420px);">
                    <i class="bi bi-search"></i>
                    <input type="text" class="form-control" id="supplierTableSearch" placeholder="Search vendors...">
                </div>
            </div>
        </div>

        <div class="supplier-table-wrap">
            <table class="table table-hover align-middle supplier-table" id="supplierControlTable">
                <thead>
                    <tr>
                        <th style="width:70px;">SL</th>
                        <th style="width:210px;">Supplier</th>
                        <th style="width:160px;">Contact</th>
                        <th style="width:210px;">Email</th>
                        <th style="width:320px;">Address</th>
                        <th style="width:125px;">Item Type</th>
                        <th style="width:130px;">Incoterm</th>
                        <th style="width:130px;">Ship Mode</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:120px;" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppliers as $supplier)
                        @php
                            $searchText = strtolower(implode(' ', array_filter([
                                $supplier->supplier_name,
                                $supplier->supplier_code,
                                $supplier->legal_name,
                                $supplier->contact_person,
                                $supplier->phone,
                                $supplier->email,
                                $supplier->full_address,
                                $supplier->item_type,
                                $supplier->incoterm,
                                $supplier->ship_mode,
                                $supplier->is_active ? 'active' : 'inactive',
                            ])));
                        @endphp
                        <tr class="supplier-row" data-search="{{ $searchText }}">
                            <td><span class="supplier-sl-badge">{{ $loop->iteration + ($suppliers->currentPage() - 1) * $suppliers->perPage() }}</span></td>
                            <td>
                                <div class="supplier-name">{{ $supplier->supplier_name }}</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @if($supplier->supplier_code)
                                        <span class="supplier-code-badge">{{ $supplier->supplier_code }}</span>
                                    @endif
                                </div>
                                @if($supplier->legal_name)
                                    <div class="supplier-sub mt-1">{{ $supplier->legal_name }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="fw-semibold text-slate-900">{{ $supplier->contact_person ?? '-' }}</div>
                                @if($supplier->phone)
                                    <div class="supplier-sub"><i class="bi bi-telephone me-1"></i>{{ $supplier->phone }}</div>
                                @endif
                            </td>
                            <td>
                                @if($supplier->email)
                                    <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-envelope text-primary"></i>{{ $supplier->email }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td><div class="supplier-sub text-slate-900">{{ $supplier->full_address ?: '-' }}</div></td>
                            <td><span class="supplier-code-badge">{{ $supplier->item_type ?? '-' }}</span></td>
                            <td>{{ $supplier->incoterm ?? '-' }}</td>
                            <td>{{ $supplier->ship_mode ?? '-' }}</td>
                            <td>
                                @if($supplier->is_active)
                                    <span class="supplier-status-badge supplier-status-active"><i class="bi bi-check-circle me-1"></i>Active</span>
                                @else
                                    <span class="supplier-status-badge supplier-status-inactive"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex justify-content-center gap-2">
                                    <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="btn supplier-action-btn supplier-action-edit" title="Edit vendor">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>

                                    <form action="{{ route('admin.suppliers.destroy', $supplier) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this vendor?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn supplier-action-btn supplier-action-delete" title="Delete vendor">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10">
                                <div class="supplier-empty">
                                    <div>
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:76px;height:76px;">
                                            <i class="bi bi-inbox fs-1 text-slate-400"></i>
                                        </span>
                                        <div class="fw-bold text-slate-900">No vendor found</div>
                                        <div class="small text-muted">Create a vendor to start booking setup.</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    <tr id="supplierNoMatchRow" style="display:none;">
                        <td colspan="10">
                            <div class="supplier-empty">
                                <div>
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:76px;height:76px;">
                                        <i class="bi bi-search fs-1 text-slate-400"></i>
                                    </span>
                                    <div class="fw-bold text-slate-900">No matching vendor found</div>
                                    <div class="small text-muted">Try another keyword.</div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-top bg-white">
            {{ $suppliers->links() }}
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('supplierTableSearch');
    const rows = Array.from(document.querySelectorAll('#supplierControlTable .supplier-row'));
    const noMatchRow = document.getElementById('supplierNoMatchRow');

    function filterVendors() {
        const keyword = (searchInput?.value || '').toLowerCase().trim();
        let visible = 0;

        rows.forEach(function (row) {
            const match = !keyword || (row.dataset.search || '').includes(keyword);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        if (noMatchRow) {
            noMatchRow.style.display = keyword && visible === 0 ? '' : 'none';
        }
    }

    searchInput?.addEventListener('input', filterVendors);
});
</script>
@endsection
