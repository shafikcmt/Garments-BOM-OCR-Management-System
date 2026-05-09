@extends('layouts.app')

@section('title', 'Admin Workspace')

@section('content')
<div class="container-fluid">
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h3 class="mb-1">Workspace Control</h3>
            <p class="text-muted mb-0">View, open, edit and delete uploaded excel files from here.</p>
        </div>
    </div>

    @include('partials.excel-files-table', ['files' => $files])
</div>
@endsection