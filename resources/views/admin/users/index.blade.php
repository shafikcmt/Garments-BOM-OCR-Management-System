@extends('layouts.app')

@section('title', 'User Management')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'User Management'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-people"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin</div>
                    <h3 class="app-hero-title mb-0">User Management</h3>
                </div>
            </div>
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg"></i> Add User
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @if($user->getRoleNames()->first())
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ ucfirst(str_replace('_', ' ', $user->getRoleNames()->first())) }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($user->status)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-secondary" title="View profile"><i class="bi bi-eye"></i><span class="ms-1">View</span></a>
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-warning" title="Edit user"><i class="bi bi-pencil-square"></i><span class="ms-1">Edit</span></a>
                                    @if($user->id !== auth()->id())
                                        <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button onclick="return confirm('Delete this user?')" class="btn btn-sm btn-outline-danger" title="Delete user"><i class="bi bi-trash"></i><span class="ms-1">Delete</span></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection