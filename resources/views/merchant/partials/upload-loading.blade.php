{{--
    Updated Excel Upload Loading Partial
    Place this file at: resources/views/merchant/partials/upload-loading.blade.php
    Then include it before @endsection:
    @include('merchant.partials.upload-loading')
--}}

<style>
    .excel-upload-loader-overlay {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, 0.42);
        backdrop-filter: blur(8px);
        z-index: 99999;
    }

    .excel-upload-loader-overlay.is-show {
        display: flex;
    }

    .excel-upload-loader-card {
        width: min(470px, 100%);
        background: rgba(255, 255, 255, 0.96);
        border-radius: 24px;
        overflow: hidden;
        border: 1px solid rgba(203, 213, 225, 0.8);
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.26);
        animation: excelLoaderPop .32s ease-out;
    }

    @keyframes excelLoaderPop {
        from { opacity: 0; transform: translateY(16px) scale(.97); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .excel-upload-loader-top {
        padding: 22px 24px 16px;
        text-align: center;
        background: linear-gradient(135deg, #f3f8ff 0%, #f8fbff 45%, #f3fffa 100%);
        border-bottom: 1px solid #eef2f7;
    }

    .excel-upload-loader-icon {
        width: 72px;
        height: 72px;
        margin: 0 auto 14px;
        position: relative;
    }

    .excel-upload-loader-icon::before,
    .excel-upload-loader-icon::after {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 4px solid transparent;
    }

    .excel-upload-loader-icon::before {
        border-top-color: #2563eb;
        border-right-color: #60a5fa;
        animation: excelLoaderSpin 1.25s linear infinite;
    }

    .excel-upload-loader-icon::after {
        inset: 10px;
        border-bottom-color: #10b981;
        border-left-color: #38bdf8;
        animation: excelLoaderSpin 1.7s linear infinite reverse;
    }

    .excel-upload-loader-core {
        position: absolute;
        inset: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #ffffff;
        font-size: 14px;
        font-weight: 800;
        letter-spacing: .4px;
        background: linear-gradient(135deg, #2563eb, #14b8a6);
        box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22);
        animation: excelLoaderFloat 1.8s ease-in-out infinite;
    }

    @keyframes excelLoaderSpin {
        to { transform: rotate(360deg); }
    }

    @keyframes excelLoaderFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-4px); }
    }

    .excel-upload-loader-title {
        margin: 0 0 6px;
        font-size: 18px;
        font-weight: 700;
        color: #1e293b;
    }

    .excel-upload-loader-text {
        margin: 0;
        font-size: 13px;
        line-height: 1.55;
        color: #64748b;
    }

    .excel-upload-loader-body {
        padding: 18px 24px 22px;
    }

    .excel-progress-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .excel-progress-head strong:first-child {
        color: #334155;
    }

    .excel-progress-track {
        height: 11px;
        border-radius: 999px;
        background: #e8eef7;
        overflow: hidden;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.05);
    }

    .excel-progress-bar {
        width: 6%;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #2563eb 0%, #38bdf8 56%, #10b981 100%);
        position: relative;
        transition: width .45s ease;
        overflow: hidden;
    }

    .excel-progress-bar::after {
        content: '';
        position: absolute;
        top: 0;
        right: -90px;
        bottom: 0;
        width: 90px;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,.72), transparent);
        animation: excelLoaderShimmer 1.4s linear infinite;
    }

    @keyframes excelLoaderShimmer {
        from { transform: translateX(0); }
        to { transform: translateX(-220px); }
    }

    .excel-file-name-box {
        margin-top: 14px;
        padding: 10px 12px;
        border-radius:var(--gx-radius);
        border: 1px solid #dbeafe;
        background: linear-gradient(180deg, #eff6ff 0%, #f8fbff 100%);
        color: #1d4ed8;
        font-size: 13px;
        font-weight: 700;
        word-break: break-all;
    }

    .excel-status-list {
        display: grid;
        gap: 9px;
        list-style: none;
        margin: 16px 0 0;
        padding: 0;
    }

    .excel-status-list li {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius:var(--gx-radius);
        color: #475569;
        background: #f8fafc;
        border: 1px solid #e8eef4;
        font-size: 13px;
        line-height: 1.4;
        transition: .25s ease;
    }

    .excel-status-list li span:first-child {
        width: 12px;
        height: 12px;
        flex: 0 0 12px;
        border-radius: 50%;
        background: #cbd5e1;
        transition: .25s ease;
    }

    .excel-status-list li.is-done {
        color: #334155;
    }

    .excel-status-list li.is-active {
        background: #ecfdf5;
        border-color: #b7ebcd;
        color: #047857;
        font-weight: 700;
    }

    .excel-status-list li.is-done span:first-child,
    .excel-status-list li.is-active span:first-child {
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, .14);
    }

    .excel-upload-submit-loading {
        pointer-events: none;
        opacity: .85;
    }
</style>

<div class="excel-upload-loader-overlay" id="excelUploadLoaderOverlay" aria-hidden="true">
    <div class="excel-upload-loader-card">
        <div class="excel-upload-loader-top">
            <div class="excel-upload-loader-icon">
                <div class="excel-upload-loader-core">XL</div>
            </div>
            <h4 class="excel-upload-loader-title" id="excelLoaderTitle">Uploading Excel File</h4>
            <p class="excel-upload-loader-text" id="excelLoaderMessage">Please wait while your file is being uploaded and validated.</p>
        </div>

        <div class="excel-upload-loader-body">
            <div class="excel-progress-head">
                <strong>Processing Progress</strong>
                <strong class="text-primary" id="excelLoaderPercent">6%</strong>
            </div>
            <div class="excel-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="6">
                <div class="excel-progress-bar" id="excelLoaderProgressBar"></div>
            </div>

            <div class="excel-file-name-box" id="excelLoaderFileName">Selected file will appear here</div>

            <ul class="excel-status-list" id="excelLoaderStatusList">
                <li class="is-active"><span></span><span>Uploading file</span></li>
                <li><span></span><span>Comparing headers and validating file</span></li>
                <li><span></span><span>Reading rows and saving data</span></li>
                <li><span></span><span>Running formula and conditional calculations</span></li>
            </ul>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const overlay = document.getElementById('excelUploadLoaderOverlay');
        const progressBar = document.getElementById('excelLoaderProgressBar');
        const percentText = document.getElementById('excelLoaderPercent');
        const titleText = document.getElementById('excelLoaderTitle');
        const messageText = document.getElementById('excelLoaderMessage');
        const fileNameText = document.getElementById('excelLoaderFileName');
        const progressTrack = overlay.querySelector('[role="progressbar"]');
        const statusItems = Array.from(document.querySelectorAll('#excelLoaderStatusList li'));

        let progress = 6;
        let timer = null;
        let activeStep = 1;
        let activeButton = null;

        const stepContent = {
            1: ['Uploading Excel File', 'Your file is being uploaded. Please wait a moment.'],
            2: ['Validating File Headers', 'Checking whether the uploaded file headers match the latest sample format.'],
            3: ['Saving Excel Rows', 'Reading data rows and storing them in the database.'],
            4: ['Final Calculation Running', 'Applying formula and conditional calculations. Almost done.']
        };

        function setProgress(value) {
            progress = Math.min(value, 95);
            progressBar.style.width = progress + '%';
            percentText.textContent = progress + '%';
            progressTrack.setAttribute('aria-valuenow', progress);

            if (progress >= 25 && activeStep < 2) activeStep = 2;
            if (progress >= 52 && activeStep < 3) activeStep = 3;
            if (progress >= 80 && activeStep < 4) activeStep = 4;

            titleText.textContent = stepContent[activeStep][0];
            messageText.textContent = stepContent[activeStep][1];

            statusItems.forEach(function (item, index) {
                const step = index + 1;
                item.classList.remove('is-active', 'is-done');
                if (step < activeStep) item.classList.add('is-done');
                if (step === activeStep) item.classList.add('is-active');
            });
        }

        function showLoader(form, fileInput) {
            const selectedName = fileInput.files && fileInput.files.length ? fileInput.files[0].name : 'Excel file selected';
            activeButton = form.querySelector('button[type="submit"], input[type="submit"]');

            fileNameText.textContent = selectedName;
            overlay.classList.add('is-show');
            overlay.setAttribute('aria-hidden', 'false');

            if (activeButton) {
                activeButton.classList.add('excel-upload-submit-loading');
                activeButton.dataset.oldText = activeButton.innerHTML;
                activeButton.innerHTML = 'Uploading...';
            }

            setProgress(6);
            timer = setInterval(function () {
                const add = progress < 35 ? 9 : (progress < 70 ? 5 : 2);
                setProgress(progress + add);
            }, 650);
        }

        function resetLoader() {
            if (timer) clearInterval(timer);
            timer = null;
            progress = 6;
            activeStep = 1;
            overlay.classList.remove('is-show');
            overlay.setAttribute('aria-hidden', 'true');

            if (activeButton) {
                activeButton.classList.remove('excel-upload-submit-loading');
                if (activeButton.dataset.oldText) {
                    activeButton.innerHTML = activeButton.dataset.oldText;
                }
            }
            activeButton = null;
        }

        document.querySelectorAll('form').forEach(function (form) {
            const fileInput = form.querySelector('input[type="file"][name="file"]');
            if (!fileInput) return;

            form.addEventListener('submit', function () {
                if (!fileInput.files || !fileInput.files.length) return;
                showLoader(form, fileInput);
            });
        });

        window.addEventListener('pageshow', resetLoader);
    });
</script>
