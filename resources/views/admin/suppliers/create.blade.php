@extends('layouts.app')

@section('title', 'Create Vendor')

@section('content')
<div class="container-fluid">

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-1">Create Vendor</h3>
                <p class="text-muted mb-0">Add supplier information for booking format.</p>
            </div>

            <a href="{{ route('admin.suppliers.index') }}" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">
            <form action="{{ route('admin.suppliers.store') }}" method="POST">
                @csrf

                @include('admin.suppliers._form')

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Save Vendor
                    </button>

                    <a href="{{ route('admin.suppliers.index') }}" class="btn btn-light">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection