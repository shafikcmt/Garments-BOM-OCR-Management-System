@extends('layouts.app')

@section('title', 'User Profile')

@section('content')
@php
    $avatarUrl = $user->avatarUrl();
    $isActive = (int) ($user->status ?? 1) === 1;
    $currentRole = $user->getRoleNames()->first();
@endphp

<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                @if($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="{{ $user->name }}" class="rounded-circle" style="width:64px;height:64px;object-fit:cover;border:2px solid #fff;box-shadow:0 4px 12px rgba(15,23,42,.12);">
                @else
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold" style="width:64px;height:64px;font-size:22px;">{{ $user->initials() }}</span>
                @endif
                <div>
                    <div class="app-hero-eyebrow">Admin / Users</div>
                    <h3 class="app-hero-title mb-1">{{ $user->name }}</h3>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        @if($currentRole)
                            <span class="badge bg-primary-subtle text-primary-emphasis">{{ ucfirst(str_replace('_', ' ', $currentRole)) }}</span>
                        @endif
                        <span class="badge {{ $isActive ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary d-inline-flex align-items-center gap-2"><i class="bi bi-pencil-square"></i> Edit</a>
                <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);max-width:760px;">
        <div class="card-body p-4">
            <h5 class="mb-3">Profile Details</h5>
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted fw-semibold">Full Name</dt>
                <dd class="col-sm-8">{{ $user->name }}</dd>
                <dt class="col-sm-4 text-muted fw-semibold">Email Address</dt>
                <dd class="col-sm-8">{{ $user->email }}</dd>
                <dt class="col-sm-4 text-muted fw-semibold">Role</dt>
                <dd class="col-sm-8">{{ $currentRole ? ucfirst(str_replace('_', ' ', $currentRole)) : '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-semibold">Department</dt>
                <dd class="col-sm-8">{{ $user->departmentLabel() ?: '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-semibold">Status</dt>
                <dd class="col-sm-8">
                    <span class="badge {{ $isActive ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                </dd>
                <dt class="col-sm-4 text-muted fw-semibold">User ID</dt>
                <dd class="col-sm-8">#{{ $user->id }}</dd>
                <dt class="col-sm-4 text-muted fw-semibold">Joined Date</dt>
                <dd class="col-sm-8">{{ optional($user->created_at)->format('d M Y, h:i A') ?? '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-semibold">Last Login</dt>
                <dd class="col-sm-8">{{ $user->last_login_at ? $user->last_login_at->format('d M Y, h:i A') : 'Never' }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
