@extends('layouts.app')

@section('title', 'Inbound Shipments')

@section('content')
@php
    $tone = ['planned' => 'secondary', 'in_transit' => 'info', 'arrived' => 'warning', 'cleared' => 'primary', 'in_house' => 'success'];
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Commercial', 'url' => route('commercial.shipments.index')], ['label' => 'Inbound Shipments']]" />
    <x-page-header icon="truck" eyebrow="Commercial" title="Inbound Shipments"
                   copy="Imported material by BL / AWB: route, dates and the POs on it. The status follows the dates.">
        <x-slot:actions>
            <a href="{{ route('commercial.shipments.clearance') }}" class="btn btn-outline-secondary"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Clearance Summary</a>
            @if($canEdit)<a href="{{ route('commercial.shipments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Shipment</a>@endif
        </x-slot:actions>
    </x-page-header>
    <x-flash />

    <div class="row g-3 mb-3">
        <div class="col-6 col-md"><x-stat-card icon="calendar-event" tone="primary" label="Planned" :value="$summary['counts']['planned']" href="{{ route('commercial.shipments.index', ['status' => 'planned']) }}" /></div>
        <div class="col-6 col-md"><x-stat-card icon="truck" tone="primary" label="In transit" :value="$summary['counts']['in_transit']" href="{{ route('commercial.shipments.index', ['status' => 'in_transit']) }}" /></div>
        <div class="col-6 col-md"><x-stat-card icon="geo-alt" tone="warning" label="Arrived" :value="$summary['counts']['arrived']" href="{{ route('commercial.shipments.index', ['status' => 'arrived']) }}" /></div>
        <div class="col-6 col-md"><x-stat-card icon="shield-check" tone="primary" label="Cleared" :value="$summary['counts']['cleared']" href="{{ route('commercial.shipments.index', ['status' => 'cleared']) }}" /></div>
        <div class="col-6 col-md"><x-stat-card icon="exclamation-triangle" tone="danger" label="ETA passed" :value="$summary['late']->count()" href="{{ route('commercial.shipments.index', ['status' => 'late']) }}" /></div>
    </div>

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label mb-1" for="fStatus">Status</label>
                <select name="status" id="fStatus" class="form-select form-select-sm">
                    <option value="">All</option><option value="open" @selected($status === 'open')>Not yet in-house</option><option value="late" @selected($status === 'late')>ETA passed</option>
                    @foreach(\App\Models\InboundShipment::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
                </select></div>
            <div class="col-6 col-md-2"><label class="form-label mb-1" for="fMode">Mode</label>
                <select name="mode" id="fMode" class="form-select form-select-sm"><option value="">All</option>@foreach(\App\Models\InboundShipment::MODES as $k => $label)<option value="{{ $k }}" @selected($mode === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-12 col-md-4"><label class="form-label mb-1" for="fQ">Search</label>
                <input type="search" name="q" id="fQ" value="{{ $term }}" class="form-control form-control-sm" placeholder="Shipment, BL / AWB, container, forwarder, vessel, port"></div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button></div>
        </form>
    </x-card>

    <x-card body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Shipment</th><th>Buyer</th><th>BL / AWB</th><th>Mode</th><th>Port</th><th>ETD</th><th>ETA</th><th>ATA</th><th>In-house</th><th class="text-end">POs</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($shipments as $s)
                    @php($st = $s->status())
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $s->shipment_no }}</td>
                        <td>{{ $s->buyer?->buyer_name }}</td>
                        <td>{{ $s->bl_awb_no ?: '—' }}@if($s->container_no)<div class="small text-muted">{{ $s->container_no }}</div>@endif</td>
                        <td>{{ \App\Models\InboundShipment::MODES[$s->ship_mode] ?? $s->ship_mode }}</td>
                        <td>{{ $s->port_of_discharge ?: '—' }}</td>
                        <td class="text-nowrap">{{ $s->etd?->format('d M Y') ?? '—' }}</td>
                        <td class="text-nowrap">{{ $s->eta?->format('d M Y') ?? '—' }}@if($s->isLate($today))<span class="badge bg-danger text-white ms-1">Late</span>@endif</td>
                        <td class="text-nowrap">{{ $s->ata?->format('d M Y') ?? '—' }}</td>
                        <td class="text-nowrap">{{ ($s->actual_inhouse_date ?? $s->committed_inhouse_date)?->format('d M Y') ?? '—' }}@if(! $s->actual_inhouse_date && $s->committed_inhouse_date)<div class="small text-muted">committed</div>@endif</td>
                        <td class="text-end">{{ $s->links_count }}</td>
                        <td><span class="badge bg-{{ $tone[$st] }}">{{ $s->statusLabel() }}</span></td>
                        <td class="text-end"><a href="{{ route('commercial.shipments.show', $s) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="text-center text-muted py-4">No shipments here.@if($canEdit) Press New Shipment.@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
