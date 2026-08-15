@extends('layouts.app')

@section('title', 'Buyer Control')

@section('styles')
<style>
    .buyer-page {
        --buyer-ink: #0f172a;
        --buyer-muted: #64748b;
        --buyer-border: #e2e8f0;
        --buyer-blue: #2563eb;
    }
    .buyer-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(191, 219, 254, .9);
        border-radius: 24px;
        background: linear-gradient(135deg, #ffffff 0%, #f8fbff 55%, #eef5ff 100%);
        box-shadow: 0 22px 55px rgba(15, 23, 42, .07);
        padding: 24px;
    }
    .buyer-hero::after {
        content: '';
        position: absolute;
        top: -72px;
        right: -66px;
        width: 230px;
        height: 230px;
        border-radius: 999px;
        background: rgba(37, 99, 235, .10);
    }
    .buyer-hero > * { position: relative; z-index: 1; }
    .buyer-hero-icon {
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
    .buyer-eyebrow { color: var(--buyer-blue); font-size: 11px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
    .buyer-title { margin: 4px 0; color: var(--buyer-ink); font-size: clamp(1.45rem, 2vw, 1.9rem); font-weight: 850; letter-spacing: -.04em; }
    .buyer-copy { color: var(--buyer-muted); margin: 0; font-size: 14px; }
    .buyer-stat-card {
        height: 100%;
        border: 1px solid var(--buyer-border);
        border-radius: 18px;
        background: rgba(255,255,255,.94);
        box-shadow: 0 14px 34px rgba(15,23,42,.055);
        padding: 16px;
    }
    .buyer-stat-label { color: var(--buyer-muted); font-size: 12px; font-weight: 800; }
    .buyer-stat-value { color: var(--buyer-ink); font-size: 1.55rem; line-height: 1; font-weight: 900; letter-spacing: -.04em; }
    .buyer-control-card {
        border: 1px solid var(--buyer-border);
        border-radius: 22px;
        background: rgba(255,255,255,.94);
        box-shadow: 0 18px 45px rgba(15,23,42,.06);
        overflow: hidden;
    }
    .buyer-toolbar {
        padding: 16px;
        border-bottom: 1px solid var(--buyer-border);
        background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
    }
    .buyer-search { position: relative; }
    .buyer-search i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
    }
    .buyer-search .form-control { padding-left: 42px; min-height: 44px; }
    .buyer-table-wrap { max-height: 72vh; overflow: auto; }
    .buyer-table {
        margin-bottom: 0;
        min-width: 1080px;
        border-color: #e5eef7 !important;
    }
    .buyer-table thead th {
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
    .buyer-table tbody td {
        padding: 14px 12px;
        border-color: #edf2f8 !important;
        color: #334155;
        font-size: 13px;
        vertical-align: middle;
    }
    .buyer-table tbody tr {
        background: #fff;
        transition: background .18s ease, box-shadow .18s ease;
    }
    .buyer-table tbody tr:nth-child(even) { background: #fcfdff; }
    .buyer-table tbody tr:hover {
        background: #f8fbff;
        box-shadow: inset 4px 0 0 #2563eb;
    }
    .buyer-sl-badge,
    .buyer-status-badge,
    .buyer-code-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 850;
        line-height: 1;
        border: 1px solid transparent;
    }
    .buyer-sl-badge { min-width: 34px; height: 26px; color: #1d4ed8; background: #eff6ff; border-color: #dbeafe; }
    .buyer-code-badge { padding: 5px 9px; color: #475569; background: #f8fafc; border-color: #e2e8f0; }
    .buyer-status-badge { padding: 6px 9px; }
    .buyer-status-active { color: #047857; background: #ecfdf5; border-color: #bbf7d0; }
    .buyer-status-inactive { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }
    .buyer-name { color: var(--buyer-ink); font-size: 13px; font-weight: 850; letter-spacing: -.015em; }
    .buyer-sub { color: var(--buyer-muted); font-size: 12px; line-height: 1.35; }
    .buyer-action-btn {
        height: auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 6px 12px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 10px !important;
        border: 1px solid transparent;
        box-shadow: none !important;
    }
    .buyer-action-edit { background: #fff7ed !important; color: #c2410c !important; border-color: #fed7aa !important; }
    .buyer-action-edit:hover { background: #ffedd5 !important; color: #9a3412 !important; }
    .buyer-action-delete { background: #fef2f2 !important; color: #dc2626 !important; border-color: #fecaca !important; }
    .buyer-action-delete:hover { background: #fee2e2 !important; color: #991b1b !important; }
    .buyer-empty {
        min-height: 240px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: var(--buyer-muted);
    }
</style>
@endsection

@section('content')
@php
    $buyerCollection = $buyers->getCollection();
    $pageTotal = $buyerCollection->count();
    $activeTotal = $buyerCollection->where('is_active', true)->count();
    $inactiveTotal = max(0, $pageTotal - $activeTotal);
@endphp

<div class="container-fluid buyer-page">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Buyers'],
    ]" />

    <div class="buyer-hero mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="buyer-hero-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
                <div>
                    <div class="buyer-eyebrow">Master Data</div>
                    <h3 class="buyer-title">Buyer Control</h3>
                    <p class="buyer-copy">Manage the fixed buyer list used when a merchant uploads a BOM file and when the workspace list is filtered.</p>
                </div>
            </div>
            <a href="{{ route('admin.buyers.create') }}" class="btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-circle" aria-hidden="true"></i> Add Buyer
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="buyer-stat-card">
                <div class="buyer-stat-label">Showing Buyers</div>
                <div class="buyer-stat-value">{{ $pageTotal }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="buyer-stat-card">
                <div class="buyer-stat-label">Active</div>
                <div class="buyer-stat-value">{{ $activeTotal }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="buyer-stat-card">
                <div class="buyer-stat-label">Inactive</div>
                <div class="buyer-stat-value">{{ $inactiveTotal }}</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="buyer-stat-card">
                <div class="buyer-stat-label">Total Records</div>
                <div class="buyer-stat-value">{{ method_exists($buyers, 'total') ? $buyers->total() : $pageTotal }}</div>
            </div>
        </div>
    </div>

    <div class="buyer-control-card">
        <div class="buyer-toolbar">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <h5 class="mb-1 fw-bold text-slate-900">Buyer List</h5>
                    <div class="small text-muted">Search by buyer name, code, contact person, email or phone.</div>
                </div>
                <div class="buyer-search" style="min-width:min(100%, 420px);">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <label class="visually-hidden" for="buyerTableSearch">Search buyers</label>
                    <input type="text" class="form-control" id="buyerTableSearch" placeholder="Search buyers...">
                </div>
            </div>
        </div>

        <div class="buyer-table-wrap">
            <table class="table table-hover align-middle buyer-table" id="buyerControlTable">
                <thead>
                    <tr>
                        <th style="width:70px;">SL</th>
                        <th style="width:260px;">Buyer</th>
                        <th style="width:200px;">Department Admin</th>
                        <th style="width:200px;">Contact</th>
                        <th style="width:240px;">Email</th>
                        <th style="width:170px;">Phone</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:170px;" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($buyers as $buyer)
                        @php
                            $searchText = strtolower(implode(' ', array_filter([
                                $buyer->buyer_name,
                                $buyer->buyer_code,
                                $buyer->departmentAdmin?->name,
                                $buyer->contact_person,
                                $buyer->email,
                                $buyer->phone,
                                $buyer->is_active ? 'active' : 'inactive',
                            ])));
                        @endphp
                        <tr class="buyer-row" data-search="{{ $searchText }}">
                            <td><span class="buyer-sl-badge">{{ $loop->iteration + ($buyers->currentPage() - 1) * $buyers->perPage() }}</span></td>
                            <td>
                                <div class="buyer-name">{{ $buyer->buyer_name }}</div>
                                @if($buyer->buyer_code)
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <span class="buyer-code-badge">{{ $buyer->buyer_code }}</span>
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($buyer->departmentAdmin)
                                    <div class="fw-semibold text-slate-900">{{ $buyer->departmentAdmin->name }}</div>
                                    <div class="buyer-sub">{{ $buyer->departmentAdmin->email }}</div>
                                @else
                                    <span class="text-muted">Not assigned</span>
                                @endif
                            </td>
                            <td>
                                <div class="fw-semibold text-slate-900">{{ $buyer->contact_person ?: '-' }}</div>
                            </td>
                            <td>
                                @if($buyer->email)
                                    <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-envelope text-primary" aria-hidden="true"></i>{{ $buyer->email }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if($buyer->phone)
                                    <span class="d-inline-flex align-items-center gap-1"><i class="bi bi-telephone" aria-hidden="true"></i>{{ $buyer->phone }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if($buyer->is_active)
                                    <span class="buyer-status-badge buyer-status-active"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Active</span>
                                @else
                                    <span class="buyer-status-badge buyer-status-inactive"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex justify-content-center gap-2">
                                    <a href="{{ route('admin.buyers.edit', $buyer) }}" class="btn buyer-action-btn buyer-action-edit">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i><span class="ms-1">Edit</span>
                                    </a>

                                    <form action="{{ route('admin.buyers.destroy', $buyer) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this buyer? Files already tagged with this buyer will keep working.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn buyer-action-btn buyer-action-delete">
                                            <i class="bi bi-trash3" aria-hidden="true"></i><span class="ms-1">Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="buyer-empty">
                                    <div>
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:76px;height:76px;">
                                            <i class="bi bi-inbox fs-1 text-slate-400" aria-hidden="true"></i>
                                        </span>
                                        <div class="fw-bold text-slate-900">No buyer found</div>
                                        <div class="small text-muted">Add a buyer so merchants can tag their BOM uploads.</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    <tr id="buyerNoMatchRow" style="display:none;">
                        <td colspan="8">
                            <div class="buyer-empty">
                                <div>
                                    <span class="d-inline-flex align-items-center justify-content-center rounded-5 bg-light border mb-3" style="width:76px;height:76px;">
                                        <i class="bi bi-search fs-1 text-slate-400" aria-hidden="true"></i>
                                    </span>
                                    <div class="fw-bold text-slate-900">No matching buyer found</div>
                                    <div class="small text-muted">Try another keyword.</div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="p-3 border-top bg-white">
            {{ $buyers->links() }}
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('buyerTableSearch');
    const rows = Array.from(document.querySelectorAll('#buyerControlTable .buyer-row'));
    const noMatchRow = document.getElementById('buyerNoMatchRow');

    function filterBuyers() {
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

    searchInput?.addEventListener('input', filterBuyers);
});
</script>
@endsection
