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

    {{-- Segmented rather than the app's pill tabs. These two are not two
         destinations, they are two modes of one workspace — upload something
         new, or work on what is already here — and the pill style says that
         with a pale blue tint next to a white tab, which is a weak signal on a
         white page. The segmented track raises the active tab out of a
         recessed background instead, so the current mode is legible without
         reading the labels. .gx-segmented is a scoped modifier; nav-tabs
         elsewhere in the app are untouched. --}}
    <ul class="nav nav-tabs gx-segmented mb-3" id="merchantWorkspaceTab" role="tablist">
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

                @error('buyer_id')
                    <div class="alert alert-danger py-2 small">{{ $message }}</div>
                @enderror

                @unless($canUpload)
                    {{-- Buyer-scoped user without the upload override. The form
                         is not rendered at all; ExcelUploadRequest::authorize()
                         is what actually refuses a hand-made POST. --}}
                    <div class="alert alert-info py-2 mb-0">
                        <div class="fw-semibold"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Upload is not enabled for your account.</div>
                        <div class="small">Your department admin can turn on BOM upload for you. You can still open and edit your buyer's files from Existing Files.</div>
                    </div>
                @endunless

                @if($canUpload)
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
                    {{-- Buyer and Remarks share one row. Both are short answers
                         about how the file is filed rather than the upload
                         itself, and stacked full-width they took up as much of
                         the card as the dropzone did — which is what flattened
                         the hierarchy. The grid collapses to one column below
                         about 520px. --}}
                    <div class="gx-upload-fields">
                        {{-- Tags the file with a buyer from the master list. The
                             free-text "Buyer Name" column inside the sheet is
                             untouched; this is the file-level assignment the
                             workspace list and its buyer filter read. --}}
                        @if($scopedBuyer)
                            {{-- Scoped to one buyer, so there is nothing to choose:
                                 the server tags the file from this user's own
                                 assignment and ignores any posted buyer_id. --}}
                            <div>
                                <label class="form-label d-block mb-1">Buyer</label>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                    <i class="bi bi-tag-fill me-1" aria-hidden="true"></i>{{ $scopedBuyer->buyer_name }}
                                </span>
                                <div class="form-text">Tagged to your assigned buyer automatically.</div>
                            </div>
                        @else
                            <div>
                                <label class="form-label" for="merchantUploadBuyer">Buyer <span class="text-danger">*</span></label>
                                <select name="buyer_id" id="merchantUploadBuyer"
                                        class="form-select @error('buyer_id') is-invalid @enderror" required>
                                    <option value="">Select buyer</option>
                                    @foreach($buyers as $buyer)
                                        <option value="{{ $buyer->id }}" {{ (int) old('buyer_id') === (int) $buyer->id ? 'selected' : '' }}>
                                            {{ $buyer->buyer_name }}{{ $buyer->buyer_code ? ' (' . $buyer->buyer_code . ')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @if($buyers->isEmpty())
                                    <div class="form-text text-danger">No active buyer found. Please ask admin to add a buyer first.</div>
                                @endif
                            </div>
                        @endif

                        <div>
                            <label class="form-label" for="merchantUploadRemarks">Remarks <span class="text-muted small fw-normal">(optional)</span></label>
                            <input type="text" name="remarks" id="merchantUploadRemarks" class="form-control"
                                   placeholder="Anything worth noting about this file" value="{{ old('remarks') }}">
                            <div class="form-text">Formula fields are calculated automatically after upload.</div>
                        </div>
                    </div>
                </x-file-upload>
                @endif
            </x-card>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'files' ? 'show active' : '' }}" id="merchant-files-tab">
            @include('partials.excel-files-table', ['files' => $files])
        </div>
    </div>
</div>

@include('merchant.partials.upload-loading')
@endsection
