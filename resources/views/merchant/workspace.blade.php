@extends('layouts.app')

@section('title', 'Merchant Workspace')

@section('content')
@php
    $activeTab = request('tab', 'upload');
    // "create" tab removed from UI; fall back to upload if requested.
    if ($activeTab === 'create') {
        $activeTab = 'upload';
    }
@endphp

<style>
    .merchant-workspace-page .workspace-hero {
        border: 1px solid #e9eef6;
        border-radius:var(--gx-radius);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        background: linear-gradient(180deg, #ffffff, #fbfdff);
    }

    .merchant-workspace-page .workspace-card {
        border: 1px solid #e9eef6;
        border-radius:var(--gx-radius);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
    }

    .merchant-workspace-page .nav-tabs .nav-link {
        border-radius: 10px 10px 0 0;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .merchant-workspace-page .hint-box {
        background: #f8fbff;
        border: 1px solid #dceafe;
        color: #315174;
        border-radius: 12px;
        padding: 10px 12px;
        font-size: 0.86rem;
    }
</style>

<div class="container-fluid merchant-workspace-page">


    @if(session('success'))
        <div class="alert alert-success rounded-4 border-0 shadow-sm">{{ session('success') }}</div>
    @endif

    @if(session('warning'))
        <div class="alert alert-warning rounded-4 border-0 shadow-sm">{{ session('warning') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger mb-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card workspace-hero border-0 mb-3">
        <div class="card-body py-3">
            <h3 class="mb-1">Merchant Workspace</h3>
            <p class="text-muted mb-0">Excel upload and uploaded file management.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="merchantWorkspaceTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'upload' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#merchant-upload-tab" type="button">
                New Excel File Upload
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'files' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#merchant-files-tab" type="button">
                Existing Files
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade {{ $activeTab === 'upload' ? 'show active' : '' }}" id="merchant-upload-tab">
            <div class="card workspace-card border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="mb-1">Upload Excel File</h5>
                            <p class="text-muted mb-0 small">Upload only merchant input headers. Formula fields are calculated automatically.</p>
                        </div>
                        <a href="{{ route('merchant.excel.sample') }}" class="btn btn-outline-primary btn-sm">
                            Download Sample
                        </a>
                    </div>

                    <form method="POST" action="{{ route('merchant.excel.store') }}" enctype="multipart/form-data">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label">Select Excel File</label>
                            <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                            <small class="text-muted">Only merchant input headers are accepted. Formula fields calculate automatically.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Optional remarks" value="{{ old('remarks') }}">
                        </div>

                        <button type="submit" class="btn btn-primary">Upload File</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'files' ? 'show active' : '' }}" id="merchant-files-tab">
            @include('partials.excel-files-table', ['files' => $files])
        </div>
    </div>
</div>

@include('merchant.partials.upload-loading')
@endsection
