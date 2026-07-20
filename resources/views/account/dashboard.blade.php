@extends('layouts.app')

@section('title', 'Accounts Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header icon="calculator" eyebrow="Accounts"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Payment request position and your share of the BOM workspace.">
        <x-slot:actions>
            <a href="{{ route('account.workspace') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-grid-3x3-gap"></i>Open Workspace
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="receipt" tone="primary" label="Payment requests"
                :value="$stats['pra_total']" :spark="collect($trend)->pluck('value')->all()" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="hourglass-split" tone="warning" label="Pending approval"
                :value="$stats['pra_pending']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="check2-circle" tone="success" label="Approved"
                :value="$stats['pra_approved']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="columns-gap" tone="primary" label="Columns you own"
                :value="$workspace['fields']" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms">
                <x-slot:title>
                    Payment requests raised — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>
                <x-area-chart :series="$trend" tone="primary" label="Payment requests raised per month" />
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms" title="Your share of the BOM">
                <x-workspace-progress :workspace="$workspace" />
                <a href="{{ route('account.workspace') }}" class="btn btn-sm btn-outline-primary mt-3">Open workspace</a>
            </x-card>
        </div>
    </div>
</div>
@endsection
