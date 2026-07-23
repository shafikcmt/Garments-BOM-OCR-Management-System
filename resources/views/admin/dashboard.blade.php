@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid">
    <x-page-header icon="sliders" eyebrow="Admin"
                   title="Welcome, {{ auth()->user()->name }}"
                   copy="Users, roles, workspace columns and vendor master.">
        <x-slot:actions>
            <a href="{{ route('admin.users.index') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-people" aria-hidden="true"></i>Users &amp; Roles
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

    {{-- Department progress. The donut above says who OWNS what; this says who
         has actually DONE it. Scoped to one order or across all of them by the
         selector, so the same table answers both questions. --}}
    <div class="row g-3 mb-4">
        <div class="col-12">
            <x-card class="gx-fade-in" style="--gx-delay:550ms">
                <x-slot:title>Department progress on required columns</x-slot:title>

                <form method="GET" class="row g-2 align-items-end mb-3">
                    <div class="col-12 col-md-6 col-lg-5">
                        <label class="form-label fw-semibold small mb-1" for="dashWorkspace">Order / Workspace</label>
                        <select name="workspace" id="dashWorkspace" class="form-select">
                            <option value="">All workspaces</option>
                            @foreach($workspaceOptions as $option)
                                <option value="{{ $option->id }}" {{ ($selectedWorkspace?->id === $option->id) ? 'selected' : '' }}>
                                    {{ $option->original_file_name }}@if($option->upload_batch_no) · {{ $option->upload_batch_no }}@endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-4 d-flex gap-2">
                        <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Show Progress</button>
                        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>

                @if(empty($departmentActivity))
                    <p class="text-muted small mb-0">No required columns are configured yet, so there is nothing to track.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Required Columns</th>
                                    <th style="min-width:160px;">Progress</th>
                                    <th>Last Worked On</th>
                                    <th>Last Sign-in</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($departmentActivity as $dept)
                                    @php $status = $dept['status']; @endphp
                                    <tr>
                                        <td class="fw-semibold">{{ $dept['label'] }}</td>
                                        <td>
                                            <span class="badge {{ \App\Services\DepartmentActivityService::statusTone($status) }}">
                                                {{ \App\Services\DepartmentActivityService::statusLabel($status) }}
                                            </span>
                                        </td>
                                        <td class="small">
                                            {{ $dept['columns_started'] }}/{{ $dept['required_columns'] }} filled
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height:6px;" role="img"
                                                     aria-label="{{ $dept['label'] }} is {{ $dept['percent'] }} percent complete">
                                                    <div class="progress-bar {{ $status === 'completed' ? 'bg-success' : 'bg-primary' }}"
                                                         style="width: {{ min(100, $dept['percent']) }}%;"></div>
                                                </div>
                                                <span class="small text-muted text-nowrap">{{ $dept['percent'] }}%</span>
                                            </div>
                                        </td>
                                        <td class="small text-muted">
                                            {{ $dept['last_activity'] ? \Illuminate\Support\Carbon::parse($dept['last_activity'])->diffForHumans() : 'Never' }}
                                        </td>
                                        <td class="small text-muted">
                                            {{ $dept['last_sign_in'] ? \Illuminate\Support\Carbon::parse($dept['last_sign_in'])->diffForHumans() : 'Never' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="form-text mt-2 mb-0">
                        Progress counts values entered in each department's own required columns.
                        “Last sign-in” is the most recent login by anyone in that department, across the whole system.
                    </p>
                @endif
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
