@extends('layouts.app')

@section('title', 'Store Workspace')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success rounded-4 border-0 shadow-sm">{{ session('success') }}</div>
    @endif

    @if(session('warning'))
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">{{ session('warning') }}</div>
    @endif

    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'Workspace'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:48px;height:48px;border-radius:17px;font-size:20px;"><i class="bi bi-shop"></i></span>
            <div>
                <div class="app-hero-eyebrow">Workspace</div>
                <h3 class="app-hero-title mb-1">Store Workspace</h3>
                <p class="app-hero-copy mb-0">Open uploaded files and update store related fields.</p>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link active" type="button"><i class="bi bi-folder2-open me-1"></i>Uploaded Files</button>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @include('partials.excel-files-table', ['files' => $files])
        </div>
    </div>
</div>
@endsection
