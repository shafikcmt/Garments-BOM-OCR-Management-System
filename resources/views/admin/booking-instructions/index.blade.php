@extends('layouts.app')

@section('title', 'Booking Instructions')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-4">{{ session('success') }}</div>
    @endif

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-card-list"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Booking Setup</div>
                    <h3 class="app-hero-title mb-0">Booking Instructions</h3>
                </div>
            </div>
            <a href="{{ route('admin.booking-instructions.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg"></i> Add Instruction
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted mb-1">Total</div>
                    <div class="fs-4 fw-bold">{{ $stats['total'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted mb-1">Default (Auto Added)</div>
                    <div class="fs-4 fw-bold">{{ $stats['default'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted mb-1">Suggestions</div>
                    <div class="fs-4 fw-bold">{{ $stats['suggested'] ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="small text-muted mb-1">Active</div>
                    <div class="fs-4 fw-bold">{{ $stats['active'] ?? 0 }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info border-0 shadow-sm rounded-4 mb-4">
        <div class="d-flex gap-2">
            <i class="bi bi-info-circle mt-1 flex-shrink-0"></i>
            <div class="small"><strong>Default</strong> instructions appear automatically in every new booking. <strong>Suggestions</strong> stay in the dropdown for Supply Chain users to add when needed.</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Instruction</th>
                        <th style="width:140px;">Type</th>
                        <th style="width:90px;">Sort</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:110px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($instructions as $instruction)
                        <tr>
                            <td>{{ $loop->iteration + ($instructions->currentPage() - 1) * $instructions->perPage() }}</td>
                            <td class="small">{!! nl2br(e($instruction->instruction)) !!}</td>
                            <td>
                                @if($instruction->is_default)
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Default</span>
                                @else
                                    <span class="badge bg-info-subtle text-info border border-info-subtle">Suggestion</span>
                                @endif
                            </td>
                            <td>{{ $instruction->sort_order }}</td>
                            <td>
                                @if($instruction->is_active)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('admin.booking-instructions.edit', $instruction) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil-square"></i><span class="ms-1">Edit</span></a>
                                    <form action="{{ route('admin.booking-instructions.destroy', $instruction) }}" method="POST" onsubmit="return confirm('Delete this instruction?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i><span class="ms-1">Delete</span></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No booking instruction found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-3">
                {{ $instructions->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
