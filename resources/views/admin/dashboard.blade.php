@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h2 class="mb-1">Welcome, {{ auth()->user()->name }}</h2>
            <p class="text-muted mb-0">Manage users, roles and excel header access from one place.</p>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted">Total Users</small>
                    <h3 class="mb-0">{{ $totalUsers }}</h3>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted">Active Users</small>
                    <h3 class="mb-0">{{ $activeUsers }}</h3>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted">Total Roles</small>
                    <h3 class="mb-0">{{ $totalRoles }}</h3>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted">Excel Headers</small>
                    <h3 class="mb-0">{{ $totalHeaders }}</h3>
                    <div class="text-muted small mt-1">
                        Merchant upload enabled: {{ $merchantUploadHeaders }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <small class="text-muted">Booking Instructions</small>
                    <h3 class="mb-0">{{ $totalBookingInstructions ?? 0 }}</h3>
                    <div class="text-muted small mt-1">
                        Default: {{ $defaultBookingInstructions ?? 0 }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <h5>User Control</h5>
                    <p class="text-muted flex-grow-1">Create user, assign role and manage active status.</p>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-primary">Manage Users</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <h5>Role Control</h5>
                    <p class="text-muted flex-grow-1">Create and maintain system roles for all profiles.</p>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-primary">Manage Roles</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <h5>Excel Header Control</h5>
                    <p class="text-muted flex-grow-1">Set owner role, merchant upload access and visibility by header.</p>
                    <a href="{{ route('admin.headers.index') }}" class="btn btn-primary">Manage Headers</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <h5>Booking Instruction Control</h5>
                    <p class="text-muted flex-grow-1">Add, edit or delete default booking notes and extra suggestions for Supply Chain booking format.</p>
                    <a href="{{ route('admin.booking-instructions.index') }}" class="btn btn-primary">Manage Instructions</a>
                </div>
            </div>
        </div>
        <div class="col-md-3">
    <div class="card shadow-sm border-0 h-100">
        <div class="card-body">
            <small class="text-muted">Total Vendors</small>
            <h3 class="mb-0">{{ $totalSuppliers }}</h3>
            <div class="text-muted small mt-1">
                Active: {{ $activeSuppliers }}
            </div>
        </div>
    </div>
</div>
    </div>
</div>
@endsection