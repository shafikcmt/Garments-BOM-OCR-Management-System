@extends('layouts.app')

@section('title', 'Merchandising Dashboard')

@php
    $k = $kpis;
    $num = fn ($v) => number_format((float) $v);
    $pct = fn ($part, $whole) => $whole > 0 ? (int) round($part / $whole * 100) : 0;
    $canPra = auth()->user()?->can('approve-pra');

    // KPI tiles. `href` only where the user can open the target screen.
    $tiles = [
        ['label' => 'Total Orders', 'value' => $k['orders'], 'icon' => 'bag-check', 'tone' => 'primary',
         'sub' => $num($k['styles']).' styles · '.$num($k['customer_pos']).' customer POs',
         'href' => route('merchant.styles.index')],
        ['label' => 'Order Quantity', 'value' => $k['order_qty'], 'icon' => 'boxes', 'tone' => 'info',
         'sub' => 'pieces on order', 'href' => null],
        ['label' => 'Booking Pending', 'value' => $k['booking_pending'], 'icon' => 'cart-x', 'tone' => 'warning',
         'sub' => $k['booking_late'] > 0 ? $num($k['booking_late']).' past order-by date' : 'materials without a PO',
         'alert' => $k['booking_late'] > 0, 'href' => route('merchant.status.index')],
        ['label' => 'PI Pending', 'value' => $k['pi_pending'], 'icon' => 'file-earmark-text', 'tone' => 'purple',
         'sub' => 'PO placed, PI not received', 'href' => route('merchant.status.index')],
        ['label' => 'Payment Pending', 'value' => $k['payment_pending'], 'icon' => 'cash-coin', 'tone' => 'danger',
         'sub' => 'PI received, not paid', 'href' => route('merchant.status.index')],
        ['label' => 'PRA Pending', 'value' => $k['pra_pending'], 'icon' => 'clipboard-check', 'tone' => 'success',
         'sub' => $num($k['pra_approved']).' approved, awaiting payment', 'href' => $canPra ? route('pra_approvals.index') : null],
    ];

    $chartData = ['pipeline' => $pipeline, 'byBuyer' => $byBuyer, 'shipMonths' => $shipMonths];
@endphp

@section('content')
<div class="container-fluid md-dash">
    <x-page-header icon="pencil-square" eyebrow="Merchandising"
                   title="Merchandising Dashboard"
                   copy="Orders, material booking, PI, payment and shipments for your buyers.">
        <x-slot:actions>
            <a href="{{ route('merchant.status.index') }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-bar-chart-steps" aria-hidden="true"></i>Style Status
            </a>
            <a href="{{ route('merchant.vendors.create') }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-buildings" aria-hidden="true"></i>Add Vendor
            </a>
            <a href="{{ route('merchant.workspace') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Open Workspace
            </a>
        </x-slot:actions>
    </x-page-header>

    @unless($hasOrders)
        <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>No orders are linked yet. Upload or import a buyer's BOM to see orders, booking, PI and payment status here.</span>
        </div>
    @endunless

    {{-- KPI tiles --}}
    <div class="row g-3 mb-3">
        @foreach($tiles as $i => $t)
            <div class="col-6 col-md-4 col-xl-2">
                <{{ $t['href'] ? 'a' : 'div' }} @if($t['href']) href="{{ $t['href'] }}" @endif
                    class="md-kpi md-kpi--{{ $t['tone'] }} gx-fade-in h-100" style="--gx-delay:{{ $i * 60 }}ms">
                    <div class="md-kpi-head">
                        <span class="md-kpi-icon" aria-hidden="true"><i class="bi bi-{{ $t['icon'] }}"></i></span>
                        <span class="md-kpi-label">{{ $t['label'] }}</span>
                    </div>
                    <div class="md-kpi-value" data-count-to="{{ $t['value'] }}">{{ $num($t['value']) }}</div>
                    <div class="md-kpi-sub {{ ($t['alert'] ?? false) ? 'text-danger fw-semibold' : '' }}">{{ $t['sub'] }}</div>
                </{{ $t['href'] ? 'a' : 'div' }}>
            </div>
        @endforeach
    </div>

    {{-- Second row: what needs action today --}}
    <div class="row g-3 mb-4">
        @php
            $chips = [
                ['label' => 'Late bookings', 'value' => $k['booking_late'], 'icon' => 'exclamation-octagon', 'tone' => 'danger', 'href' => route('merchant.status.index')],
                ['label' => 'Booking due in 14 days', 'value' => $k['booking_due_soon'], 'icon' => 'hourglass-split', 'tone' => 'warning', 'href' => route('merchant.status.index')],
                ['label' => 'BOM approvals pending', 'value' => $k['bom_waiting'], 'icon' => 'patch-check', 'tone' => 'purple', 'href' => route('bom_approvals.index')],
                ['label' => 'My follow-ups due', 'value' => $k['followups_due'], 'icon' => 'check2-square', 'tone' => 'info', 'href' => route('follow_ups.index')],
                ['label' => 'Shipping in 30 days', 'value' => $k['shipping_soon'], 'icon' => 'truck', 'tone' => 'primary', 'href' => null],
            ];
        @endphp
        @foreach($chips as $c)
            <div class="col-6 col-md-4 col-xl">
                <{{ $c['href'] ? 'a' : 'div' }} @if($c['href']) href="{{ $c['href'] }}" @endif class="md-chip md-chip--{{ $c['tone'] }} {{ $c['value'] > 0 ? 'is-hot' : '' }}">
                    <i class="bi bi-{{ $c['icon'] }}" aria-hidden="true"></i>
                    <span class="md-chip-value">{{ $num($c['value']) }}</span>
                    <span class="md-chip-label">{{ $c['label'] }}</span>
                </{{ $c['href'] ? 'a' : 'div' }}>
            </div>
        @endforeach
    </div>

    {{-- Charts --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="h-100">
                <x-slot:title>
                    Material Pipeline
                    <span class="text-muted fw-normal small ms-1">{{ $num($k['materials']) }} material lines</span>
                </x-slot:title>
                <div class="md-chart md-chart--pipeline"><canvas id="md-pipeline" aria-label="Material lines by stage" role="img"></canvas></div>
                <div class="md-progress-row">
                    @foreach([
                        ['Booked', $k['materials'] - $k['booking_pending'], 'primary'],
                        ['PI received', $k['materials'] - $k['booking_pending'] - $k['pi_pending'], 'purple'],
                        ['Received in store', $k['received'], 'success'],
                    ] as [$label, $done, $tone])
                        <div class="md-progress">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">{{ $label }}</span>
                                <span class="fw-semibold">{{ $pct($done, $k['materials']) }}%</span>
                            </div>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar md-bar--{{ $tone }}" style="width: {{ $pct($done, $k['materials']) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card class="h-100" title="Order Quantity by Buyer">
                @if(count($byBuyer['values']))
                    <div class="md-chart md-chart--doughnut">
                        <canvas id="md-buyers" aria-label="Order quantity by buyer" role="img"></canvas>
                        <div class="md-doughnut-center">
                            <div class="md-doughnut-value">{{ $num($k['order_qty']) }}</div>
                            <div class="md-doughnut-label">pcs</div>
                        </div>
                    </div>
                @else
                    <div class="md-empty"><i class="bi bi-pie-chart" aria-hidden="true"></i>No order quantities yet.</div>
                @endif
            </x-card>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="h-100" title="Order Quantity by Ship Month">
                <div class="md-chart md-chart--bars"><canvas id="md-ship-months" aria-label="Order quantity by ship month" role="img"></canvas></div>
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card class="h-100" title="Your Share of the BOM">
                <x-workspace-progress :workspace="$workspace" />
            </x-card>
        </div>
    </div>

    {{-- Lists --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="h-100" body-class="p-0">
                <x-slot:title>Upcoming Shipments <span class="text-muted fw-normal small ms-1">next 30 days</span></x-slot:title>
                @if($upcoming->isEmpty())
                    <div class="md-empty"><i class="bi bi-truck" aria-hidden="true"></i>No shipments in the next 30 days.</div>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 md-table">
                            <thead>
                                <tr>
                                    <th>Style / Color / PO</th>
                                    <th class="text-end">Qty</th>
                                    <th>Ship Date</th>
                                    <th>Received</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($upcoming as $u)
                                    <tr>
                                        <td>
                                            @if($u['mother_id'])
                                                <a href="{{ route('merchant.styles.mothers.show', $u['mother_id']) }}" class="fw-semibold">{{ $u['style'] }}</a>
                                            @else
                                                <span class="fw-semibold">{{ $u['style'] ?: '—' }}</span>
                                            @endif
                                            <div class="small text-muted">{{ $u['buyer'] ?: '—' }} · {{ $u['color'] ?: '—' }} · {{ $u['customer_po'] ?: '—' }}</div>
                                        </td>
                                        <td class="text-end fw-semibold">{{ $u['order_qty'] !== null ? $num($u['order_qty']) : '—' }}</td>
                                        <td class="small">
                                            {{ $u['ship_date']->format('d-M-Y') }}
                                            <div><span class="badge {{ $u['days_left'] <= 7 ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis' }}">{{ $u['days_left'] === 0 ? 'Today' : $u['days_left'].' days' }}</span></div>
                                        </td>
                                        <td>
                                            @if($u['ready_pct'] !== null)
                                                <div class="progress" style="height:6px; width:72px;">
                                                    <div class="progress-bar {{ $u['ready_pct'] >= 100 ? 'bg-success' : ($u['ready_pct'] >= 50 ? 'bg-primary' : 'bg-danger') }}" style="width: {{ $u['ready_pct'] }}%"></div>
                                                </div>
                                                <div class="small text-muted text-nowrap mt-1"><span class="fw-semibold text-body">{{ $u['ready_pct'] }}%</span> · {{ $u['received'] }} of {{ $u['materials'] }}</div>
                                                @if($u['late'])<div class="small text-danger">{{ $u['late'] }} late</div>@endif
                                            @else
                                                <span class="small text-muted">No BOM</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card class="h-100" body-class="p-0">
                <x-slot:title>Needs Attention <span class="text-muted fw-normal small ms-1">booking past order-by date</span></x-slot:title>
                @if($attention->isEmpty())
                    <div class="md-empty text-success"><i class="bi bi-check-circle" aria-hidden="true"></i>No late bookings.</div>
                @else
                    <ul class="list-unstyled mb-0 md-attention">
                        @foreach($attention as $a)
                            <li>
                                <div>
                                    <div class="fw-semibold">{{ $a['style'] ?: '—' }}</div>
                                    <div class="small text-muted">{{ $a['buyer'] ?: '—' }}</div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-danger-subtle text-danger">{{ $a['late'] }} late</span>
                                    @if($a['mother_id'])
                                        <a href="{{ route('merchant.styles.mothers.show', $a['mother_id']) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1" aria-hidden="true"></i>View
                                        </a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-3">
            <x-quick-action icon="diagram-3" tone="primary" title="Style Library"
                description="Buyer, season, mother and child styles" :href="route('merchant.styles.index')" />
        </div>
        <div class="col-12 col-md-3">
            <x-quick-action icon="grid-3x3-gap" tone="primary" title="BOM Workspace"
                description="Fill in the columns you own" :href="route('merchant.workspace')" />
        </div>
        <div class="col-12 col-md-3">
            <x-quick-action icon="file-earmark-bar-graph" tone="primary" title="Merchandising Reports"
                description="Your BOM columns, with PDF and Excel" :href="route('merchant.reports.index')" />
        </div>
        <div class="col-12 col-md-3">
            <x-quick-action icon="file-earmark-bar-graph" tone="success" title="Store Reports"
                description="Receive and issue summaries" :href="route('store.reports.index')" />
        </div>
    </div>

    {{-- Stock Summary. Renders nothing for a user who may not see requisitions. --}}
    @include('shared._requisition-summary-card')
</div>

<script type="application/json" id="md-chart-data">@json($chartData)</script>

@include('partials.dashboard-kit-styles')
@endsection

@section('scripts')
    @vite('resources/js/merchant-dashboard.js')
@endsection
