@extends('layouts.app')

@section('title', 'User Management')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'User Management'],
    ]" />

    <x-page-header icon="people" eyebrow="Admin" title="User Management"
                   copy="Accounts, the role each one holds, and who is signed in right now.">
        <x-slot:actions>
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Roles</a>
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-person-plus" aria-hidden="true"></i>Add User
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:0ms"
                icon="people" tone="primary" label="Total users" :value="$stats['total']" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:100ms"
                icon="check2-circle" tone="success" label="Active" :value="$stats['active']" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:200ms"
                icon="pause-circle" tone="warning" label="Inactive" :value="$stats['inactive']" />
        </div>
        <div class="col-6 col-xl-3">
            {{-- "Never signed in" replaces the requested pending-invite count:
                 there is no invite system, but an account nobody has ever used
                 is the same thing worth chasing. --}}
            <x-stat-card class="gx-fade-in h-100" style="--gx-delay:300ms"
                icon="hourglass" tone="danger" label="Never signed in" :value="$stats['never_signed_in']" />
        </div>
    </div>

    <x-card class="gx-fade-in mb-4" style="--gx-delay:400ms" data-user-table>
        <x-slot:title>Users <span class="badge bg-primary-subtle text-primary ms-1">{{ $stats['total'] }}</span></x-slot:title>

        <div class="row g-2 align-items-end mb-3">
            <div class="col-12 col-lg-5">
                <label class="form-label small fw-semibold mb-1" for="userSearch">Search</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input type="search" id="userSearch" class="form-control" data-user-search
                           placeholder="Name or email…" autocomplete="off">
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small fw-semibold mb-1" for="userRole">Role</label>
                <select id="userRole" class="form-select" data-user-role>
                    <option value="">All roles</option>
                    @foreach($roleCounts as $role)
                        <option value="{{ $role->name }}">{{ Str::headline($role->name) }} ({{ $role->users_count }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small fw-semibold mb-1" for="userStatus">Status</label>
                <select id="userStatus" class="form-select" data-user-status>
                    <option value="">All</option>
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
            <div class="col-12 col-lg-2">
                <button type="button" class="btn btn-outline-secondary w-100" data-user-clear>Clear</button>
            </div>
        </div>

        <div class="small text-muted mb-2" data-user-count aria-live="polite"></div>

        <div class="table-responsive gx-table-scroll">
            <table class="table align-middle mb-0 gx-file-table">
                <thead>
                    <tr class="text-muted small text-uppercase">
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last signed in</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        @php
                            $roleName = $user->roles->first()->name ?? null;
                            $isActive = (int) $user->status === 1;
                        @endphp
                        <tr data-user-row
                            data-role="{{ $roleName }}"
                            data-status="{{ $isActive ? 1 : 0 }}"
                            data-search="{{ strtolower($user->name.' '.$user->email) }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    @if($user->avatarUrl())
                                        <img src="{{ $user->avatarUrl() }}" alt="" class="rounded-3" style="width:34px;height:34px;object-fit:cover;">
                                    @else
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold"
                                              style="width:34px;height:34px;font-size:12px;">{{ $user->initials() }}</span>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate">{{ $user->name }}</div>
                                        <div class="small text-muted text-truncate">{{ $user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($roleName)
                                    <span class="badge bg-primary-subtle text-primary">{{ Str::headline($roleName) }}</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">No role</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                    {{ $isActive ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="small">
                                @if($user->last_login_at)
                                    <span title="{{ $user->last_login_at }}">{{ $user->last_login_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-secondary" title="View"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil" aria-hidden="true"></i></a>

                                <button type="button" class="btn btn-sm btn-outline-warning" title="Reset password"
                                        data-bs-toggle="modal" data-bs-target="#resetPw{{ $user->id }}">
                                    <i class="bi bi-key" aria-hidden="true"></i>
                                </button>

                                @if($user->id !== auth()->id())
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline"
                                          data-delete-user="{{ $user->id }}"
                                          onsubmit="return confirm('Delete {{ $user->name }}? This cannot be undone.');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-5">No users yet.</td></tr>
                    @endforelse

                    <tr class="d-none" data-user-no-match>
                        <td colspan="5" class="text-center text-muted py-4">No user matches the current filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-card>

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            {{-- Read-only. These permissions already gate features through
                 @can(), but nothing has ever displayed them, so an admin had to
                 query the database to see who can do what. Editing them changes
                 access for everyone and is deliberately a separate step. --}}
            <x-card class="gx-fade-in h-100" style="--gx-delay:500ms">
                <x-slot:title>Role permissions <span class="badge bg-secondary-subtle text-secondary ms-1">Read-only</span></x-slot:title>

                <div class="table-responsive gx-table-scroll">
                    <table class="table table-sm align-middle mb-0 gx-file-table">
                        <thead>
                            <tr class="text-muted text-uppercase" style="font-size:10px;">
                                <th>Permission</th>
                                @foreach($permissionMatrix['roles'] as $role)
                                    <th class="text-center" title="{{ $role->name }}">{{ Str::of($role->name)->headline()->substr(0, 4) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($permissionMatrix['permissions'] as $permission)
                                <tr>
                                    <td class="small">{{ $permission->name }}</td>
                                    @foreach($permissionMatrix['roles'] as $role)
                                        <td class="text-center">
                                            @if($role->permissions->contains('id', $permission->id))
                                                <i class="bi bi-check-lg text-success" title="{{ $role->name }} can {{ $permission->name }}"></i>
                                                <span class="visually-hidden">{{ $role->name }} has {{ $permission->name }}</span>
                                            @else
                                                <span class="text-muted" aria-hidden="true">·</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="col-12 col-xl-5">
            {{-- There is no login-history table, but the database session
                 driver records who is signed in, from where, and on what. --}}
            <x-card class="gx-fade-in h-100" style="--gx-delay:600ms" title="Currently signed in">
                @forelse($sessions as $session)
                    <div class="d-flex align-items-start justify-content-between gap-2 py-2 border-bottom">
                        <div class="min-w-0">
                            <div class="fw-semibold small text-truncate">{{ $session['user']->name }}</div>
                            <div class="small text-muted text-truncate" title="{{ $session['agent'] }}">
                                {{ $session['ip'] }} · {{ Str::limit($session['agent'], 34) }}
                            </div>
                        </div>
                        <div class="small text-muted text-nowrap">
                            {{ $session['last_activity']?->diffForHumans() }}
                        </div>
                    </div>
                @empty
                    <p class="text-muted small mb-0">No active sessions recorded.</p>
                @endforelse
            </x-card>
        </div>
    </div>
</div>

@foreach($users as $user)
    <div class="modal fade" id="resetPw{{ $user->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content gx-card">
                <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
                    @csrf @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title">Reset password</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Set a new password for <strong>{{ $user->name }}</strong> ({{ $user->email }}).</p>
                        <label class="form-label fw-semibold" for="pw{{ $user->id }}">New password</label>
                        <input type="password" id="pw{{ $user->id }}" name="password" class="form-control" required minlength="6" autocomplete="new-password">
                        <div class="form-text">Minimum 6 characters. Share it with the user directly.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Reset password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach
@endsection
