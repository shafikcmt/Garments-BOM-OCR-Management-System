@extends('layouts.app')

@section('title', 'Supplier Delivery')

@section('content')
@php
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $label = ['overdue' => 'Overdue', 'no_date' => 'No date given', 'open' => 'Open, not due yet', 'delivered_late' => 'Delivered late', 'on_time' => 'Delivered on time'];
    $tone = ['overdue' => 'danger', 'no_date' => 'warning', 'open' => 'secondary', 'delivered_late' => 'warning', 'on_time' => 'success', 'delivered' => 'success'];
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Supply Chain', 'url' => route('supply_chain.supplier_delivery')], ['label' => 'Supplier Delivery']]" />
    <x-page-header icon="clock-history" eyebrow="Supply Chain" title="Supplier Delivery"
                   copy="The date each supplier promised against what has arrived, per PO and per supplier. Chase the overdue ones first.">
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card icon="exclamation-triangle" tone="danger" label="Overdue PO lines" :value="$kpis['overdue']" href="{{ route('supply_chain.supplier_delivery', ['status' => 'overdue']) }}" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="calendar-x" tone="warning" label="No promised date" :value="$kpis['no_date']" href="{{ route('supply_chain.supplier_delivery', ['status' => 'no_date']) }}" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="hourglass-split" tone="primary" label="Open, not due yet" :value="$kpis['open']" href="{{ route('supply_chain.supplier_delivery', ['status' => 'open']) }}" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="check2-circle" tone="success" label="Delivered on time" :value="$kpis['on_time_rate'] === null ? '—' : $kpis['on_time_rate'] . '%'" /></div>
    </div>

    <x-card title="Suppliers" class="mb-3" body-class="p-0">
        <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead class="sticky-top bg-white"><tr><th>Supplier</th><th class="text-end">PO lines</th><th class="text-end">Delivered</th><th class="text-end">Overdue</th><th class="text-end">No date</th><th class="text-end">Open</th><th class="text-end">On time</th><th class="text-end">Avg days late</th></tr></thead>
                <tbody>
                @forelse($suppliers as $s)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('supply_chain.supplier_delivery', ['q' => $s['vendor'], 'status' => $s['overdue'] > 0 ? 'overdue' : ($s['no_date'] > 0 ? 'no_date' : 'open')]) }}">{{ $s['vendor'] }}</a></td>
                        <td class="text-end">{{ $s['lines'] }}</td><td class="text-end">{{ $s['delivered'] }}</td>
                        <td class="text-end {{ $s['overdue'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ $s['overdue'] }}@if($s['max_overdue'] > 0)<div class="small text-muted fw-normal">up to {{ $s['max_overdue'] }}d</div>@endif</td>
                        <td class="text-end {{ $s['no_date'] > 0 ? 'text-warning-emphasis' : '' }}">{{ $s['no_date'] }}</td><td class="text-end">{{ $s['open'] }}</td>
                        <td class="text-end">{{ $s['on_time_rate'] === null ? '—' : $s['on_time_rate'] . '%' }}</td><td class="text-end">{{ $s['avg_days_late'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No generated POs yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label mb-1" for="dStatus">Show</label>
                <select name="status" id="dStatus" class="form-select form-select-sm">@foreach($label as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-12 col-md-5"><label class="form-label mb-1" for="dQ">Search</label>
                <input type="search" name="q" id="dQ" value="{{ $term }}" class="form-control form-control-sm" placeholder="Supplier, PO no., style or item"></div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button></div>
        </form>
    </x-card>

    <x-card body-class="p-0">
        <x-slot:title>{{ $label[$status] }} <span class="badge bg-light text-muted border ms-1">{{ $linesTotal }}</span></x-slot:title>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Supplier</th><th>PO no.</th><th>Style</th><th>Item</th><th class="text-end">PO qty</th><th class="text-end">Received</th><th>Promised</th><th>Delivered</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($lines as $l)
                    @php($po = $l['po'])
                    <tr>
                        <td class="fw-semibold">{{ $l['vendor'] }}</td>
                        <td class="text-nowrap">{{ $po->po_no }}</td><td>{{ $po->style_name }}</td><td>{{ $po->item_name ?: $po->description }}</td>
                        <td class="text-end">{{ $q($l['qty']) }} {{ $po->uom }}</td>
                        <td class="text-end">{{ $q($l['received']) }}@if($l['partial'])<div class="small text-warning-emphasis">partial</div>@endif</td>
                        <td class="text-nowrap">{{ $l['promised']?->format('d M Y') ?? '—' }}@if($l['basis'])<div class="small text-muted">{{ $l['basis'] }}</div>@endif</td>
                        <td class="text-nowrap">{{ $l['delivered_on']?->format('d M Y') ?? '—' }}</td>
                        <td><span class="badge bg-{{ $tone[$l['status']] ?? 'secondary' }}{{ in_array($l['status'], ['no_date', 'delivered_late']) ? ' text-dark' : '' }}">{{ $label[$l['status']] ?? 'Delivered' }}</span>
                            @if($l['days_late'] > 0)<div class="small text-danger">{{ $l['days_late'] }} day(s) late</div>@endif</td>
                        <td class="text-end"><a href="{{ route('supply_chain.bookings.show', $po) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>View PO</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">Nothing here.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
    <p class="small text-muted mt-3 mb-0">Promised date = Committed in-house, else Committed ETA, else Committed ex-mill (the first one filled on the PO's workspace row). Delivered = Actual in-house is filled, or Store has received 98% or more of the PO quantity; the delivery date is Actual in-house, else the last receipt. This page only reads; fill the dates in the workspace or on Inbound Shipments.</p>
</div>
@endsection
