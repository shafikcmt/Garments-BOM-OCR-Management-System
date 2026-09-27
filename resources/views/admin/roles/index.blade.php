@extends('layouts.app')

@section('title', 'Role Management')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Role Management'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin</div>
                    <h3 class="app-hero-title mb-0">Role Management</h3>
                </div>
            </div>
            <a href="{{ route('admin.roles.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Role
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Role Name</th>
                        <th>Guard</th>
                        <th>Department</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($roles as $role)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td class="fw-semibold">{{ ucfirst(str_replace('_', ' ', $role->name)) }}</td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">{{ $role->guard_name }}</span>
                            </td>
                            {{-- Read-only: which department this role belongs to. Changed
                                 only on Admin > Settings > Departments, never here. --}}
                            <td>
                                @php $departmentKey = \App\Support\DepartmentRoles::departmentOf($role->name); @endphp
                                @if($departmentKey)
                                    <a href="{{ route('admin.departments.index') }}#dept-{{ $departmentKey }}" class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle text-decoration-none" title="Open this department on the Departments screen">
                                        {{ \App\Support\DepartmentRoles::labelOf($departmentKey) }}
                                    </a>
                                @else
                                    <span class="text-muted small">Not assigned</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil-square" aria-hidden="true"></i><span class="ms-1">Edit</span></a>
                                    <form action="{{ route('admin.roles.destroy', $role) }}" method="POST" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button onclick="return confirm('Delete this role?')" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i><span class="ms-1">Delete</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No roles found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
