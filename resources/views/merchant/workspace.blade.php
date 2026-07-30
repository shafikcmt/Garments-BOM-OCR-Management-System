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

<div class="container-fluid merchant-workspace-page">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.dashboard')],
        ['label' => 'Workspace'],
    ]" />

    <x-page-header data-aos="fade-down" icon="layout-text-window-reverse" eyebrow="Merchandising"
                   title="Merchant Workspace"
                   copy="Upload merchant input files and manage the ones already in the workspace." />

    <x-flash />

    {{-- Plain nav-tabs: components.css already gives them the app's pill look,
         which every other workspace uses. A second tab style would be the
         inconsistency, not the fix. --}}
    <ul class="nav nav-tabs mb-3" id="merchantWorkspaceTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'upload' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#merchant-upload-tab" type="button">
                <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>New Excel File Upload
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'files' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#merchant-files-tab" type="button">
                <i class="bi bi-folder2-open" aria-hidden="true"></i>Existing Files
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade {{ $activeTab === 'upload' ? 'show active' : '' }}" id="merchant-upload-tab">
            <x-card class="mb-3">
                <x-slot:title>
                    Upload Excel File
                    <span class="d-block fw-normal text-muted small mt-1">Upload only merchant input headers. Formula fields are calculated automatically.</span>
                </x-slot:title>
                <x-slot:actions>
                    <a href="{{ route('merchant.excel.sample') }}" class="btn btn-outline-primary btn-sm">
                        Download Sample
                    </a>
                </x-slot:actions>

                @error('file')
                    <div class="alert alert-danger py-2 small">{{ $message }}</div>
                @enderror

                {{-- The limit shown is the real PHP ceiling, so the number
                     cannot drift away from what the server will accept. --}}
                <x-file-upload
                    :action="route('merchant.excel.store')"
                    name="file"
                    accept=".xlsx,.xls,.csv"
                    :max-mb="(int) min(
                        (int) filter_var(ini_get('upload_max_filesize'), FILTER_SANITIZE_NUMBER_INT),
                        (int) filter_var(ini_get('post_max_size'), FILTER_SANITIZE_NUMBER_INT)
                    )"
                    hint="Excel or CSV — merchant input headers only">
                    <div class="mt-3">
                        <label class="form-label" for="merchantUploadRemarks">Remarks <span class="text-muted small fw-normal">(optional)</span></label>
                        <input type="text" name="remarks" id="merchantUploadRemarks" class="form-control"
                               placeholder="Anything worth noting about this file" value="{{ old('remarks') }}">
                        <div class="form-text">Formula fields are calculated automatically after upload.</div>
                    </div>
                </x-file-upload>
            </x-card>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'files' ? 'show active' : '' }}" id="merchant-files-tab">
            @include('partials.excel-files-table', ['files' => $files])
        </div>
    </div>
</div>

@include('merchant.partials.upload-loading')
@endsection
