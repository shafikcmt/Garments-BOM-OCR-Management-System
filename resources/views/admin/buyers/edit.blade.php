@extends('layouts.app')

@section('title', 'Edit Buyer')

@section('content')
<div class="container-fluid">

    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Buyers', 'url' => route('admin.buyers.index')],
        ['label' => 'Edit Buyer'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Buyers</div>
                    <h3 class="app-hero-title mb-0">Edit Buyer</h3>
                </div>
            </div>
            <a href="{{ route('admin.buyers.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Buyers
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <form action="{{ route('admin.buyers.update', $buyer) }}" method="POST">
                @csrf
                @method('PUT')
                @include('admin.buyers._form', ['buyer' => $buyer])
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1" aria-hidden="true"></i> Update Buyer
                    </button>
                    <a href="{{ route('admin.buyers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
