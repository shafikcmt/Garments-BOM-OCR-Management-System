@extends('layouts.app')

@section('title', 'Email Templates')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Settings'],
        ['label' => 'Email Templates'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-envelope-paper" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Admin / Settings</div>
                <h3 class="app-hero-title mb-0">Email Templates</h3>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <ul class="nav nav-pills mb-3" id="emailTemplateTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pra-tab" data-bs-toggle="pill" data-bs-target="#pra-pane" type="button" role="tab">
                Payment Request (PRA)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="po-booking-tab" data-bs-toggle="pill" data-bs-target="#po-booking-pane" type="button" role="tab">
                PO Booking to Supplier
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {{-- PRA template --}}
        <div class="tab-pane fade show active" id="pra-pane" role="tabpanel">
            @include('admin.email-templates.partials.form', [
                'type' => 'pra',
                'heading' => 'Payment Request Approval (PRA) Email',
                'description' => 'This template is used to pre-fill the "Send Email" form on a PRA. Senders can still edit the subject and body before sending.',
                'defaultSubject' => 'Payment Request Approval - {{pr_number}}',
                'template' => $praTemplate,
                'placeholders' => $praPlaceholders,
            ])
        </div>

        {{-- PO Booking template --}}
        <div class="tab-pane fade" id="po-booking-pane" role="tabpanel">
            @include('admin.email-templates.partials.form', [
                'type' => 'po_booking',
                'heading' => 'PO Booking to Supplier Email',
                'description' => 'This template pre-fills the "Email to Supplier" form on a generated PO Booking. Senders can still edit the subject and body before sending.',
                'defaultSubject' => 'PO Booking {{po_number}} - {{buyer}} {{season}}',
                'template' => $poBookingTemplate,
                'placeholders' => $poBookingPlaceholders,
            ])
        </div>
    </div>
</div>
@endsection
