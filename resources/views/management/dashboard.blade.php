@extends('layouts.app')

@section('title', 'Management Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="clipboard2-check" eyebrow="Management"
                   title="Management Overview"
                   copy="What needs your decision, where the company stands, and the reports.">
        <x-slot:actions>
            <a href="{{ route('pra_approvals.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-inbox" aria-hidden="true"></i>Pending PRA Approvals
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- 1. What needs THIS person's decision. Same counts as the sidebar badges. --}}
    <h2 class="h6 text-uppercase text-muted fw-semibold mb-2">Needs your decision</h2>
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="h-100" icon="cash-coin" tone="warning"
                label="Payment requests (PRA)" :value="$decisions['pra']" :href="route('pra_approvals.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="h-100" icon="clipboard-check" tone="primary"
                label="Purchase requisitions" :value="$decisions['requisitions']" :href="route('store.stock.requisitions.my-approvals')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="h-100" icon="patch-check" tone="success"
                label="BOM approvals" :value="$decisions['bom']" :href="route('bom_approvals.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="h-100" icon="check2-square" tone="danger"
                label="Follow-ups due" :value="$decisions['followups']" :href="route('follow_ups.index')" />
        </div>
    </div>

    {{-- 2. Company position: orders and the material pipeline, all buyers. --}}
    @if($orders || $supply)
        <h2 class="h6 text-uppercase text-muted fw-semibold mb-2">Company position</h2>
        <div class="row g-3 mb-4">
            @if($orders)
                @php $ok = $orders['kpis']; @endphp
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="bag-check" tone="primary" label="Open orders" :value="$ok['orders']" :href="route('merchant.status.index')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="box-seam" tone="primary" label="Order qty (pcs)" :value="number_format((float) $ok['order_qty'])" :href="route('merchant.status.index')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="calendar-event" tone="warning" label="Shipping in 14 days" :value="$ok['shipping_soon']" :href="route('merchant.status.index')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="exclamation-triangle" tone="danger" label="Booking late" :value="$ok['booking_late']" :href="route('supply_chain.reports.index')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="file-earmark-text" tone="warning" label="PI pending" :value="$ok['pi_pending']" :href="route('supply_chain.reports.index')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="credit-card" tone="warning" label="Payment pending" :value="$ok['payment_pending']" :href="route('supply_chain.reports.index')" />
                </div>
            @endif
            @if($supply)
                @php $sk = $supply['kpis']; @endphp
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="truck" tone="primary" label="In transit" :value="$sk['in_transit']" :href="route('commercial.shipments.index')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="hourglass-split" tone="danger" label="Arrival overdue" :value="$sk['eta_overdue']" :href="route('supply_chain.supplier_delivery')" />
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <x-stat-card class="h-100" icon="cash-stack" tone="danger" label="Payment overdue" :value="$sk['payment_overdue']" :href="route('supply_chain.reports.index')" />
                </div>
            @endif
        </div>
    @endif

    {{-- 3. Payment request (PRA) position. --}}
    <h2 class="h6 text-uppercase text-muted fw-semibold mb-2">Payment requests</h2>
    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-4">
            <x-stat-card class="h-100" icon="clock-history" tone="primary" label="Pending approval" :value="$stats['pending']" :spark="collect($trend)->pluck('value')->all()" />
        </div>
        <div class="col-12 col-sm-4">
            <x-stat-card class="h-100" icon="check2-circle" tone="success" label="Approved" :value="$stats['approved']" />
        </div>
        <div class="col-12 col-sm-4">
            <x-stat-card class="h-100" icon="x-circle" tone="danger" label="Rejected" :value="$stats['rejected']" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="PRA status distribution">
                <x-donut-chart caption="Total PRA" :total="$stats['total']" :segments="[
                    ['label' => 'Draft', 'value' => $stats['draft'], 'tone' => 'primary'],
                    ['label' => 'Pending approval', 'value' => $stats['pending'], 'tone' => 'warning'],
                    ['label' => 'Approved', 'value' => $stats['approved'], 'tone' => 'success'],
                    ['label' => 'Rejected', 'value' => $stats['rejected'], 'tone' => 'danger'],
                ]" />
            </x-card>
        </div>

        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
                <x-slot:title>
                    PRA raised — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>

                <x-area-chart :series="$trend" tone="primary" label="Payment requests raised per month" />
            </x-card>
        </div>
    </div>

    @include('shared._requisition-summary-card')

    <div class="row g-3 mb-4" data-aos="fade-up">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:600ms">
                <x-slot:title>Recent approval activity</x-slot:title>
                <x-slot:actions>
                    <a href="{{ route('pra_approvals.index') }}" class="btn btn-sm btn-outline-secondary">View all</a>
                </x-slot:actions>

                @php
                    // Map each approval action onto a tone and icon so the
                    // timeline reads at a glance.
                    $activityItems = $recentActivity->map(function ($approval) {
                        $status = $approval->status;
                        $isApproved = $status === \App\Models\PraApproval::STATUS_APPROVED;
                        $isRejected = $status === \App\Models\PraApproval::STATUS_REJECTED;

                        return [
                            'tone' => $isApproved ? 'success' : ($isRejected ? 'danger' : 'warning'),
                            'icon' => $isApproved ? 'check2' : ($isRejected ? 'x-lg' : 'hourglass-split'),
                            'title' => ($approval->approver->name ?? 'Someone')
                                .' '.($isApproved ? 'approved' : ($isRejected ? 'rejected' : 'reviewed'))
                                .' PRA '.($approval->paymentRequest->request_no ?? '#'.$approval->payment_request_id),
                            'description' => $approval->remarks ?: null,
                            'meta' => optional($approval->acted_at)->diffForHumans(),
                        ];
                    })->all();
                @endphp

                <x-timeline :items="$activityItems" />
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:700ms" title="Reports">
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="file-earmark-bar-graph" tone="primary" title="Merchandising Reports"
                            description="Orders, styles and BOM"
                            :href="route('merchant.reports.index')" />
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="file-earmark-bar-graph" tone="warning" title="Supply Chain Reports"
                            description="PO, PI and payment follow-up"
                            :href="route('supply_chain.reports.index')" />
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="file-earmark-bar-graph" tone="success" title="Commercial Reports"
                            description="Shipments and documents"
                            :href="route('commercial.reports.index')" />
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="file-earmark-bar-graph" tone="primary" title="Store Reports"
                            description="Receive and issue summaries"
                            :href="route('store.reports.index')" />
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12">
                        <x-quick-action icon="clock-history" tone="success" title="Approval History"
                            description="Past PRA decisions"
                            :href="route('admin.pra-approvals.history')" />
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
@endsection
