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
                   title="General Stock"
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

    {{-- Straight after the counts: the four things a stock user comes here to
         do. They used to sit under the attention list and the movement feed,
         which put the day's work below a page of reading. --}}
    <div class="row g-3 mb-4" data-aos="fade-up">
        @if($seeReceiving)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:400ms" icon="box-arrow-in-down" tone="success"
                    title="Receiving" description="Record consumables in"
                    :href="route('store.stock.purchases.index')" />
            </div>
        @endif
        @if($seeIssues)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:450ms" icon="box-arrow-up" tone="warning"
                    title="Issues" description="Issue to departments"
                    :href="route('store.stock.issues.index')" />
            </div>
        @endif
        @if($seeRequisition)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:500ms" icon="clipboard-check" tone="primary"
                    title="Purchase Requisition" description="Raise and track"
                    :href="route('store.stock.requisitions.index')" />
            </div>
        @endif
        @if($seeStockReport)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:550ms" icon="journal-text" tone="primary"
                    title="Stock Report" description="Closing position by item"
                    :href="route('store.stock.ledger')" />
            </div>
        @endif
    </div>

    <div class="row g-3 mb-4">
        @if($seeStockReport)
            <div class="col-12 col-xl-4">
                <x-card class="gx-fade-in h-100" style="--gx-delay:600ms" title="Needs attention">
                    {{-- Worst first: out of stock, then below safety stock, then
                         below re-order level. Same ordering as the Stock Report. --}}
                    @php
                        $lowStock = collect($stockLevels)->take(6);
                        $tone = ['out' => 'danger', 'place_order' => 'danger', 'low' => 'warning'];
                        $statusLabels = \App\Services\GeneralStockReportService::statusLabels();

                        // Counted over the whole list, not the six shown, so the
                        // chips describe the situation rather than the excerpt.
                        $bySeverity = collect($stockLevels)->countBy('status');
                    @endphp

                    @if($stats['reorder_count'] > 0)
                        <div class="gx-chips">
                            @foreach(['out', 'place_order', 'low'] as $status)
                                @if(($bySeverity[$status] ?? 0) > 0)
                                    <span class="gx-chip gx-tone-{{ $tone[$status] }}">
                                        {{ $bySeverity[$status] }} {{ $statusLabels[$status] }}
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <div class="gx-alert-list">
                        @forelse($lowStock as $item)
                            <div class="gx-alert-row gx-tone-{{ $tone[$item['status']] }}">
                                <div class="min-w-0">
                                    <div class="gx-alert-name">{{ $item['name'] }}</div>
                                    <div class="gx-alert-sub">{{ $item['category'] }}</div>
                                </div>
                                <div class="text-end">
                                    <div class="gx-alert-qty">{{ $fmt($item['current']) }} / {{ $fmt($item['threshold']) }}</div>
                                    <div class="gx-alert-qty-sub">{{ $item['uom'] }}</div>
                                </div>
                            </div>
                        @empty
                            <p class="text-muted small mb-0">Every consumable is above its re-order level.</p>
                        @endforelse
                    </div>

                    @if($stats['reorder_count'] > $lowStock->count())
                        <a href="{{ route('store.stock.ledger', ['status' => 'attention']) }}" class="btn btn-sm btn-outline-danger mt-3">
                            View all {{ $stats['reorder_count'] }} in the Place Order list
                        </a>
                    @endif
                </x-card>
            </div>
        @endif

        @if($seeMovement)
            <div class="col-12 col-xl-8">
                <x-card class="gx-fade-in h-100" style="--gx-delay:700ms">
                    <x-slot:title>Recent stock movement</x-slot:title>
                    @if($seeStockReport)
                        <x-slot:actions>
                            <a href="{{ route('store.stock.ledger') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                        </x-slot:actions>
                    @endif

                    @include('store._movement-feed', ['rows' => $recentActivity, 'fmt' => $fmt])
                </x-card>
            </div>
        @endif
    </div>

</div>
@endsection
