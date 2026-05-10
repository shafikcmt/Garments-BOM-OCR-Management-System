@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 p-lg-5 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:54px;height:54px;border-radius:18px;font-size:23px;"><i class="bi bi-speedometer2"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin Control Center</div>
                    <h2 class="app-hero-title">Welcome, {{ auth()->user()->name }}</h2>
                    <p class="app-hero-copy mb-0">Manage users, roles, booking settings and excel header access from one clean workspace.</p>
                </div>
            </div>
            <a href="{{ route('admin.workspace') }}" class="btn btn-primary px-4">
                <i class="bi bi-grid me-1"></i>Open Workspace
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="app-stat-label">Total Users</div><div class="app-stat-value">{{ $totalUsers }}</div></div>
                    <span class="app-stat-icon"><i class="bi bi-people"></i></span>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="app-stat-label">Active Users</div><div class="app-stat-value">{{ $activeUsers }}</div></div>
                    <span class="app-stat-icon"><i class="bi bi-person-check"></i></span>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div><div class="app-stat-label">Total Roles</div><div class="app-stat-value">{{ $totalRoles }}</div></div>
                    <span class="app-stat-icon"><i class="bi bi-person-badge"></i></span>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <div class="app-stat-label">Excel Headers</div>
                        <div class="app-stat-value">{{ $totalHeaders }}</div>
                        <div class="text-muted small">Merchant enabled: {{ $merchantUploadHeaders }}</div>
                    </div>
                    <span class="app-stat-icon"><i class="bi bi-file-earmark-spreadsheet"></i></span>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <div class="app-stat-label">Booking Instructions</div>
                        <div class="app-stat-value">{{ $totalBookingInstructions ?? 0 }}</div>
                        <div class="text-muted small">Default: {{ $defaultBookingInstructions ?? 0 }}</div>
                    </div>
                    <span class="app-stat-icon"><i class="bi bi-card-checklist"></i></span>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="app-stat-card p-3 h-100">
                <div class="d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <div class="app-stat-label">Generated PO</div>
                        <div class="app-stat-value">{{ $totalGeneratedPos ?? 0 }}</div>
                        <div class="text-muted small">Admin controlled</div>
                    </div>
                    <span class="app-stat-icon"><i class="bi bi-file-earmark-check"></i></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <span class="app-stat-icon mb-3"><i class="bi bi-people"></i></span>
                    <h5 class="fw-bold">User Control</h5>
                    <p class="text-muted flex-grow-1">Create users, assign roles and manage active status.</p>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-primary">Manage Users</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <span class="app-stat-icon mb-3"><i class="bi bi-person-badge"></i></span>
                    <h5 class="fw-bold">Role Control</h5>
                    <p class="text-muted flex-grow-1">Create roles and keep permission groups organized.</p>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-primary">Manage Roles</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <span class="app-stat-icon mb-3"><i class="bi bi-file-earmark-spreadsheet"></i></span>
                    <h5 class="fw-bold">Excel Header Control</h5>
                    <p class="text-muted flex-grow-1">Control excel headers and merchant upload fields.</p>
                    <a href="{{ route('admin.headers.index') }}" class="btn btn-primary">Manage Headers</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <span class="app-stat-icon mb-3"><i class="bi bi-shield-lock"></i></span>
                    <h5 class="fw-bold">PO Generate Control</h5>
                    <p class="text-muted flex-grow-1">Control generated PO, re-generated PO, and before/after source changes.</p>
                    <a href="{{ route('admin.po-generate-control.index') }}" class="btn btn-primary">Manage PO Control</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
