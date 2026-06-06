@extends('layouts.app')

@section('title', 'Excel Upload')

@section('content')
<style>
    .upload-card {
        border: 0;
        border-radius: 24px;
        overflow: hidden;
    }

    .upload-header {
        background: linear-gradient(135deg, #eef4ff 0%, #f8fbff 100%);
        border-bottom: 1px solid #e8eef9;
        padding: 1.25rem 1.5rem;
    }

    .upload-form-wrap {
        padding: 1.5rem;
    }

    .upload-note-box {
        border: 1px dashed #c8d7f2;
        background: #f8fbff;
        border-radius: 18px;
        padding: 1rem 1.1rem;
    }

    .loading-overlay {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(6px);
        z-index: 2000;
    }

    .loading-overlay.active {
        display: flex;
    }

    .loading-card {
        width: min(520px, 100%);
        background: #ffffff;
        border-radius: 28px;
        box-shadow: 0 30px 80px rgba(15, 23, 42, 0.22);
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.18);
    }

    .loading-card-top {
        padding: 24px 24px 18px;
        background: linear-gradient(135deg, #edf4ff 0%, #f7fbff 55%, #eef9ff 100%);
        text-align: center;
    }

    .upload-loader {
        width: 84px;
        height: 84px;
        margin: 0 auto 18px;
        position: relative;
    }

    .upload-loader span {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 4px solid transparent;
        animation: pulseRing 2.2s linear infinite;
    }

    .upload-loader span:nth-child(1) {
        border-top-color: #2563eb;
        border-right-color: #60a5fa;
    }

    .upload-loader span:nth-child(2) {
        inset: 10px;
        border-bottom-color: #14b8a6;
        border-left-color: #38bdf8;
        animation-duration: 1.5s;
        animation-direction: reverse;
    }

    .upload-loader span:nth-child(3) {
        inset: 24px;
        background: radial-gradient(circle at 35% 35%, #60a5fa, #2563eb);
        border: 0;
        animation: loaderFloat 1.8s ease-in-out infinite;
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
    }

    @keyframes pulseRing {
        0% { transform: rotate(0deg) scale(1); }
        50% { transform: rotate(180deg) scale(1.04); }
        100% { transform: rotate(360deg) scale(1); }
    }

    @keyframes loaderFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-6px); }
    }

    .loading-card-body {
        padding: 22px 24px 24px;
    }

    .upload-progress {
        height: 12px;
        background: #e9eef7;
        border-radius: 999px;
        overflow: hidden;
        margin: 14px 0 10px;
    }

    .upload-progress-bar {
        height: 100%;
        width: 8%;
        border-radius: inherit;
        background: linear-gradient(90deg, #2563eb 0%, #38bdf8 60%, #14b8a6 100%);
        transition: width 0.5s ease;
        position: relative;
        overflow: hidden;
    }

    .upload-progress-bar::after {
        content: '';
        position: absolute;
        top: 0;
        bottom: 0;
        width: 90px;
        right: -90px;
        background: linear-gradient(90deg, rgba(255,255,255,0), rgba(255,255,255,.65), rgba(255,255,255,0));
        animation: shimmer 1.4s linear infinite;
    }

    @keyframes shimmer {
        from { transform: translateX(0); }
        to { transform: translateX(-220px); }
    }

    .upload-status-list {
        list-style: none;
        padding: 0;
        margin: 18px 0 0;
        display: grid;
        gap: 10px;
    }

    .upload-status-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 14px;
        background: #f8fafc;
        border: 1px solid #e9eef5;
        font-size: 0.94rem;
        color: #334155;
    }

    .upload-status-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #cbd5e1;
        flex: 0 0 12px;
        transition: all .3s ease;
    }

    .upload-status-item.active .upload-status-dot,
    .upload-status-item.done .upload-status-dot {
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.16);
    }

    .upload-status-item.active {
        background: #effcf6;
        border-color: #b7ebcd;
    }

    .upload-file-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 0.9rem;
        font-weight: 600;
        max-width: 100%;
        word-break: break-all;
    }

    .upload-submit-btn.is-loading {
        pointer-events: none;
        opacity: .9;
    }
</style>

<div class="container-fluid">
    <div class="card shadow-sm upload-card">
        <div class="upload-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h3 class="mb-1">Upload Excel File</h3>
                <p class="text-muted mb-0">Upload your merchant data using the sample file format.</p>
            </div>
            <a href="{{ route('merchant.excel.sample') }}" class="btn btn-outline-primary">
                Download Sample Excel
            </a>
        </div>

        <div class="upload-form-wrap">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="upload-note-box mb-4">
                <div class="fw-semibold mb-1">Important</div>
                <div class="text-muted small mb-0">
                    Download the sample file and maintain the same column order. Do not close this page while the upload is in progress.
                </div>
            </div>

            <form id="excelUploadForm" method="POST" action="{{ route('merchant.excel.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="mb-3">
                    <label class="form-label fw-semibold">Select File</label>
                    <input type="file" id="uploadFileInput" name="file" class="form-control" accept=".csv,.xls,.xlsx" required>
                    <div class="form-text">Allowed file: csv, xls, xlsx</div>
                    @error('file')
                        <small class="text-danger d-block mt-1">{{ $message }}</small>
                    @enderror
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Remarks</label>
                    <input type="text" name="remarks" class="form-control" placeholder="Optional remarks">
                </div>

                <button type="submit" id="uploadSubmitBtn" class="btn btn-primary upload-submit-btn px-4">
                    <span class="default-label">Upload File</span>
                </button>
            </form>
        </div>
    </div>
</div>

<div class="loading-overlay" id="uploadLoadingOverlay" aria-hidden="true">
    <div class="loading-card">
        <div class="loading-card-top">
            <div class="upload-loader" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <h4 class="mb-2">Uploading your Excel file</h4>
            <p class="text-muted mb-0" id="loadingHeadline">Please wait... file upload and validation in progress.</p>
        </div>

        <div class="loading-card-body">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                <div class="fw-semibold text-dark">Upload Progress</div>
                <div class="small text-primary fw-semibold" id="loadingPercent">8%</div>
            </div>

            <div class="upload-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="8">
                <div class="upload-progress-bar" id="uploadProgressBar"></div>
            </div>

            <div class="small text-muted mb-3" id="loadingSubtext">Preparing file for upload...</div>

            <div class="mb-3">
                <div class="small text-muted mb-2">Selected file</div>
                <div class="upload-file-pill" id="selectedFileName">No file selected</div>
            </div>

            <ul class="upload-status-list">
                <li class="upload-status-item active" data-step="1">
                    <span class="upload-status-dot"></span>
                    <span>Uploading file</span>
                </li>
                <li class="upload-status-item" data-step="2">
                    <span class="upload-status-dot"></span>
                    <span>Validating file headers</span>
                </li>
                <li class="upload-status-item" data-step="3">
                    <span class="upload-status-dot"></span>
                    <span>Reading rows and saving data</span>
                </li>
                <li class="upload-status-item" data-step="4">
                    <span class="upload-status-dot"></span>
                    <span>Running formula calculations</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('excelUploadForm');
        const fileInput = document.getElementById('uploadFileInput');
        const submitBtn = document.getElementById('uploadSubmitBtn');
        const overlay = document.getElementById('uploadLoadingOverlay');
        const progressBar = document.getElementById('uploadProgressBar');
        const loadingPercent = document.getElementById('loadingPercent');
        const loadingHeadline = document.getElementById('loadingHeadline');
        const loadingSubtext = document.getElementById('loadingSubtext');
        const selectedFileName = document.getElementById('selectedFileName');
        const progressContainer = overlay.querySelector('[role="progressbar"]');
        const statusItems = overlay.querySelectorAll('.upload-status-item');

        let progress = 8;
        let progressTimer = null;
        let progressStage = 1;

        const stepTexts = {
            1: {
                headline: 'Uploading your Excel file',
                subtext: 'Please wait while your file is being uploaded and validated.'
            },
            2: {
                headline: 'Validating file headers',
                subtext: 'Checking whether the uploaded file headers match the sample format.'
            },
            3: {
                headline: 'Processing rows and saving data',
                subtext: 'Reading data rows and preparing to save to the database.'
            },
            4: {
                headline: 'Finalizing upload',
                subtext: 'Applying formula and conditional calculations. Almost done.'
            }
        };

        function updateProgress(value) {
            progress = Math.min(value, 95);
            progressBar.style.width = progress + '%';
            loadingPercent.textContent = progress + '%';
            progressContainer.setAttribute('aria-valuenow', progress);

            if (progress >= 25 && progressStage < 2) {
                progressStage = 2;
            }
            if (progress >= 50 && progressStage < 3) {
                progressStage = 3;
            }
            if (progress >= 78 && progressStage < 4) {
                progressStage = 4;
            }

            statusItems.forEach(function (item, index) {
                const step = index + 1;
                item.classList.remove('active', 'done');

                if (step < progressStage) {
                    item.classList.add('done');
                } else if (step === progressStage) {
                    item.classList.add('active');
                }
            });

            loadingHeadline.textContent = stepTexts[progressStage].headline;
            loadingSubtext.textContent = stepTexts[progressStage].subtext;
        }

        function startLoading() {
            const fileName = fileInput.files.length ? fileInput.files[0].name : 'Excel file';
            selectedFileName.textContent = fileName;
            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');
            submitBtn.classList.add('is-loading');
            submitBtn.setAttribute('disabled', 'disabled');
            updateProgress(8);

            progressTimer = setInterval(function () {
                const increment = progress < 35 ? 8 : (progress < 70 ? 5 : 2);
                updateProgress(progress + increment);
            }, 700);
        }

        form.addEventListener('submit', function () {
            if (!fileInput.files.length) {
                return;
            }

            startLoading();
        });

        window.addEventListener('pageshow', function () {
            if (progressTimer) {
                clearInterval(progressTimer);
            }
            overlay.classList.remove('active');
            overlay.setAttribute('aria-hidden', 'true');
            submitBtn.classList.remove('is-loading');
            submitBtn.removeAttribute('disabled');
        });
    });
</script>
@endsection
