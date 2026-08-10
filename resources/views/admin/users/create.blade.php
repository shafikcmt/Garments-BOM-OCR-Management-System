@extends('layouts.app')

@section('title', 'Create User')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Users'],
        ['label' => 'Create User'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Users</div>
                    <h3 class="app-hero-title mb-0">Create User</h3>
                </div>
            </div>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Users
            </a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf

        <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);max-width:640px;">
            <div class="card-body p-4">
                @include('admin.users.form')
                @include('admin.users._department-roles')
            </div>
        </div>

        {{-- Additional permissions, on top of whatever the role above grants. --}}
        <div class="card border-0 shadow-sm mt-4" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                <h5 class="mb-1">Additional Permissions</h5>
                <p class="text-muted small mb-4">
                    The role above already grants the greyed ticks. Tick anything extra this
                    person needs — it is saved against this user only.
                </p>

                @include('admin.users._permission-matrix', ['selectedRole' => old('role')])
            </div>
        </div>

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Save User
            </button>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
