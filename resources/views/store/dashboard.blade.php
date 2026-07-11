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
    </div>
</div>
@endsection
