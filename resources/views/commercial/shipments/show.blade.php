@extends('layouts.app')

@section('title', 'Shipment ' . $shipment->shipment_no)

@section('content')
@php
    $tone = ['planned' => 'secondary', 'in_transit' => 'info', 'arrived' => 'warning', 'cleared' => 'primary', 'in_house' => 'success'];
    $st = $shipment->status();
    $q = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Commercial', 'url' => route('commercial.shipments.index')], ['label' => 'Inbound Shipments', 'url' => route('commercial.shipments.index')], ['label' => $shipment->shipment_no]]" />
    <x-page-header icon="truck" :eyebrow="$shipment->buyer?->buyer_name" :title="'Shipment ' . $shipment->shipment_no . ($shipment->bl_awb_no ? ' — ' . $shipment->bl_awb_no : '')" copy="Route, dates and the POs on this BL / AWB.">
        <x-slot:actions><a href="{{ route('commercial.shipments.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Shipments</a></x-slot:actions>
    </x-page-header>
    <x-flash />
    @if($errors->any())<div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <x-card class="mb-3" body-class="p-3">
        <x-slot:title>Shipment <span class="badge bg-{{ $tone[$st] }} ms-2">{{ $shipment->statusLabel() }}</span>
            @if($shipment->isLate($today))<span class="badge bg-danger text-white ms-1">ETA passed</span>@endif
            @if(($w = $shipment->waitingDays($today)) !== null && $st !== 'in_house')<span class="badge bg-light text-muted border ms-1">{{ $w }} day(s) since arrival</span>@endif</x-slot:title>
        <form method="POST" action="{{ route('commercial.shipments.update', $shipment) }}">
            @csrf @method('PUT')
            @include('commercial.shipments._fields', ['shipment' => $shipment, 'canEdit' => $canEdit])
            @if($canEdit)<button class="btn btn-primary btn-sm mt-3"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Shipment</button>@endif
        </form>
        <p class="small text-muted mt-3 mb-0">Saving also writes the BL / AWB no, ETD, ETA and in-house dates into the workspace rows of the POs below, for the columns the workspace has.</p>
    </x-card>

    <x-card class="mb-3" body-class="p-0">
        <x-slot:title>POs on this shipment <span class="badge bg-light text-muted border ms-1">{{ $shipment->links->count() }}</span></x-slot:title>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>PO no.</th><th>Vendor</th><th>Style</th><th>Item</th><th class="text-end">PO qty</th><th class="text-end" style="width:170px">Qty on this shipment</th><th></th></tr></thead>
                <tbody>
                @forelse($shipment->links as $l)
                    @php($po = $l->bookingPo)
                    <tr>
                        <td class="fw-semibold">{{ $po?->po_no ?? '—' }}</td><td>{{ $po?->vendor_name }}</td><td>{{ $po?->style_name }}</td><td>{{ $po?->item_name ?: $po?->description }}</td>
                        <td class="text-end">{{ $q($po?->qty) }} {{ $po?->uom }}</td>
                        <td class="text-end">
                            @if($canEdit)
                                <form method="POST" action="{{ route('commercial.shipments.pos.update', [$shipment, $l]) }}" class="d-flex gap-1 justify-content-end">@csrf @method('PUT')
                                    <input type="number" step="any" min="0" name="qty" value="{{ $q($l->qty) }}" class="form-control form-control-sm text-end" style="width:100px" aria-label="Quantity on this shipment">
                                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                                </form>
                            @else {{ $q($l->qty) }} @endif
                        </td>
                        <td class="text-end">@if($canEdit)<form method="POST" action="{{ route('commercial.shipments.pos.remove', [$shipment, $l]) }}" onsubmit="return confirm('Remove this PO from the shipment?')">@csrf @method('DELETE')
                            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Remove</button></form>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No PO added yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($canEdit)
            <div class="p-3 border-top">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-5"><label class="form-label mb-1" for="poQ">Find a PO of {{ $shipment->buyer?->buyer_name }} to add</label>
                        <input type="search" name="q" id="poQ" value="{{ $term }}" class="form-control form-control-sm" placeholder="PO no., vendor, style or item"></div>
                    <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button></div>
                </form>
                @if($term !== '')
                    @if($found->isEmpty())
                        <p class="text-muted small mt-2 mb-0">No matching PO of this buyer that is not already on the shipment.</p>
                    @else
                        <div class="table-responsive mt-2">
                            <table class="table table-sm align-middle mb-0" style="min-width:0">
                                <tbody>
                                @foreach($found as $po)
                                    <tr>
                                        <td class="fw-semibold">{{ $po->po_no }}</td><td>{{ $po->vendor_name }}</td><td>{{ $po->style_name }}</td><td>{{ $po->item_name ?: $po->description }}</td><td class="text-end">{{ $q($po->qty) }} {{ $po->uom }}</td>
                                        <td class="text-end"><form method="POST" action="{{ route('commercial.shipments.pos.add', $shipment) }}" class="d-flex gap-1 justify-content-end">@csrf
                                            <input type="hidden" name="booking_po_id" value="{{ $po->id }}">
                                            <input type="number" step="any" min="0" name="qty" value="{{ $q($po->qty) }}" class="form-control form-control-sm text-end" style="width:100px" aria-label="Quantity">
                                            <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add</button></form></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </div>
        @endif
    </x-card>

    @if($canEdit)
        <form method="POST" action="{{ route('commercial.shipments.destroy', $shipment) }}" onsubmit="return confirm('Delete this shipment? The POs are not changed.')">@csrf @method('DELETE')
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Shipment</button></form>
    @endif
</div>
@endsection
