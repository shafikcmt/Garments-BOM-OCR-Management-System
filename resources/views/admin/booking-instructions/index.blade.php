@extends('layouts.app')

@section('title', 'Booking Instruction Control')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">Booking Instruction Control</h3>
                <p class="text-muted mb-0">Admin can add, edit or delete default booking instructions and extra suggestions for Supply Chain users.</p>
            </div>
            <a href="{{ route('admin.booking-instructions.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>Add Instruction
            </a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted">Total Instructions</small>
                    <h3 class="mb-0">{{ $stats['total'] ?? 0 }}</h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted">Default Auto Added</small>
                    <h3 class="mb-0">{{ $stats['default'] ?? 0 }}</h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted">Extra Suggestions</small>
                    <h3 class="mb-0">{{ $stats['suggested'] ?? 0 }}</h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <small class="text-muted">Active</small>
                    <h3 class="mb-0">{{ $stats['active'] ?? 0 }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info border-0 shadow-sm">
        <div class="d-flex gap-2">
            <i class="bi bi-info-circle mt-1"></i>
            <div>
                <strong>How it works:</strong>
                <span>Default instructions automatically appear in every new booking format. Extra suggestions stay in the Supply Chain booking preview dropdown so the user can add them only when needed. Supply Chain users can still add one-time custom instruction lines in the booking format.</span>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px;">SL</th>
                        <th>Instruction</th>
                        <th style="width:150px;">Use</th>
                        <th style="width:110px;">Sort</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:170px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($instructions as $instruction)
                        <tr>
                            <td>{{ $loop->iteration + ($instructions->currentPage() - 1) * $instructions->perPage() }}</td>
                            <td>{!! nl2br(e($instruction->instruction)) !!}</td>
                            <td>
                                @if($instruction->is_default)
                                    <span class="badge bg-primary">Default</span>
                                    <div class="small text-muted mt-1">Auto added</div>
                                @else
                                    <span class="badge bg-info text-dark">Suggestion</span>
                                    <div class="small text-muted mt-1">User selectable</div>
                                @endif
                            </td>
                            <td>{{ $instruction->sort_order }}</td>
                            <td>
                                @if($instruction->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-danger">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.booking-instructions.edit', $instruction) }}" class="btn btn-sm btn-warning" title="Edit">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <form action="{{ route('admin.booking-instructions.destroy', $instruction) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this booking instruction?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
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
