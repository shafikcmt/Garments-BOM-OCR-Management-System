@extends('layouts.app')

@section('title', 'New Inbound Shipment')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Commercial', 'url' => route('commercial.shipments.index')], ['label' => 'Inbound Shipments', 'url' => route('commercial.shipments.index')], ['label' => 'New']]" />
    <x-page-header icon="truck" eyebrow="Commercial" title="New Inbound Shipment" copy="One BL / AWB of imported material. Add the POs it carries on the next page." />
    <x-flash />
    @if($errors->any())<div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ route('commercial.shipments.store') }}">
        @csrf
        <x-card class="mb-3" body-class="p-3">
            <div class="row g-2 mb-2">
                <div class="col-12 col-md-4"><label class="form-label mb-1" for="shBuyer">Buyer *</label>
                    <select name="buyer_id" id="shBuyer" class="form-select form-select-sm" required>
                        <option value="">Choose</option>
                        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected((string) old('buyer_id') === (string) $b->id)>{{ $b->buyer_name }}</option>@endforeach
                    </select></div>
            </div>
            @include('commercial.shipments._fields', ['shipment' => $shipment, 'canEdit' => true])
        </x-card>
        <button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Shipment</button>
        <a href="{{ route('commercial.shipments.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
@endsection
