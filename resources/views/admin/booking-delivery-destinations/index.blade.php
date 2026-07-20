@extends('layouts.app')

@section('title', 'Delivery Destinations')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-4">{{ session('success') }}</div>
    @endif

    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Booking Setup'],
        ['label' => 'Delivery Destinations'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-geo-alt" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Booking Setup</div>
                    <h3 class="app-hero-title mb-0">Delivery Destinations</h3>
                </div>
            </div>
            <a href="{{ route('admin.booking-delivery-destinations.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Destination
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Title</th>
                        <th>Details</th>
                        <th style="width:90px;">Sort</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:110px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($destinations as $destination)
                        <tr>
                            <td>{{ $loop->iteration + ($destinations->currentPage() - 1) * $destinations->perPage() }}</td>
                            <td class="fw-semibold">{{ $destination->title }}</td>
                            <td class="text-muted small">{!! nl2br(e(\Illuminate\Support\Str::limit($destination->details, 220))) !!}</td>
                            <td>{{ $destination->sort_order }}</td>
                            <td>
                                @if($destination->is_active)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Inactive</span>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="{{ route('admin.booking-delivery-destinations.edit', $destination) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil-square" aria-hidden="true"></i><span class="ms-1">Edit</span></a>
                                    <form action="{{ route('admin.booking-delivery-destinations.destroy', $destination) }}" method="POST" onsubmit="return confirm('Delete this destination?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i><span class="ms-1">Delete</span></button>
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
