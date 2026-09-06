@extends('layouts.app')

@section('title', 'Booking Instructions')

@section('styles')
<style>
    /* Same table treatment as Buyers and Vendors, so the three admin lists
       read as one screen family rather than three. */
    .bi-table-wrap { max-height: 72vh; overflow: auto; }
    .bi-table { margin-bottom: 0; border-color: #e5eef7 !important; }
    .bi-table thead th {
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
    .bi-table tbody td {
        padding: 14px 12px;
        border-color: #edf2f8 !important;
        color: #334155;
        font-size: 13px;
        vertical-align: middle;
    }
    .bi-table tbody tr { background: #fff; transition: background .18s ease, box-shadow .18s ease; }
    .bi-table tbody tr:nth-child(even) { background: #fcfdff; }
    .bi-table tbody tr:hover { background: #f8fbff; box-shadow: inset 4px 0 0 #2563eb; }
    .bi-instruction-text { white-space: pre-line; line-height: 1.5; }
</style>
@endsection

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success gx-flash d-flex gap-2 align-items-start">
            <i class="bi bi-check-circle-fill gx-flash-icon" aria-hidden="true"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => auth()->user()?->hasRole('admin') ? route('admin.dashboard') : null],
        ['label' => 'Booking Setup'],
        ['label' => 'Booking Instructions'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-card-list" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Booking Setup</div>
                    <h3 class="app-hero-title mb-0">Booking Instructions</h3>
                    <p class="text-muted small mb-0 mt-1">
                        <strong>Default</strong> instructions print on every new booking. <strong>Suggestions</strong> stay in the tick list for Supply Chain to add when needed.
                    </p>
                </div>
            </div>
            <a href="{{ route('admin.booking-instructions.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Instruction
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach([
            ['label' => 'Total', 'key' => 'total', 'icon' => 'card-list'],
            ['label' => 'Default (Auto Added)', 'key' => 'default', 'icon' => 'pin-angle'],
            ['label' => 'Suggestions', 'key' => 'suggested', 'icon' => 'lightbulb'],
            ['label' => 'Active', 'key' => 'active', 'icon' => 'check2-circle'],
        ] as $tile)
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100" style="border-radius:var(--gx-radius);">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="app-stat-icon"><i class="bi bi-{{ $tile['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <div class="small text-muted mb-1">{{ $tile['label'] }}</div>
                            <div class="fs-4 fw-bold lh-1">{{ $stats[$tile['key']] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-0">
            <div class="bi-table-wrap">
                <table class="table table-hover align-middle bi-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th>Instruction</th>
                            <th style="width:160px;">Buyer</th>
                            <th style="width:130px;">Type</th>
                            <th style="width:80px;">Sort</th>
                            <th style="width:110px;">Status</th>
                            <th style="width:190px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($instructions as $instruction)
                            <tr>
                                <td class="text-muted">{{ $loop->iteration + ($instructions->currentPage() - 1) * $instructions->perPage() }}</td>
                                <td class="bi-instruction-text">{{ $instruction->instruction }}</td>
                                <td>
                                    @if($instruction->buyer)
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">{{ $instruction->buyer->buyer_name }}</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">All buyers</span>
                                    @endif
                                </td>
                                <td>
                                    @if($instruction->is_default)
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Default</span>
                                    @else
                                        <span class="badge bg-info-subtle text-info border border-info-subtle">Suggestion</span>
                                    @endif
                                </td>
                                <td>{{ $instruction->sort_order }}</td>
                                <td>
                                    @if($instruction->is_active)
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                    @else
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                                    @endif
                                </td>
                                <td>
                                    @if($instruction->is_default && ! $canManageDefaults)
                                        {{-- Still listed, because Supply Chain needs to know what already
                                             prints on a PO — but a default is an administrator's to change. --}}
                                        <span class="text-muted small">Admin only</span>
                                    @else
                                        <div class="d-flex gap-2">
                                            <a href="{{ route('admin.booking-instructions.edit', $instruction) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit</a>
                                            <form action="{{ route('admin.booking-instructions.destroy', $instruction) }}" method="POST" onsubmit="return confirm('Delete this instruction?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="bi bi-card-list d-block fs-3 mb-2 opacity-50" aria-hidden="true"></i>
                                    No booking instruction found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($instructions->hasPages())
            <div class="card-footer bg-white border-0 pt-0 px-3 pb-3">
                {{ $instructions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
