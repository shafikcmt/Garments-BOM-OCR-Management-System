@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header icon="sliders" eyebrow="Admin"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Users, roles, workspace columns and vendor master.">
        <x-slot:actions>
            <a href="{{ route('admin.users.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-people"></i>Users &amp; Roles
            </a>
            <a href="{{ route('admin.workspace') }}" class="btn btn-outline-secondary">Workspace</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="people" tone="primary" label="Users"
                :value="$totalUsers" :href="route('admin.users.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="shield-check" tone="success" label="Active users"
                :value="$activeUsers" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="columns-gap" tone="primary" label="Workspace columns"
                :value="$totalHeaders" :href="route('admin.headers.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="truck" tone="warning" label="Vendors"
                :value="$totalSuppliers" :href="route('admin.suppliers.index')" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-5">
            {{-- Column ownership decides who is responsible for which part of
                 the BOM, so it is the closest thing admin has to a workload
                 distribution across departments. --}}
            <x-card class="gx-fade-in h-100" style="--gx-delay:400ms" title="Workspace columns by owner role">
                <x-donut-chart caption="Columns" :total="$totalHeaders" :segments="collect($ownership)->map(fn ($row, $i) => [
                    'label' => \Illuminate\Support\Str::headline($row['role']),
                    'value' => $row['fields'],
                    'tone' => ['primary', 'success', 'warning', 'danger'][$i % 4],
                ])->all()" />
            </x-card>
        </div>

        <div class="col-12 col-xl-7">
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
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
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:600ms"
                icon="person-badge" tone="primary" label="Roles" :value="$totalRoles"
                :href="route('admin.roles.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:650ms"
                icon="upload" tone="success" label="Merchant-uploadable columns"
                :value="$merchantUploadHeaders" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:700ms"
                icon="journal-text" tone="primary" label="Booking instructions"
                :value="$totalBookingInstructions" :href="route('admin.booking-instructions.index')" />
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:750ms"
                icon="clipboard-check" tone="success" label="Booking POs generated"
                :value="$totalGeneratedPos" />
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:800ms" icon="person-plus" tone="primary"
                title="Add User" description="Create an account and assign a role"
                :href="route('admin.users.create')" />
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:850ms" icon="columns-gap" tone="success"
                title="Workspace Columns" description="Ownership and field rules"
                :href="route('admin.headers.index')" />
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:900ms" icon="truck" tone="warning"
                title="Vendors" description="Supplier master data"
                :href="route('admin.suppliers.index')" />
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <x-quick-action class="gx-fade-in" style="--gx-delay:950ms" icon="check2-square" tone="primary"
                title="PRA Approvers" description="Approval pool and cycles"
                :href="route('admin.pra-approvers.index')" />
        </div>
    </div>
</div>
@endsection
