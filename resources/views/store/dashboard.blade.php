@extends('layouts.app')

@section('title', 'General Stock Dashboard')

@section('content')
@php
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

    // Card-level scoping. A user reaches this screen by holding any General
    // Stock permission, which does not mean they hold all of them — so each
    // panel asks for the one that governs the screen it summarises, and links
    // are only offered where they will open.
    $user = auth()->user();
    $seeStockReport = $user?->can('store.stock_report.view') ?? false;
    $seeReceiving = $user?->can('store.receiving.view') ?? false;
    $seeIssues = $user?->can('store.issues.view') ?? false;
    $seeItems = $user?->can('store.items.view') ?? false;
    $seeRequisition = $user?->can('store.requisition.view') ?? false;
    $seeMovement = $seeReceiving || $seeIssues;
@endphp
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="box-seam" eyebrow="General Stock"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Consumable stock position, movement and what needs attention.">
        <x-slot:actions>
            @if($seeReceiving)
                <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>Receiving
                </a>
            @endif
            @if($seeStockReport)
                <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary">Stock Report</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    <div class="row g-3 mb-4">
        @if($seeItems || $seeStockReport)
            <div class="col-12 col-sm-6 col-xl-3">
                <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                    icon="stack" tone="primary" label="Items tracked"
                    :value="$stats['stock_items']"
                    :href="$seeItems ? route('store.stock.items.index') : null" />
            </div>
        @endif

        @if($seeStockReport)
            <div class="col-12 col-sm-6 col-xl-3">
                <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                    icon="exclamation-triangle" tone="danger" label="Items needing purchase"
                    :value="$stats['reorder_count']"
                    :href="route('store.stock.ledger', ['status' => 'attention'])" />
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                    icon="x-octagon" tone="warning" label="Out of stock"
                    :value="$stats['out_of_stock']"
                    :href="route('store.stock.ledger', ['status' => 'out'])" />
            </div>
        @endif

        @if($seeRequisition)
            <div class="col-12 col-sm-6 col-xl-3">
                <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                    icon="hourglass-split" tone="primary" label="Pending purchase requisitions"
                    :value="$stats['pending_requisitions']"
                    :href="route('store.stock.requisitions.index')" />
            </div>
        @endif
    </div>

    <div class="row g-3 mb-4">
        @if($seeStockReport)
            <div class="col-12 col-xl-5">
                <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="Needs attention">
                    {{-- Worst first: out of stock, then below safety stock, then
                         below re-order level. Same ordering as the Stock Report. --}}
                    @php
                        $lowStock = collect($stockLevels)->take(6);
                        $tone = ['out' => 'danger', 'place_order' => 'danger', 'low' => 'warning'];
                        $statusLabels = \App\Services\GeneralStockReportService::statusLabels();
                    @endphp

                    @forelse($lowStock as $item)
                        <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">{{ $item['name'] }}</div>
                                <div class="small text-muted">
                                    <span class="badge bg-{{ $tone[$item['status']] }}-subtle text-{{ $tone[$item['status']] }}">{{ $statusLabels[$item['status']] }}</span>
                                    {{ $item['category'] }}
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-{{ $tone[$item['status']] }}">{{ $fmt($item['current']) }} {{ $item['uom'] }}</div>
                                <div class="small text-muted">safety {{ $fmt($item['threshold']) }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">Every consumable is above its re-order level.</p>
                    @endforelse

                    @if($stats['reorder_count'] > $lowStock->count())
                        <a href="{{ route('store.stock.ledger', ['status' => 'attention']) }}" class="btn btn-sm btn-outline-danger mt-3">
                            View all {{ $stats['reorder_count'] }} in the Place Order list
                        </a>
                    @endif
                </x-card>
            </div>
        @endif

        @if($seeMovement)
            <div class="col-12 col-xl-7">
                <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
                    <x-slot:title>Recent stock movement</x-slot:title>
                    @if($seeStockReport)
                        <x-slot:actions>
                            <a href="{{ route('store.stock.ledger') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                        </x-slot:actions>
                    @endif

                    @php
                        $movementItems = collect($recentActivity)->map(fn ($row) => [
                            'tone' => $row['direction'] === 'in' ? 'success' : 'warning',
                            'icon' => $row['direction'] === 'in' ? 'box-arrow-in-down' : 'box-arrow-up',
                            'title' => $row['label'],
                            'description' => ($row['direction'] === 'in' ? 'Received ' : 'Issued ')
                                .$fmt($row['qty']).' '.($row['uom'] ?: ''),
                            'meta' => optional($row['date'])->diffForHumans(),
                        ])->all();
                    @endphp

                    <x-timeline :items="$movementItems" />
                </x-card>
            </div>
        @endif
    </div>

    <div class="row g-3" data-aos="fade-up">
        @if($seeReceiving)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:600ms" icon="box-arrow-in-down" tone="success"
                    title="Receiving" description="Record consumables in"
                    :href="route('store.stock.purchases.index')" />
            </div>
        @endif
        @if($seeIssues)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:650ms" icon="box-arrow-up" tone="warning"
                    title="Issues" description="Issue to departments"
                    :href="route('store.stock.issues.index')" />
            </div>
        @endif
        @if($seeRequisition)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:700ms" icon="clipboard-check" tone="primary"
                    title="Purchase Requisition" description="Raise and track"
                    :href="route('store.stock.requisitions.index')" />
            </div>
        @endif
        @if($seeStockReport)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:750ms" icon="journal-text" tone="primary"
                    title="Stock Report" description="Closing position by item"
                    :href="route('store.stock.ledger')" />
            </div>
        @endif
    </div>
</div>
@endsection
