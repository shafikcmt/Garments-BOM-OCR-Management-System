@extends('layouts.app')

@section('title', 'Add Delivery Destination')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Booking Setup'],
        ['label' => 'Add Delivery Destination'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-geo-alt"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Booking Setup</div>
                    <h3 class="app-hero-title mb-0">Add Delivery Destination</h3>
                </div>
            </div>
            <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);max-width:720px;">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('admin.booking-delivery-destinations.store') }}">
                @csrf
                @include('admin.booking-delivery-destinations._form')
                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Destination</button>
                    <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
