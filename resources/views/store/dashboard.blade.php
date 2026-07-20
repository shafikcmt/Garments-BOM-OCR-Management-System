@extends('layouts.app')

@section('title', 'Store Dashboard')

@section('content')
@php
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp
<div class="container-fluid">
    <x-page-header icon="box-seam" eyebrow="Store"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Stock position, movement and what needs attention.">
        <x-slot:actions>
            <a href="{{ route('store.material.receivings.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-in-down"></i>Receiving
            </a>
            <a href="{{ route('store.reports.index') }}" class="btn btn-outline-secondary">Reports</a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="stack" tone="primary" label="Material lines tracked"
                :value="$stats['material_lines']"
                :spark="collect($trend)->pluck('value')->all()"
                :href="route('store.material.ledger')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="check2-circle" tone="success" label="Running closing qty"
                :value="$fmt($stats['running_qty'])" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="hourglass-split" tone="warning" label="Pending requisitions"
                :value="$stats['pending_requisitions']"
                :href="route('store.material.requisitions.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="exclamation-triangle" tone="danger" label="Items at re-order level"
                :value="$stats['reorder_count']"
                :href="route('store.stock.items.index')" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-5">
            {{-- Running / Liability / Dead is the split Store actually manages:
                 liability and dead can still be transferred back to bulk. --}}
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="Closing stock split">
                <x-donut-chart caption="Total qty"
                    :total="$fmt($stats['running_qty'] + $stats['liability_qty'] + $stats['dead_qty'])"
                    :segments="[
                        ['label' => 'Running', 'value' => $stats['running_qty'], 'tone' => 'success'],
                        ['label' => 'Liability', 'value' => $stats['liability_qty'], 'tone' => 'warning'],
                        ['label' => 'Dead', 'value' => $stats['dead_qty'], 'tone' => 'danger'],
                    ]" />
                <a href="{{ route('store.material.ledger') }}" class="btn btn-sm btn-outline-primary mt-3">Closing stock report</a>
            </x-card>
        </div>

        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
                <x-slot:title>
                    Receivings — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>
                <x-area-chart :series="$trend" tone="success" label="Material receivings per month" />
            </x-card>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:600ms">
                <x-slot:title>Recent stock movement</x-slot:title>
                <x-slot:actions>
                    <a href="{{ route('store.material.ledger') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                </x-slot:actions>

                @php
                    $movementItems = collect($recentActivity)->map(fn ($row) => [
                        'tone' => $row['direction'] === 'in' ? 'success' : 'warning',
                        'icon' => $row['direction'] === 'in' ? 'box-arrow-in-down' : 'box-arrow-up',
                        'title' => $row['label'],
                        'description' => ($row['direction'] === 'in' ? 'Received ' : 'Issued ')
                            .$fmt($row['qty']).' '.($row['uom'] ?: ''),
                        'meta' => $row['module'].' · '.optional($row['date'])->diffForHumans(),
                    ])->all();
                @endphp

                <x-timeline :items="$movementItems" />
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:700ms" title="Needs attention">
                @php $lowStock = collect($stockLevels)->where('low', true)->take(6); @endphp

                @forelse($lowStock as $item)
                    <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                        <div class="min-w-0">
                            <div class="fw-semibold text-truncate">{{ $item['name'] }}</div>
                            <div class="small text-muted">{{ $item['code'] }}</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-danger">{{ $fmt($item['current']) }} {{ $item['uom'] }}</div>
                            <div class="small text-muted">re-order at {{ $fmt($item['threshold']) }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">Nothing is at its re-order level.</p>
                @endforelse

                @if($stats['pending_req_lines'] > 0)
                    <div class="alert alert-warning mt-3 mb-0 py-2 small">
                        {{ $stats['pending_req_lines'] }} requisition line(s) not fully issued
                        ({{ $fmt($stats['pending_req_qty']) }} qty outstanding).
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:800ms" icon="box-arrow-in-down" tone="success"
                title="Receiving" description="Record material in"
                :href="route('store.material.receivings.index')" />
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:850ms" icon="box-arrow-up" tone="warning"
                title="Bulk Issue" description="Issue to production"
                :href="route('store.material.bulk-issues.index')" />
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:900ms" icon="clipboard-data" tone="primary"
                title="Closing Stock" description="Running / liability / dead"
                :href="route('store.material.ledger')" />
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:950ms" icon="file-earmark-bar-graph" tone="primary"
                title="Reports" description="Export PDF or Excel"
                :href="route('store.reports.index')" />
        </div>
    </div>
</div>
@endsection
