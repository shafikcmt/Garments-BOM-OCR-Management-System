@extends('layouts.app')

@section('title', 'Profile Settings')

@section('content')
@php
    $avatarUrl = $user->avatarUrl();
    $isActive = (int) ($user->status ?? 1) === 1;
@endphp

<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="flex-shrink-0">
                @if($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="{{ $user->name }}"
                         class="rounded-circle" style="width:64px;height:64px;object-fit:cover;border:2px solid #fff;box-shadow:0 4px 12px rgba(15,23,42,.12);">
                @else
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold"
                          style="width:64px;height:64px;font-size:22px;letter-spacing:1px;">{{ $user->initials() }}</span>
                @endif
            </div>
            <div class="min-w-0">
                <div class="app-hero-eyebrow">My Account</div>
                <h3 class="app-hero-title mb-1">{{ $user->name }}</h3>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    @forelse($roleLabels as $label)
                        <span class="badge bg-primary-subtle text-primary-emphasis">{{ $label }}</span>
                    @empty
                        <span class="badge bg-secondary-subtle text-secondary-emphasis">No Role</span>
                    @endforelse
                    <span class="badge {{ $isActive ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                        {{ $isActive ? 'Active' : 'Inactive' }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    @if(session('status') === 'profile-updated')
        <div class="alert alert-success border-0 shadow-sm rounded-3">Profile updated successfully.</div>
    @endif
    @if(session('status') === 'password-updated')
        <div class="alert alert-success border-0 shadow-sm rounded-3">Password changed successfully.</div>
    @endif

    <div class="row g-4">
        {{-- Card 1: Profile Information --}}
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-1">Profile Information</h5>
                    <p class="text-muted small mb-4">Update your name, email and profile photo.</p>

                    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PATCH')

                        <div class="d-flex align-items-center gap-3 mb-4">
                            @if($avatarUrl)
                                <img src="{{ $avatarUrl }}" alt="{{ $user->name }}"
                                     class="rounded-circle" style="width:72px;height:72px;object-fit:cover;border:1px solid #e5e9f2;">
                            @else
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold"
                                      style="width:72px;height:72px;font-size:24px;">{{ $user->initials() }}</span>
                            @endif
                            <div class="flex-grow-1">
                                <label class="form-label fw-semibold">Profile Photo</label>
                                <input type="file" name="profile_photo" accept="image/*"
                                       class="form-control @error('profile_photo') is-invalid @enderror">
                                <div class="form-text">JPG, PNG or WEBP. Max 2 MB.</div>
                                @error('profile_photo')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}" required maxlength="255">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $user->email) }}" required maxlength="255">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold text-muted">Role</label>
                                <input type="text" class="form-control bg-light" value="{{ $roleLabels->implode(', ') ?: '—' }}" readonly>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold text-muted">Department</label>
                                <input type="text" class="form-control bg-light" value="{{ $roleLabels->implode(', ') ?: '—' }}" readonly>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold text-muted">Status</label>
                                <input type="text" class="form-control bg-light" value="{{ $isActive ? 'Active' : 'Inactive' }}" readonly>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold text-muted">Joined Date</label>
                                <input type="text" class="form-control bg-light"
                                       value="{{ optional($user->created_at)->format('d M Y') ?? '—' }}" readonly>
                            </div>
                        </div>
                        <p class="text-muted small mb-3"><i class="bi bi-info-circle me-1"></i>Role, Department and Status are managed by the administrator.</p>

                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Profile</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Card 2: Change Password --}}
        <div class="col-12 col-xl-5">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-1">Change Password</h5>
                    <p class="text-muted small mb-4">Use a strong password you don't use elsewhere.</p>

                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password" autocomplete="current-password"
                                   class="form-control @error('current_password', 'updatePassword') is-invalid @enderror">
                            @error('current_password', 'updatePassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="password" autocomplete="new-password"
                                   class="form-control @error('password', 'updatePassword') is-invalid @enderror">
                            @error('password', 'updatePassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="password_confirmation" autocomplete="new-password"
                                   class="form-control">
                        </div>

                        <button type="submit" class="btn btn-outline-primary px-4"><i class="bi bi-shield-lock me-1"></i>Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
