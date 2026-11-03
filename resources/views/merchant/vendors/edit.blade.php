@extends('layouts.app')

@section('title', 'Edit Vendor')

@section('content')
<div class="container-fluid">
    <x-page-header icon="buildings" eyebrow="Merchandising" title="Edit Vendor"
                   :copy="$supplier->supplier_name">
        <x-slot:actions>
            <a href="{{ route('merchant.vendors.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Vendors
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <form action="{{ route('merchant.vendors.update', $supplier) }}" method="POST">
                @csrf
                @method('PUT')
                @include('admin.suppliers._form')
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>Save Changes
                    </button>
                    <a href="{{ route('merchant.vendors.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
