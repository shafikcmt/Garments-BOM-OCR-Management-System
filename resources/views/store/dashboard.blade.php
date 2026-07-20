@extends('layouts.app')

@section('title', 'Store Dashboard')

@php
    $fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 4), '0'), '.');
@endphp

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 p-lg-5 mb-4">
        <div class="app-hero-layout d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="app-hero-main d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:52px;height:52px;border-radius:18px;font-size:22px;"><i class="bi bi-shop"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Store</div>
                    <h2 class="app-hero-title">Welcome, {{ auth()->user()->name }}</h2>
                    <p class="app-hero-copy mb-0">General stock and buyer/style material stock in one place.</p>
                </div>
            </div>
            <a href="{{ route('store.workspace') }}" class="app-hero-action btn btn-primary px-4 d-inline-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-up-right"></i>Open Workspace
            </a>
        </div>
    </div>

    {{-- Quick stats --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="app-stat-label">Stock Items</div>
                <div class="fw-bold fs-4 text-slate-900">{{ $stats['stock_items'] }}</div>
                @if($stats['reorder_count'] > 0)<div class="small text-danger"><i class="bi bi-cart-plus me-1"></i>{{ $stats['reorder_count'] }} to re-order</div>@endif
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="app-stat-label">Material Lines</div>
                <div class="fw-bold fs-4 text-slate-900">{{ $stats['material_lines'] }}</div>
                <div class="small text-muted">Running: {{ $fmt($stats['running_qty']) }}</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="app-stat-label">Liability / Dead</div>
                <div class="fw-bold fs-4"><span class="text-warning">{{ $fmt($stats['liability_qty']) }}</span> / <span class="text-danger">{{ $fmt($stats['dead_qty']) }}</span></div>
                <div class="small text-muted">Reusable stock</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="app-stat-label">Pending Requisitions</div>
                <div class="fw-bold fs-4 text-slate-900">{{ $stats['pending_requisitions'] }}</div>
                <div class="small text-muted">Awaiting approval</div>
            </div>
        </div>
    </div>

    {{-- Requisition flow — tracking only (does not move stock) --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="app-stat-label">Pending Issue</div>
                <div class="fw-bold fs-4 text-warning">{{ $fmt($stats['pending_req_qty']) }}</div>
                <div class="small text-muted">{{ $stats['pending_req_lines'] }} line(s) required &gt; issued</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="app-stat-card p-3 h-100">
                <div class="app-stat-label">Pending Receive</div>
                <div class="fw-bold fs-4 text-info">{{ $fmt($stats['pending_recv_qty']) }}</div>
                <div class="small text-muted">{{ $stats['pending_recv_lines'] }} line(s) issued &gt; received</div>
            </div>
        </div>
    </div>

    {{-- Live stock levels + recent movement (read-only) --}}
    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="app-stat-icon"><i class="bi bi-boxes"></i></span>
                            <h5 class="mb-0">Current Stock by Item</h5>
                        </div>
                        <a href="{{ route('store.stock.items.index') }}" class="btn btn-sm btn-outline-secondary">Items</a>
                    </div>
                    @if($stockLevels->isEmpty())
                        <p class="text-muted small mb-0">No stock items yet. Add items under General Stock → Items.</p>
                    @else
                    <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="text-muted small text-uppercase">
                                <tr><th>Item</th><th class="text-end">Current</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach($stockLevels->take(12) as $s)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold small">{{ $s['name'] }}</div>
                                            @if($s['code'])<div class="text-muted" style="font-size:.72rem;">{{ $s['code'] }}</div>@endif
                                        </td>
                                        <td class="text-end fw-bold {{ $s['low'] ? 'text-danger' : 'text-slate-900' }}">
                                            {{ $fmt($s['current']) }}<span class="text-muted fw-normal small"> {{ $s['uom'] }}</span>
                                        </td>
                                        <td class="text-end" style="width:96px;">
                                            @if($s['low'])<span class="badge bg-danger-subtle text-danger"><i class="bi bi-cart-plus me-1"></i>Re-order</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($stockLevels->count() > 12)<div class="small text-muted mt-2">Showing 12 of {{ $stockLevels->count() }} items (lowest stock first).</div>@endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="app-stat-icon"><i class="bi bi-clock-history"></i></span>
                        <h5 class="mb-0">Recent Issue / Receive Activity</h5>
                    </div>
                    @if($recentActivity->isEmpty())
                        <p class="text-muted small mb-0">No stock movement recorded yet.</p>
                    @else
                    <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                @foreach($recentActivity as $a)
                                    @php $in = $a['direction'] === 'in'; @endphp
                                    <tr>
                                        <td style="width:74px;">
                                            <span class="badge bg-{{ $in ? 'success' : 'warning' }}-subtle text-{{ $in ? 'success' : 'warning' }}">
                                                <i class="bi bi-arrow-{{ $in ? 'down' : 'up' }}-circle me-1"></i>{{ $in ? 'In' : 'Out' }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold small text-truncate" style="max-width:260px;">{{ $a['label'] }}</div>
                                            <div class="text-muted" style="font-size:.72rem;">{{ $a['module'] }} · {{ optional($a['date'])->format('d-M-Y') }}</div>
                                        </td>
                                        <td class="text-end fw-bold {{ $in ? 'text-success' : 'text-warning' }}">
                                            {{ ($in ? '+' : '−') . $fmt($a['qty']) }}<span class="text-muted fw-normal small"> {{ $a['uom'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- General Stock module --}}
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="app-stat-icon"><i class="bi bi-box-seam"></i></span>
                        <h5 class="mb-0">General Stock</h5>
                    </div>
                    <p class="text-muted small">Consumables and non-BOM items. Purchase → Consumption → Monthly closing with re-order alerts.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('store.stock.ledger') }}" class="btn btn-primary btn-sm"><i class="bi bi-journal-text me-1"></i>Monthly Ledger</a>
                        <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary btn-sm">Items</a>
                        <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary btn-sm">Purchases</a>
                        <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary btn-sm">Issues</a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Buyer/Style Stock module --}}
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="app-stat-icon"><i class="bi bi-clipboard-data"></i></span>
                        <h5 class="mb-0">Buyer / Style Stock</h5>
                    </div>
                    <p class="text-muted small">BOM/PO-linked fabric &amp; trims. Receiving → Bulk Issue (bulk/sample/liability/dead) → Closing Stock with reuse.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('store.material.ledger') }}" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-data me-1"></i>Closing Stock</a>
                        <a href="{{ route('store.material.receivings.index') }}" class="btn btn-outline-secondary btn-sm">Receiving</a>
                        <a href="{{ route('store.material.bulk-issues.index') }}" class="btn btn-outline-secondary btn-sm">Bulk Issue</a>
                        <a href="{{ route('store.material.requisitions.index') }}" class="btn btn-outline-secondary btn-sm">Requisitions</a>
                    </div>
                </div>
            </div>
        </div>

        {{-- Reports — read-only summaries built from the existing movement data --}}
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="app-stat-icon"><i class="bi bi-file-earmark-bar-graph"></i></span>
                        <h5 class="mb-0">Reports</h5>
                    </div>
                    <p class="text-muted small">Receive and issue summary from three angles, with period movement and current ledger balance side by side. Filter by buyer, style, material or date range, then export to PDF or Excel.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('store.reports.index', ['type' => 'style']) }}" class="btn btn-primary btn-sm"><i class="bi bi-tags me-1"></i>Style-wise</a>
                        <a href="{{ route('store.reports.index', ['type' => 'buyer']) }}" class="btn btn-outline-secondary btn-sm">Buyer-wise</a>
                        <a href="{{ route('store.reports.index', ['type' => 'material']) }}" class="btn btn-outline-secondary btn-sm">Material-wise</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
