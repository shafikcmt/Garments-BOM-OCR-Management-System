@extends('layouts.app')

@section('title', 'Create User')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        $rootCrumb,
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

                {{-- Which of this admin's own buyers the new person works on.

                     Only shown when the admin holds more than one — with a
                     single buyer there is nothing to decide and it is passed on
                     silently, exactly as it always has been.

                     The list is the admin's own buyers and no others. That is
                     the honest-mistake half of the subset rule; the other half
                     is Rule::in on the server, which refuses a buyer outside
                     this set whatever the form was made to post. --}}
                @if($assignableBuyers->count() > 1)
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block mb-1">
                            Assigned Buyers <span class="text-danger">*</span>
                        </label>
                        <div class="form-text mb-2">
                            Choose which of your buyers this person works on. They will be able to
                            edit files for the buyers you tick, and no others.
                        </div>

                        @foreach($assignableBuyers as $buyer)
                            <div class="form-check">
                                <input class="form-check-input @error('buyer_ids') is-invalid @enderror"
                                       type="checkbox" name="buyer_ids[]" value="{{ $buyer->id }}"
                                       id="assignBuyer{{ $buyer->id }}"
                                       {{ in_array((string) $buyer->id, (array) old('buyer_ids', []), true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="assignBuyer{{ $buyer->id }}">
                                    {{ $buyer->buyer_name }}{{ $buyer->buyer_code ? ' (' . $buyer->buyer_code . ')' : '' }}
                                </label>
                            </div>
                        @endforeach

                        @error('buyer_ids')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        @error('buyer_ids.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                @endif
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
