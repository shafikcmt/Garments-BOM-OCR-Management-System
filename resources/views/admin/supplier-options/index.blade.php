@extends('layouts.app')

@section('title', 'Vendor Options')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-4">{{ session('success') }}</div>
    @endif

    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Booking Setup'],
        ['label' => 'Vendor Options'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-list-check" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Admin / Booking Setup</div>
                <h3 class="app-hero-title mb-0">Vendor Options</h3>
                <div class="small text-muted">Choices shown on the vendor form and booking PO. Deleting one does not change vendors that already use it.</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        @foreach($types as $type => $title)
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent fw-semibold">{{ $title }}</div>
                    <div class="card-body">
                        <form action="{{ route('admin.supplier-options.store') }}" method="POST" class="d-flex gap-2 mb-3">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <input type="text" name="name" maxlength="100" required placeholder="New {{ \Illuminate\Support\Str::singular(strtolower($title)) }}"
                                   class="form-control form-control-sm @if(old('type') === $type) @error('name') is-invalid @enderror @endif"
                                   value="{{ old('type') === $type ? old('name') : '' }}">
                            <button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add</button>
                        </form>
                        @if(old('type') === $type)
                            @error('name') <div class="text-danger small mb-2">{{ $message }}</div> @enderror
                        @endif

                        <ul class="list-group list-group-flush">
                            @forelse($options->get($type, collect()) as $option)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span>{{ $option->name }}</span>
                                    <form action="{{ route('admin.supplier-options.destroy', $option) }}" method="POST" onsubmit="return confirm('Delete {{ e($option->name) }}? Vendors using it keep their value.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                    </form>
                                </li>
                            @empty
                                <li class="list-group-item px-0 text-muted">Nothing added yet.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
