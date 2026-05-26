@extends('layouts.app')

@section('title', 'Edit Role')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-shield-lock"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Roles</div>
                    <h3 class="app-hero-title mb-0">Edit Role</h3>
                </div>
            </div>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left"></i> Back to Roles
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;max-width:560px;">
        <div class="card-body p-4">
            <form action="{{ route('admin.roles.update', $role) }}" method="POST">
                @csrf
                @method('PUT')
                @include('admin.roles.form')
                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4">Update Role</button>
                    <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
