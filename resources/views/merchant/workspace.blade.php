@extends('layouts.app')

@section('title', 'Merchant Workspace')

@section('content')
@php
    $activeTab = request('tab', 'upload');
    $defaultManualRows = max(5, count(old('manual_rows', [])) ?: 5);
@endphp

<style>
    .merchant-workspace-page .workspace-hero {
        border: 1px solid #e9eef6;
        border-radius: 14px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
        background: linear-gradient(180deg, #ffffff, #fbfdff);
    }

    .merchant-workspace-page .workspace-card {
        border: 1px solid #e9eef6;
        border-radius: 14px;
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

    .manual-order-wrap {
        max-height: 58vh;
        overflow: auto;
        border: 1px solid #e8eef5;
        border-radius: 12px;
    }

    .manual-order-table {
        min-width: 1200px;
        margin-bottom: 0;
        font-size: 12px;
    }

    .manual-order-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: linear-gradient(180deg, #f6f9fd, #edf3f9);
        border-color: #dce4ee;
        vertical-align: middle;
        text-align: center;
        min-width: 150px;
        padding: 8px;
        white-space: normal;
    }

    .manual-order-table thead th:first-child,
    .manual-order-table tbody td:first-child {
        position: sticky;
        left: 0;
        z-index: 3;
        width: 60px;
        min-width: 60px;
        text-align: center;
        font-weight: 700;
        background: #fff;
    }

    .manual-order-table thead th:first-child {
        background: linear-gradient(180deg, #e8eef6, #dde7f2);
    }

    .manual-order-table td {
        padding: 4px;
        border-color: #edf2f7;
        background: #fff;
    }

    .manual-order-table tbody tr:nth-child(even) td {
        background: #fcfcfd;
    }

    .manual-order-table tbody tr:hover td {
        background: #f8fbff;
    }

    .manual-order-table .form-control,
    .manual-order-table .form-select {
        min-width: 140px;
        height: 30px;
        padding: 3px 7px;
        border-radius: 7px;
        font-size: 12px;
        border-color: #d9e2ec;
    }

    .manual-header-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 2px 7px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 9px;
        font-weight: 700;
        margin-top: 4px;
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
            <p class="text-muted mb-0">Excel upload, direct order create, and uploaded file management.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="merchantWorkspaceTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'upload' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#merchant-upload-tab" type="button">
                New Excel File Upload
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $activeTab === 'create' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#merchant-create-tab" type="button">
                New Order Create
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
                            <p class="text-muted mb-0 small">Sample file-e only merchant input headers thakbe. Formula/conditional headers upload file-e thakbe na.</p>
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
                            <small class="text-muted">Only merchant owner + input type headers allowed. Formula/conditional fields auto calculate hobe.</small>
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

        <div class="tab-pane fade {{ $activeTab === 'create' ? 'show active' : '' }}" id="merchant-create-tab">
            <div class="card workspace-card border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="mb-1">New Order Create</h5>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="addManualOrderRow">
                            + Add Row
                        </button>
                    </div>

                    

                    @if($merchantInputHeaders->isEmpty())
                        <div class="alert alert-warning mb-0">
                            Merchant input header found hoyni. Admin panel theke merchant owner + input type headers configure korte hobe.
                        </div>
                    @else
                        <form method="POST" action="{{ route('merchant.excel.manual-store') }}" id="manualOrderForm">
                            @csrf

                            <div class="manual-order-wrap mb-3">
                                <table class="table table-bordered manual-order-table" id="manualOrderTable">
                                    <thead>
                                        <tr>
                                            <th>Row</th>
                                            @foreach($merchantInputHeaders as $header)
                                                <th>
                                                    <div>{{ $header->header_name }}</div>
                                                    <span class="manual-header-chip">Merchant Input</span>
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @for($rowIndex = 0; $rowIndex < $defaultManualRows; $rowIndex++)
                                            <tr>
                                                <td class="manual-row-number">{{ $rowIndex + 1 }}</td>
                                                @foreach($merchantInputHeaders as $header)
                                                    @php
                                                        $inputType = $header->field_type === 'number' ? 'number' : ($header->field_type === 'date' ? 'date' : 'text');
                                                        $oldValue = old('manual_rows.' . $rowIndex . '.' . $header->id, '');
                                                    @endphp
                                                    <td>
                                                        <input
                                                            type="{{ $inputType }}"
                                                            name="manual_rows[{{ $rowIndex }}][{{ $header->id }}]"
                                                            class="form-control manual-order-input"
                                                            value="{{ $oldValue }}"
                                                            placeholder="{{ $header->header_name }}"
                                                            @if($inputType === 'number') step="any" @endif
                                                        >
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endfor
                                    </tbody>
                                </table>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" placeholder="Optional remarks" value="{{ old('remarks') }}">
                            </div>

                            <button type="submit" class="btn btn-primary">Create Order</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'files' ? 'show active' : '' }}" id="merchant-files-tab">
            @include('partials.excel-files-table', ['files' => $files])
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const addRowButton = document.getElementById('addManualOrderRow');
    const tableBody = document.querySelector('#manualOrderTable tbody');

    if (!addRowButton || !tableBody) {
        return;
    }

    addRowButton.addEventListener('click', function () {
        const rows = tableBody.querySelectorAll('tr');
        const nextIndex = rows.length;
        const templateRow = rows[0];

        if (!templateRow) {
            return;
        }

        const newRow = templateRow.cloneNode(true);
        const rowNumber = newRow.querySelector('.manual-row-number');

        if (rowNumber) {
            rowNumber.textContent = nextIndex + 1;
        }

        newRow.querySelectorAll('input').forEach(function (input) {
            input.value = '';
            input.name = input.name.replace(/manual_rows\[\d+\]/, 'manual_rows[' + nextIndex + ']');
        });

        tableBody.appendChild(newRow);
    });
});
</script>
@include('merchant.partials.upload-loading')
@endsection
