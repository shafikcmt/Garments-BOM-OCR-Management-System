@extends('layouts.app')

@section('title', 'Supply Chain Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header icon="truck" eyebrow="Supply Chain"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Booking POs, payment requests and your share of the BOM.">
        <x-slot:actions>
            <a href="{{ route('supply_chain.bookings.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-clipboard-plus"></i>PO Generate
            </a>
            <a href="{{ route('supply_chain.workspace') }}" class="btn btn-outline-secondary">Workspace</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="clipboard-check" tone="primary" label="Booking POs generated"
                :value="$stats['pos']" :spark="collect($trend)->pluck('value')->all()"
                :href="route('supply_chain.bookings.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="hourglass-split" tone="warning" label="PRA pending"
                :value="$stats['pra_pending']" :href="route('supply_chain.payment_requests.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="check2-circle" tone="success" label="PRA approved"
                :value="$stats['pra_approved']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="envelope-check" tone="primary" label="Emails sent"
                :value="$stats['emails']" :href="route('supply_chain.sent_emails.index')" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms">
                <x-slot:title>
                    Booking POs generated — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>
                <x-area-chart :series="$trend" tone="primary" label="Booking POs generated per month" />
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms" title="Your share of the BOM">
                <x-workspace-progress :workspace="$workspace" />
                <a href="{{ route('supply_chain.workspace') }}" class="btn btn-sm btn-outline-primary mt-3">Open workspace</a>
            </x-card>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-4">
            <x-quick-action class="gx-fade-in" style="--gx-delay:600ms" icon="clipboard-plus" tone="primary"
                title="Generate PO" description="Create booking POs from the BOM"
                :href="route('supply_chain.bookings.index')" />
        </div>
        <div class="col-12 col-md-4">
            <x-quick-action class="gx-fade-in" style="--gx-delay:700ms" icon="cash-coin" tone="warning"
                title="Payment Requests" description="Raise and track PRAs"
                :href="route('supply_chain.payment_requests.index')" />
        </div>
        <div class="col-12 col-md-4">
            <x-quick-action class="gx-fade-in" style="--gx-delay:800ms" icon="envelope" tone="success"
                title="Sent Emails" description="Vendor correspondence log"
                :href="route('supply_chain.sent_emails.index')" />
        </div>
    </div>
</div>
@endsection
