@extends('layouts.app')

@section('title', 'Clearance Summary')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Commercial', 'url' => route('commercial.shipments.index')], ['label' => 'Inbound Shipments', 'url' => route('commercial.shipments.index')], ['label' => 'Clearance Summary']]" />
    <x-page-header icon="clipboard-check" eyebrow="Commercial" title="Clearance Summary" copy="What is on its way, what needs chasing at the port, and what is cleared but not yet delivered.">
        <x-slot:actions><a href="{{ route('commercial.shipments.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Shipments</a></x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
            <x-card title="Not yet in-house, by port" body-class="p-0">
                <table class="table table-sm mb-0"><tbody>
                @forelse($summary['ports'] as $port => $n)<tr><td>{{ $port }}</td><td class="text-end fw-semibold">{{ $n }}</td></tr>@empty<tr><td class="text-muted text-center py-3">Nothing on the way.</td></tr>@endforelse
                </tbody></table>
            </x-card>
        </div>
        <div class="col-12 col-lg-4">
            <x-card title="Arriving, by expected week (ETA)" body-class="p-0">
                <table class="table table-sm mb-0"><tbody>
                @forelse($summary['weeks'] as $week => $n)<tr><td>{{ $week }}</td><td class="text-end fw-semibold">{{ $n }}</td></tr>@empty<tr><td class="text-muted text-center py-3">No expected arrivals.</td></tr>@endforelse
                </tbody></table>
            </x-card>
        </div>
        <div class="col-12 col-lg-4">
            <x-card title="By status" body-class="p-0">
                <table class="table table-sm mb-0"><tbody>
                @foreach(\App\Models\InboundShipment::STATUSES as $k => $label)<tr><td>{{ $label }}</td><td class="text-end fw-semibold">{{ $summary['counts'][$k] }}</td></tr>@endforeach
                </tbody></table>
            </x-card>
        </div>
    </div>

    @foreach([
        ['late', 'ETA passed, not arrived', 'eta', 'ETA', 'danger'],
        ['waiting', 'Arrived ' . $waitDays . '+ days ago, not customs cleared', 'ata', 'Arrived', 'warning'],
        ['to_deliver', 'Customs cleared, not yet in-house', 'customs_cleared_date', 'Cleared', 'primary'],
    ] as [$key, $title, $field, $dateLabel, $tone])
        <x-card class="mb-3" body-class="p-0">
            <x-slot:title>{{ $title }} <span class="badge bg-{{ $tone }} ms-1">{{ $summary[$key]->count() }}</span></x-slot:title>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th>Shipment</th><th>Buyer</th><th>BL / AWB</th><th>Port</th><th>{{ $dateLabel }}</th><th>Forwarder</th><th></th></tr></thead>
                    <tbody>
                    @forelse($summary[$key] as $s)
                        <tr>
                            <td class="fw-semibold">{{ $s->shipment_no }}</td><td>{{ $s->buyer?->buyer_name }}</td><td>{{ $s->bl_awb_no ?: '—' }}</td><td>{{ $s->port_of_discharge ?: '—' }}</td>
                            <td class="text-nowrap">{{ $s->{$field}?->format('d M Y') }}@if($key === 'late')<span class="small text-danger ms-1">{{ $s->eta->diffInDays($today) }} day(s) late</span>@elseif($key === 'waiting')<span class="small text-muted ms-1">{{ $s->waitingDays($today) }} day(s)</span>@endif</td>
                            <td>{{ $s->forwarder ?: '—' }}</td>
                            <td class="text-end"><a href="{{ route('commercial.shipments.show', $s) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-3">Nothing here.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endforeach
</div>
@endsection
