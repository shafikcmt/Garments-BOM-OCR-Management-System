@extends('layouts.app')

@section('title', 'Booking Delivery Destination Control')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h3 class="mb-1">Booking Delivery Destination Control</h3>
                <p class="text-muted mb-0">Create and manage optional delivery / ship-to details for booking preview.</p>
            </div>
            <a href="{{ route('admin.booking-delivery-destinations.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i>Add Destination
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px;">SL</th>
                        <th>Title</th>
                        <th>Details</th>
                        <th style="width:110px;">Sort</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:170px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($destinations as $destination)
                        <tr>
                            <td>{{ $loop->iteration + ($destinations->currentPage() - 1) * $destinations->perPage() }}</td>
                            <td><strong>{{ $destination->title }}</strong></td>
                            <td>{!! nl2br(e(\Illuminate\Support\Str::limit($destination->details, 220))) !!}</td>
                            <td>{{ $destination->sort_order }}</td>
                            <td>
                                @if($destination->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-danger">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.booking-delivery-destinations.edit', $destination) }}" class="btn btn-sm btn-warning">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <form action="{{ route('admin.booking-delivery-destinations.destroy', $destination) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this destination?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No delivery destination found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-3">
                {{ $destinations->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
