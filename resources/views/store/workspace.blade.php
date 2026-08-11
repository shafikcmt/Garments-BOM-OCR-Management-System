@extends('layouts.app')

@section('title', 'Store Workspace')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success rounded-4 border-0 shadow-sm">{{ session('success') }}</div>
    @endif

    @if(session('warning'))
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">{{ session('warning') }}</div>
    @endif

    {{-- The resolver rather than store.dashboard: that screen is General Stock's
         now, and a Buyer / Style user with Workspace access would be refused by
         the crumb that was meant to take them home. --}}
    <x-breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('dashboard')],
        ['label' => 'Workspace'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:48px;height:48px;border-radius:17px;font-size:20px;"><i class="bi bi-shop" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Workspace</div>
                <h3 class="app-hero-title mb-1">Store Workspace</h3>
                <p class="app-hero-copy mb-0">Open uploaded files and update store related fields.</p>
            </div>
        </div>
    </div>

    {{-- Store's own required BOM columns, moved here from the Store dashboard.
         It measures work done on this screen, and the all-department table
         stays on the Admin Dashboard. Hidden when this department owns no
         columns, so the card never states a total of zero. --}}
    @if(($workspace['required_columns'] ?? 0) > 0)
        <div class="row g-3 mb-4">
            <div class="col-12 col-xl-5">
                <x-card class="h-100" title="Your share of the BOM">
                    <x-workspace-progress :workspace="$workspace" />
                </x-card>
            </div>
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link active" type="button"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Uploaded Files</button>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @include('partials.excel-files-table', ['files' => $files])
        </div>
    </div>
</div>
@endsection
