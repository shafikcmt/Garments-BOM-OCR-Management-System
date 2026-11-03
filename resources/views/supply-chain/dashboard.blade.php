@extends('layouts.app')

@section('title', 'Supply Chain Dashboard')

@php
    $k = $kpis;
    $num = fn ($v) => number_format((float) $v);
    $canPra = auth()->user()?->can('approve-pra');

    // KPI tiles: the material lines at each step from booking to arrival.
    $tiles = [
        ['label' => 'Booking Pending', 'value' => $k['booking_pending'], 'icon' => 'cart-x', 'tone' => 'warning',
         'sub' => $k['booking_late'] > 0 ? $num($k['booking_late']).' past order-by date' : 'materials without a PO',
         'alert' => $k['booking_late'] > 0, 'href' => route('supply_chain.workspace')],
        ['label' => 'PI Pending', 'value' => $k['pi_pending'], 'icon' => 'file-earmark-text', 'tone' => 'purple',
         'sub' => 'PO placed, PI not received', 'href' => route('supply_chain.workspace')],
        ['label' => 'Payment Pending', 'value' => $k['payment_pending'], 'icon' => 'cash-coin', 'tone' => 'danger',
         'sub' => $k['payment_overdue'] > 0 ? $num($k['payment_overdue']).' past required date' : 'PI received, not paid',
         'alert' => $k['payment_overdue'] > 0, 'href' => route('supply_chain.payment_requests.index')],
        ['label' => 'In Transit', 'value' => $k['in_transit'], 'icon' => 'truck', 'tone' => 'info',
         'sub' => 'shipped, not yet arrived', 'href' => null],
        ['label' => 'Arrival Overdue', 'value' => $k['eta_overdue'], 'icon' => 'alarm', 'tone' => 'danger',
         'sub' => 'past Committed ETA', 'alert' => $k['eta_overdue'] > 0, 'href' => null],
        ['label' => 'PRA Pending', 'value' => $stats['pra_pending'], 'icon' => 'clipboard-check', 'tone' => 'success',
         'sub' => $num($stats['pra_approved']).' approved, awaiting payment', 'href' => route('supply_chain.payment_requests.index')],
    ];

    $chips = [
        ['label' => 'Late bookings', 'value' => $k['booking_late'], 'icon' => 'exclamation-octagon', 'tone' => 'danger', 'hot' => true, 'href' => route('supply_chain.workspace')],
        ['label' => 'Booking due in 14 days', 'value' => $k['booking_due_soon'], 'icon' => 'hourglass-split', 'tone' => 'warning', 'hot' => true, 'href' => route('supply_chain.workspace')],
        ['label' => 'Arriving in 7 days', 'value' => $k['eta_soon'], 'icon' => 'box-arrow-in-down', 'tone' => 'primary', 'hot' => true, 'href' => null],
        ['label' => 'Order lines', 'value' => $k['orders'], 'icon' => 'bag-check', 'tone' => 'info', 'href' => null],
        ['label' => 'Booking POs generated', 'value' => $stats['pos'], 'icon' => 'clipboard-check', 'tone' => 'purple', 'href' => route('supply_chain.bookings.index')],
        ['label' => 'Emails sent', 'value' => $stats['emails'], 'icon' => 'envelope-check', 'tone' => 'primary', 'href' => route('supply_chain.sent_emails.index')],
    ];

    $chartData = [
        'pipeline' => $pipeline,
        'byBuyer' => $byBuyer,
        'etaMonths' => $etaMonths,
        'byVendor' => $byVendor,
    ];
@endphp

@section('content')
<div class="container-fluid md-dash">
    <x-page-header data-aos="fade-down" icon="truck" eyebrow="Supply Chain"
                   title="Supply Chain Dashboard"
                   copy="Booking, PI, payment and shipment status of every material line, for your buyers.">
        <x-slot:actions>
            <a href="{{ route('supply_chain.bookings.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-clipboard-plus" aria-hidden="true"></i>PO Generate
            </a>
            <a href="{{ route('supply_chain.workspace') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Workspace
            </a>
        </x-slot:actions>
    </x-page-header>

    @unless($hasData)
        <div class="alert alert-info d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>No order lines are linked yet. Once Merchandising imports a buyer's BOM, booking, PI, payment and shipment status appears here.</span>
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

    {{-- What needs action today --}}
    <div class="row g-3 mb-4">
        @foreach($chips as $c)
            <div class="col-6 col-md-4 col-xl-2">
                <{{ $c['href'] ? 'a' : 'div' }} @if($c['href']) href="{{ $c['href'] }}" @endif class="md-chip md-chip--{{ $c['tone'] }} {{ ($c['hot'] ?? false) && $c['value'] > 0 ? 'is-hot' : '' }}">
                    <i class="bi bi-{{ $c['icon'] }}" aria-hidden="true"></i>
                    <span class="md-chip-value">{{ $num($c['value']) }}</span>
                    <span class="md-chip-label">{{ $c['label'] }}</span>
                </{{ $c['href'] ? 'a' : 'div' }}>
            </div>
        @endforeach
    </div>

    {{-- Pipeline and buyers --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="h-100">
                <x-slot:title>
                    Material Pipeline
                    <span class="text-muted fw-normal small ms-1">{{ $num($k['materials']) }} material lines</span>
                </x-slot:title>
                <div class="md-chart md-chart--pipeline"><canvas id="sc-pipeline" aria-label="Material lines by stage" role="img"></canvas></div>
                <div class="md-progress-row sc-progress-row">
                    @foreach(['Booked' => 'primary', 'PI received' => 'purple', 'Paid' => 'warning', 'Received' => 'success'] as $label => $tone)
                        <div class="md-progress">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">{{ $label }}</span>
                                <span class="fw-semibold">{{ $progress[$label] }}%</span>
                            </div>
                            <div class="progress" style="height:6px;">
                                <div class="progress-bar md-bar--{{ $tone }}" style="width: {{ $progress[$label] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card class="h-100" title="Open Work by Buyer">
                @if(count($byBuyer['labels']))
                    <div class="md-chart md-chart--bars"><canvas id="sc-buyers" aria-label="Pending material lines by buyer" role="img"></canvas></div>
                @else
                    <div class="md-empty text-success"><i class="bi bi-check-circle" aria-hidden="true"></i>Nothing pending.</div>
                @endif
            </x-card>
        </div>
    </div>

    {{-- Shipments and vendors --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-6">
            <x-card class="h-100">
                <x-slot:title>Arrivals by Committed ETA <span class="text-muted fw-normal small ms-1">lines still to arrive</span></x-slot:title>
                <div class="md-chart md-chart--bars"><canvas id="sc-eta" aria-label="Material lines still to arrive, by Committed ETA month" role="img"></canvas></div>
            </x-card>
        </div>
        <div class="col-12 col-xl-6">
            <x-card class="h-100">
                <x-slot:title>Waiting on Vendors <span class="text-muted fw-normal small ms-1">top 8, ordered but not received</span></x-slot:title>
                @if(count($byVendor['labels']))
                    <div class="md-chart md-chart--bars"><canvas id="sc-vendors" aria-label="Material lines waiting on each vendor" role="img"></canvas></div>
                @else
                    <div class="md-empty text-success"><i class="bi bi-check-circle" aria-hidden="true"></i>No lines waiting on a vendor.</div>
                @endif
            </x-card>
        </div>
    </div>

    {{-- Needs attention --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-4">
            <x-card class="h-100" body-class="p-3 pb-1">
                <x-slot:title>Late Bookings <span class="text-muted fw-normal small ms-1">past order-by date</span></x-slot:title>
                @if($lateBooking->isEmpty())
                    <div class="md-empty text-success"><i class="bi bi-check-circle" aria-hidden="true"></i>No late bookings.</div>
                @else
                    <ul class="list-unstyled mb-0 md-attention sc-list">
                        @foreach($lateBooking as $r)
                            <li>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $r['material'] ?: '—' }}</div>
                                    <div class="small text-muted text-truncate">{{ $r['style'] ?: '—' }} · {{ $r['buyer'] }}</div>
                                </div>
                                <div class="text-end text-nowrap">
                                    <span class="badge bg-danger-subtle text-danger">{{ $r['days'] }} {{ $r['days'] === 1 ? 'day' : 'days' }} late</span>
                                    <div class="small text-muted">{{ $r['date']->format('d-M-Y') }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
        <div class="col-12 col-xl-4">
            <x-card class="h-100" body-class="p-3 pb-1">
                <x-slot:title>PI Pending <span class="text-muted fw-normal small ms-1">oldest PO first</span></x-slot:title>
                @if($piPending->isEmpty())
                    <div class="md-empty text-success"><i class="bi bi-check-circle" aria-hidden="true"></i>No PI pending.</div>
                @else
                    <ul class="list-unstyled mb-0 md-attention sc-list">
                        @foreach($piPending as $r)
                            <li>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $r['vendor'] ?: 'No vendor' }}</div>
                                    <div class="small text-muted text-truncate">{{ $r['po'] }} · {{ $r['material'] ?: '—' }}</div>
                                </div>
                                <div class="text-end text-nowrap">
                                    @if($r['days'] !== null)
                                        <span class="badge {{ $r['days'] > 14 ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis' }}">{{ $r['days'] }} {{ $r['days'] === 1 ? 'day' : 'days' }}</span>
                                        <div class="small text-muted">{{ $r['date']->format('d-M-Y') }}</div>
                                    @else
                                        <span class="small text-muted">No PO date</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
        <div class="col-12 col-xl-4">
            <x-card class="h-100" body-class="p-3 pb-1">
                <x-slot:title>Arrival Overdue <span class="text-muted fw-normal small ms-1">past Committed ETA</span></x-slot:title>
                @if($arrivalOverdue->isEmpty())
                    <div class="md-empty text-success"><i class="bi bi-check-circle" aria-hidden="true"></i>Nothing overdue.</div>
                @else
                    <ul class="list-unstyled mb-0 md-attention sc-list">
                        @foreach($arrivalOverdue as $r)
                            <li>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $r['vendor'] ?: 'No vendor' }}</div>
                                    <div class="small text-muted text-truncate">{{ $r['po'] }} · {{ $r['material'] ?: '—' }}</div>
                                </div>
                                <div class="text-end text-nowrap">
                                    <span class="badge bg-danger-subtle text-danger">{{ $r['days'] }} {{ $r['days'] === 1 ? 'day' : 'days' }} late</span>
                                    <div class="small text-muted">{{ $r['in_transit'] ? 'In transit · ' : '' }}ETA {{ $r['date']->format('d-M') }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>

    {{-- Booking POs generated, and the department's share of the BOM --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="h-100">
                <x-slot:title>
                    Booking POs generated — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>
                <x-area-chart :series="$trend" tone="primary" label="Booking POs generated per month" />
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card class="h-100" title="Your Share of the BOM">
                <x-workspace-progress :workspace="$workspace" />
                <a href="{{ route('supply_chain.workspace') }}" class="btn btn-sm btn-outline-primary mt-3">
                    <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Open Workspace
                </a>
            </x-card>
        </div>
    </div>

    <div class="row g-3" data-aos="fade-up">
        <div class="col-12 col-md-4">
            <x-quick-action icon="clipboard-plus" tone="primary"
                title="Generate PO" description="Create booking POs from the BOM"
                :href="route('supply_chain.bookings.index')" />
        </div>
        <div class="col-12 col-md-4">
            <x-quick-action icon="cash-coin" tone="warning"
                title="Payment Requests" description="Raise and track PRAs"
                :href="route('supply_chain.payment_requests.index')" />
        </div>
        <div class="col-12 col-md-4">
            <x-quick-action icon="envelope" tone="success"
                title="Sent Emails" description="Vendor correspondence log"
                :href="route('supply_chain.sent_emails.index')" />
        </div>
    </div>

    {{-- Stock Summary. Renders nothing for a user who may not see
         requisitions, so it is included unconditionally. --}}
    @include('shared._requisition-summary-card')
</div>

<script type="application/json" id="sc-chart-data">@json($chartData)</script>
@include('partials.dashboard-kit-styles')
<style>
    .sc-progress-row { grid-template-columns:repeat(4, 1fr); }
    @media (max-width: 767.98px) { .sc-progress-row { grid-template-columns:repeat(2, 1fr); } }
    .md-attention .min-w-0 { min-width:0; }
    .sc-list li { padding-left:.25rem; padding-right:.25rem; }
</style>
@endsection

@section('scripts')
    @vite('resources/js/supply-chain-dashboard.js')
@endsection
