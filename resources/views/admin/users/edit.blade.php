@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
@php
    $avatarUrl = $user->avatarUrl();
    $isActive = (int) ($user->status ?? 1) === 1;
    $isSelf = $user->id === auth()->id();
    $currentRole = $user->getRoleNames()->first();
@endphp

<div class="container-fluid">
    <x-breadcrumb :items="[
        $rootCrumb,
        ['label' => 'Users'],
        ['label' => 'Edit User'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                @if($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="{{ $user->name }}" class="rounded-circle" style="width:56px;height:56px;object-fit:cover;border:2px solid #fff;box-shadow:0 4px 12px rgba(15,23,42,.12);">
                @else
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold" style="width:56px;height:56px;font-size:20px;">{{ $user->initials() }}</span>
                @endif
                <div>
                    <div class="app-hero-eyebrow">Admin / Users</div>
                    <h3 class="app-hero-title mb-0">{{ $user->name }}</h3>
                </div>
            </div>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Users
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger border-0 shadow-sm rounded-3">{{ session('error') }}</div>
    @endif

    @if($isSelf)
        <div class="alert alert-info border-0 shadow-sm rounded-3">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>You are editing your own account. Your role and status are locked to prevent accidental self-lockout.
        </div>
    @endif

    <div class="row g-4">
        {{-- Card 1: Profile Info (Admin Editable) --}}
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm h-100" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-1">Profile Information</h5>
                    <p class="text-muted small mb-4">Update name, email, role, status and photo.</p>

                    <form method="POST" action="{{ route('admin.users.update', $user) }}" enctype="multipart/form-data"
                          onsubmit="return (document.getElementById('statusSwitch') && !document.getElementById('statusSwitch').checked) ? confirm('Set this user as INACTIVE? They will not be able to log in.') : true;">
                        @csrf
                        @method('PUT')

                        <div class="d-flex align-items-center gap-3 mb-4">
                            @if($avatarUrl)
                                <img src="{{ $avatarUrl }}" alt="{{ $user->name }}" class="rounded-circle" style="width:72px;height:72px;object-fit:cover;border:1px solid #e5e9f2;">
                            @else
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold" style="width:72px;height:72px;font-size:24px;">{{ $user->initials() }}</span>
                            @endif
                            <div class="flex-grow-1">
                                <label class="form-label fw-semibold">Profile Photo</label>
                                <input type="file" name="profile_photo" accept="image/*" class="form-control @error('profile_photo') is-invalid @enderror">
                                <div class="form-text">JPG, PNG or WEBP. Max 2 MB.</div>
                                @error('profile_photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required maxlength="255">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required maxlength="255">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        @php($currentDepartment = old('department', \App\Support\DepartmentRoles::departmentOf($currentRole)))

                        {{-- Department leads, Role follows. Department is not
                             stored on the user — the role still decides it —
                             so this select exists to narrow the role list and
                             keep a Store user out of a Management role. --}}
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold" for="departmentSelect">Department</label>
                                <select name="department" id="departmentSelect"
                                        class="form-select js-department @error('department') is-invalid @enderror"
                                        required {{ $isSelf ? 'disabled' : '' }}>
                                    {{-- A department admin is offered their own
                                         department and nothing else, so there
                                         is no empty choice to leave sitting. --}}
                                    @if($scope->isSuperAdmin())
                                        <option value="">Select Department</option>
                                    @endif
                                    @foreach($departments as $key => $label)
                                        <option value="{{ $key }}"
                                            @selected($currentDepartment === $key || ! $scope->isSuperAdmin())>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @if($isSelf)
                                    <input type="hidden" name="department" value="{{ $currentDepartment }}">
                                @endif
                                @error('department')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text">Decides which roles are offered.</div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold" for="roleSelect">Role</label>
                                <select name="role" id="roleSelect"
                                        class="form-select js-role @error('role') is-invalid @enderror"
                                        required {{ $isSelf ? 'disabled' : '' }}>
                                    <option value="">Select Role</option>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->name }}"
                                                data-department="{{ $roleDepartments[$role->name] ?? \App\Support\DepartmentRoles::UNASSIGNED }}"
                                                @selected(old('role', $currentRole) === $role->name)>
                                            {{ \App\Support\DepartmentRoles::roleLabel($role->name) }}
                                        </option>
                                    @endforeach
                                </select>
                                @if($isSelf)
                                    <input type="hidden" name="role" value="{{ $currentRole }}">
                                @endif
                                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        @include('admin.users._department-roles')

                        <div class="mb-4">
                            <label class="form-label fw-semibold d-block">Status</label>
                            <input type="hidden" name="status" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="statusSwitch" name="status" value="1"
                                       {{ old('status', $user->status) ? 'checked' : '' }} {{ $isSelf ? 'disabled' : '' }}>
                                <label class="form-check-label" for="statusSwitch">Active</label>
                            </div>
                            @if($isSelf)
                                <input type="hidden" name="status" value="1">
                            @endif
                        </div>

                        {{-- Department Admin — a promotion, not an access
                             setting. Super admin only, and never on your own
                             account, for the same reason your role and status
                             are locked above.

                             The hidden marker says the control was on the page:
                             an unticked checkbox posts nothing, so without it a
                             save from a form that never showed this field would
                             read as "untick it" and quietly demote someone. --}}
                        @if($scope->maySetDepartmentAdminFlag() && ! $isSelf)
                            <div class="mb-4">
                                <label class="form-label fw-semibold d-block">Department Admin</label>
                                <input type="hidden" name="department_admin_control" value="1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="departmentAdminSwitch" name="is_department_admin" value="1"
                                           @checked(old('is_department_admin', $user->is_department_admin))>
                                    <label class="form-check-label" for="departmentAdminSwitch">
                                        Can manage users in their own department
                                    </label>
                                </div>
                                <div class="form-text">
                                    Lets this person create and manage users of their own department only,
                                    and grant them permissions they already hold themselves. Only you can set this.
                                </div>
                            </div>
                        @endif

                        {{-- Buyer assignment, shown but never edited here. The
                             owning column lives on the buyer, so Admin > Buyers
                             is the one screen that sets it; showing it read-only
                             answers "which buyer is this person on" where you
                             would look for it.

                             A department admin with no buyer yet gets a pointer
                             to that screen rather than blank space: ticking the
                             Department Admin flag is only half the setup, and
                             an empty section reads as "nothing to do here". A
                             normal user without a buyer still shows nothing —
                             they inherit one at creation and have nothing to
                             act on. --}}
                        @if($assignedBuyer)
                            <div class="mb-4">
                                <label class="form-label fw-semibold d-block">Assigned Buyer</label>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                    <i class="bi bi-tag-fill me-1" aria-hidden="true"></i>{{ $assignedBuyer->buyer_name }}
                                </span>
                                <div class="form-text">
                                    @if($user->isDepartmentAdmin())
                                        Owns this buyer. Change it from
                                        <a href="{{ route('admin.buyers.index') }}">Admin &rsaquo; Buyers</a>.
                                    @else
                                        Inherited from the department admin who created this account.
                                    @endif
                                </div>
                            </div>
                        @elseif($user->isDepartmentAdmin())
                            <div class="mb-4">
                                <label class="form-label fw-semibold d-block">Assigned Buyer</label>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                    <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Not assigned yet
                                </span>
                                <div class="form-text">
                                    This department admin owns no buyer yet — assign one from
                                    <a href="{{ route('admin.buyers.index') }}">Admin &rsaquo; Buyers</a>.
                                </div>
                            </div>
                        @endif

                        {{-- BOM upload override — Merchandising only, and the
                             one control on this form that belongs to a
                             department admin rather than a super admin. A
                             merchant scoped to a buyer does not upload unless
                             the admin who owns that buyer says so.

                             Same hidden-marker reasoning as above: the control
                             is absent for everyone else, so its absence must
                             read as "not shown", never as "untick it". --}}
                        @can('setCanUpload', $user)
                            <div class="mb-4">
                                <label class="form-label fw-semibold d-block">BOM Upload</label>
                                <input type="hidden" name="can_upload_control" value="1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="canUploadSwitch" name="can_upload" value="1"
                                           @checked(old('can_upload', $user->can_upload))>
                                    <label class="form-check-label" for="canUploadSwitch">
                                        Can upload BOM files for {{ $user->buyer?->buyer_name }}
                                    </label>
                                </div>
                                <div class="form-text">
                                    Off by default. Turn this on for team members who prepare and upload
                                    BOM files themselves. It applies to your buyer only.
                                </div>
                            </div>
                        @endcan

                        {{-- Additional permissions, saved by this same form so
                             role and permissions can never be posted apart. --}}
                        <div class="border-top pt-4 mb-4">
                            <h6 class="mb-1">Additional Permissions</h6>
                            <p class="text-muted small mb-3">
                                @if($isSelf)
                                    Your own permissions are locked here, for the same reason your
                                    role and status are.
                                @else
                                    The role above already grants the greyed ticks. Tick anything
                                    extra this person needs — it is saved against this user only.
                                @endif
                            </p>

                            @include('admin.users._permission-matrix', [
                                'selectedRole' => old('role', $currentRole),
                                'readonly' => $isSelf,
                            ])
                        </div>

                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Profile</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            {{-- Card 2: Password Control --}}
            <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-1">Password Control</h5>
                    <p class="text-muted small mb-4">Set a new password directly, or email a reset link.</p>

                    <form method="POST" action="{{ route('admin.users.reset-password', $user) }}" class="mb-4">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="new_password" autocomplete="new-password" class="form-control @error('new_password') is-invalid @enderror">
                            @error('new_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="new_password_confirmation" autocomplete="new-password" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-key me-1" aria-hidden="true"></i>Set Password</button>
                    </form>

                    <hr>

                    <form method="POST" action="{{ route('admin.users.send-reset-link', $user) }}"
                          onsubmit="return confirm('Send a password reset email to {{ $user->email }}?');">
                        @csrf
                        <p class="small text-muted mb-2">Send a self-service reset link to the user's email.</p>
                        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Send Password Reset Email</button>
                    </form>
                </div>
            </div>

            {{-- Card 3: Account Info (Read-only) --}}
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Account Info</h5>
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted fw-semibold">User ID</dt>
                        <dd class="col-7">#{{ $user->id }}</dd>
                        <dt class="col-5 text-muted fw-semibold">Joined Date</dt>
                        <dd class="col-7">{{ optional($user->created_at)->format('d M Y, h:i A') ?? '—' }}</dd>
                        <dt class="col-5 text-muted fw-semibold">Last Login</dt>
                        <dd class="col-7">{{ $user->last_login_at ? $user->last_login_at->format('d M Y, h:i A') : 'Never' }}</dd>
                        <dt class="col-5 text-muted fw-semibold">Status</dt>
                        <dd class="col-7">
                            <span class="badge {{ $isActive ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
