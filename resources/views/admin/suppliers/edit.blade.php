@extends('layouts.app')

@section('title', 'Edit Vendor')

@section('content')
<div class="container-fluid">

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-buildings"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Vendors</div>
                    <h3 class="app-hero-title mb-0">Edit Vendor</h3>
                </div>
            </div>
            <a href="{{ route('admin.suppliers.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left"></i> Back to Vendors
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-4">
            <form action="{{ route('admin.suppliers.update', $supplier) }}" method="POST">
                @csrf
                @method('PUT')
                @include('admin.suppliers._form', ['supplier' => $supplier])
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Update Vendor
                    </button>
                    <a href="{{ route('admin.suppliers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection