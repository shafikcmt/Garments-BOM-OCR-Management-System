@extends('layouts.app')

@section('title', 'Add Booking Delivery Destination')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">Add Booking Delivery Destination</h5>
            <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
        <form method="POST" action="{{ route('admin.booking-delivery-destinations.store') }}">
            @csrf
            <div class="card-body">
                @include('admin.booking-delivery-destinations._form')
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Destination</button>
            </div>
        </form>
    </div>
</div>
@endsection
