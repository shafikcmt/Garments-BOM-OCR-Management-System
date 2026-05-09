@extends('layouts.app')

@section('title', 'Store Workspace')

@section('content')
<div class="container-fluid">
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h3 class="mb-1">Store Workspace</h3>
            <p class="text-muted mb-0">Open uploaded files and update store related fields.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button class="nav-link active" type="button">Uploaded Files</button>
        </li>
    </ul>

    @include('partials.excel-files-table', ['files' => $files])
</div>
@endsection