@extends('layouts.app')

@section('title', 'Buyer / Style Stock Dashboard')

@section('content')
@php
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');

    // Card-level scoping, same reasoning as the General Stock dashboard: this
    // module's entry guard admits anyone holding any one of its permissions.
    $user = auth()->user();
    $seeClosingStock = $user?->can('material.closing_stock.view') ?? false;
    $seeReceiving = $user?->can('material.receiving.view') ?? false;
    $seeBulkIssue = $user?->can('material.bulk_issue.view') ?? false;
    $seeRequisitions = $user?->can('material.requisitions.view') ?? false;
    $seeMovement = $seeReceiving || $seeBulkIssue;
@endphp
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="clipboard-data" eyebrow="Buyer / Style Stock"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Closing stock by style, receiving and bulk issuing.">
        <x-slot:actions>
            @if($seeReceiving)
                <a href="{{ route('store.material.receivings.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>Receiving
                </a>
            @endif
            @if($seeClosingStock)
                <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary">Closing Stock</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    <div class="row g-3 mb-4">
        @if($seeClosingStock)
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
                    icon="archive" tone="warning" label="Liability + dead qty"
                    :value="$fmt($stats['liability_qty'] + $stats['dead_qty'])" />
            </div>
        @endif

        @if($seeRequisitions)
            <div class="col-12 col-sm-6 col-xl-3">
                <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                    icon="hourglass-split" tone="primary" label="Pending requisitions"
                    :value="$stats['pending_requisitions']"
                    :href="route('store.material.requisitions.index')" />
            </div>
        @endif
    </div>

    <div class="row g-3 mb-4">
        @if($seeClosingStock)
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
        @endif

        @if($seeReceiving)
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
        @endif
    </div>

    <div class="row g-3 mb-4">
        @if($seeMovement)
            <div class="col-12 col-xl-7">
                <x-card class="gx-fade-in h-100" style="--gx-delay:600ms">
                    <x-slot:title>Recent stock movement</x-slot:title>
                    @if($seeClosingStock)
                        <x-slot:actions>
                            <a href="{{ route('store.material.ledger') }}" class="btn btn-sm btn-outline-secondary">View all</a>
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

        @if($seeRequisitions)
            <div class="col-12 col-xl-5">
                <x-card class="gx-fade-in h-100" style="--gx-delay:700ms" title="Requisition follow-up">
                    <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                        <div class="min-w-0">
                            <div class="fw-semibold">Not fully issued</div>
                            <div class="small text-muted">Lines where less was issued than required</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold {{ $stats['pending_req_lines'] > 0 ? 'text-warning' : 'text-muted' }}">{{ $stats['pending_req_lines'] }}</div>
                            <div class="small text-muted">{{ $fmt($stats['pending_req_qty']) }} qty</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-2 py-2">
                        <div class="min-w-0">
                            <div class="fw-semibold">Not fully received</div>
                            <div class="small text-muted">Lines where less was received than issued</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold {{ $stats['pending_recv_lines'] > 0 ? 'text-warning' : 'text-muted' }}">{{ $stats['pending_recv_lines'] }}</div>
                            <div class="small text-muted">{{ $fmt($stats['pending_recv_qty']) }} qty</div>
                        </div>
                    </div>

                    <a href="{{ route('store.material.requisitions.index') }}" class="btn btn-sm btn-outline-primary mt-3">Open requisitions</a>
                </x-card>
            </div>
        @endif
    </div>

    <div class="row g-3" data-aos="fade-up">
        @if($seeReceiving)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:800ms" icon="box-arrow-in-down" tone="success"
                    title="Receiving" description="Record material in"
                    :href="route('store.material.receivings.index')" />
            </div>
        @endif
        @if($seeBulkIssue)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:850ms" icon="box-arrow-up" tone="warning"
                    title="Bulk Issue" description="Issue to production"
                    :href="route('store.material.bulk-issues.index')" />
            </div>
        @endif
        @if($seeClosingStock)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:900ms" icon="clipboard-data" tone="primary"
                    title="Closing Stock" description="Running / liability / dead"
                    :href="route('store.material.ledger')" />
            </div>
        @endif
        @if($seeRequisitions)
            <div class="col-12 col-md-6 col-xl-3">
                <x-quick-action class="gx-fade-in" style="--gx-delay:950ms" icon="list-check" tone="primary"
                    title="Requisitions" description="Track issue and receipt"
                    :href="route('store.material.requisitions.index')" />
            </div>
        @endif
    </div>
</div>
@endsection
