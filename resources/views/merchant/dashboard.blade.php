@extends('layouts.app')

@section('title', 'Merchandising Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header icon="pencil-square" eyebrow="Merchandising"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Your share of the BOM workspace and what is still outstanding.">
        <x-slot:actions>
            <a href="{{ route('merchant.workspace') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Open Workspace
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="columns-gap" tone="primary" label="Columns you own"
                :value="$workspace['fields']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="list-ul" tone="primary" label="BOM lines"
                :value="$stats['rows']" :spark="collect($trend)->pluck('value')->all()" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="check2-square" tone="success" label="Fields filled"
                :value="$workspace['filled']" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="hourglass-split" tone="danger" label="Still to fill"
                :value="$workspace['pending']" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-5">
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="Your share of the BOM">
                <x-workspace-progress :workspace="$workspace" />
            </x-card>
        </div>

        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
                <x-slot:title>
                    BOM lines added — last 6 months
                    @if($delta !== null)
                        <span class="badge {{ $delta >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }} ms-1">
                            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs last month
                        </span>
                    @endif
                </x-slot:title>
                <x-area-chart :series="$trend" tone="primary" label="BOM lines added per month" />
            </x-card>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6">
            <x-quick-action class="gx-fade-in" style="--gx-delay:600ms" icon="grid-3x3-gap" tone="primary"
                title="BOM Workspace" description="Fill in the columns you own"
                :href="route('merchant.workspace')" />
        </div>
        <div class="col-12 col-md-6">
            <x-quick-action class="gx-fade-in" style="--gx-delay:700ms" icon="file-earmark-bar-graph" tone="success"
                title="Store Reports" description="Receive and issue summaries"
                :href="route('store.reports.index')" />
        </div>
    </div>
</div>
@endsection
