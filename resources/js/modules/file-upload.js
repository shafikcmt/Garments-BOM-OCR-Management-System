/**
 * Drag-and-drop upload with a real progress bar.
 *
 * Progressive enhancement: the markup is a normal <form> around a normal file
 * input, so with this script absent the browser posts it the ordinary way and
 * the server behaves identically. The script adds drag-and-drop, a file card,
 * and byte-accurate upload progress.
 *
 * The progress bar reports what XHR actually measures — bytes sent to the
 * server. Once those reach 100% the request is still open while the server
 * parses the spreadsheet, and the server reports nothing about that phase, so
 * the bar switches to an indeterminate "processing" state rather than
 * inventing percentages for work it cannot see.
 */

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(0) + ' KB';

    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function initForm(form) {
    const input = form.querySelector('[data-upload-input]');
    const zone = form.querySelector('[data-dropzone]');
    const card = form.querySelector('[data-upload-file]');
    const nameEl = form.querySelector('[data-upload-name]');
    const metaEl = form.querySelector('[data-upload-meta]');
    const progressWrap = form.querySelector('[data-upload-progress-wrap]');
    const progressBar = form.querySelector('[data-upload-progress-bar]');
    const errorEl = form.querySelector('[data-upload-error]');
    const statusEl = form.querySelector('[data-upload-status]');
    const submitBtn = form.querySelector('[data-upload-submit]');
    const submitLabel = form.querySelector('[data-upload-submit-label]');
    const removeBtn = form.querySelector('[data-upload-remove]');

    if (!input || !zone) {
        return;
    }

    const maxBytes = (parseFloat(form.dataset.maxMb) || 40) * 1024 * 1024;
    const accepted = (input.getAttribute('accept') || '')
        .split(',')
        .map((s) => s.trim().toLowerCase())
        .filter(Boolean);

    function showError(message) {
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function clearError() {
        errorEl.textContent = '';
        errorEl.classList.add('d-none');
    }

    /** Client-side checks are a courtesy; the server validates regardless. */
    function validate(file) {
        const dot = file.name.lastIndexOf('.');
        const ext = dot >= 0 ? file.name.slice(dot).toLowerCase() : '';

        if (accepted.length && !accepted.includes(ext)) {
            return 'That file type is not accepted. Allowed: ' + accepted.join(', ') + '.';
        }

        if (file.size > maxBytes) {
            return 'That file is ' + formatBytes(file.size) + '. The limit is '
                + form.dataset.maxMb + ' MB.';
        }

        if (file.size === 0) {
            return 'That file is empty.';
        }

        return null;
    }

    function showFile(file) {
        nameEl.textContent = file.name;
        metaEl.textContent = formatBytes(file.size);
        card.classList.remove('d-none');
        zone.classList.add('has-file');
    }

    function reset() {
        input.value = '';
        card.classList.add('d-none');
        zone.classList.remove('has-file');
        progressWrap.classList.add('d-none');
        progressBar.style.width = '0%';
        progressBar.classList.remove('gx-progress-indeterminate');
        statusEl.classList.add('d-none');
        clearError();
    }

    function handleFile(file) {
        clearError();

        const problem = validate(file);

        if (problem) {
            showError(problem);
            input.value = '';
            card.classList.add('d-none');

            return;
        }

        showFile(file);
    }

    input.addEventListener('change', () => {
        if (input.files && input.files[0]) {
            handleFile(input.files[0]);
        }
    });

    removeBtn?.addEventListener('click', reset);

    // --- Drag and drop ----------------------------------------------------
    ['dragenter', 'dragover'].forEach((type) => {
        zone.addEventListener(type, (e) => {
            e.preventDefault();
            zone.classList.add('is-dragging');
        });
    });

    ['dragleave', 'drop'].forEach((type) => {
        zone.addEventListener(type, (e) => {
            e.preventDefault();
            zone.classList.remove('is-dragging');
        });
    });

    zone.addEventListener('drop', (e) => {
        const file = e.dataTransfer?.files?.[0];

        if (!file) {
            return;
        }

        // Put the dropped file on the real input so a non-XHR submit still works.
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;

        handleFile(file);
    });

    // --- Submit with progress --------------------------------------------
    form.addEventListener('submit', (e) => {
        const file = input.files && input.files[0];

        if (!file) {
            return; // let the browser's own required validation handle it
        }

        const problem = validate(file);

        if (problem) {
            e.preventDefault();
            showError(problem);

            return;
        }

        if (!window.FormData || !window.XMLHttpRequest) {
            return; // very old browser: fall back to a normal submit
        }

        e.preventDefault();
        clearError();

        submitBtn.disabled = true;
        submitLabel.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading…';
        progressWrap.classList.remove('d-none');
        statusEl.classList.remove('d-none');
        statusEl.textContent = 'Sending file…';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) {
                return;
            }

            const percent = Math.round((event.loaded / event.total) * 100);
            progressBar.style.width = percent + '%';
            progressWrap.setAttribute('aria-valuenow', percent);
            statusEl.textContent = 'Sending file… ' + percent + '%';

            if (percent >= 100) {
                // Bytes are all sent; the server is now parsing. It reports no
                // progress for that, so stop pretending to measure it.
                progressBar.classList.add('gx-progress-indeterminate');
                progressWrap.removeAttribute('aria-valuenow');
                statusEl.textContent = 'Processing on the server — this can take a while for large files.';
                submitLabel.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing…';
            }
        });

        xhr.addEventListener('load', () => {
            // The controller redirects on both success and validation failure,
            // so following the final URL keeps the existing server flow and its
            // flash messages exactly as they were.
            window.location.href = xhr.responseURL || window.location.href;
        });

        xhr.addEventListener('error', () => {
            progressBar.classList.remove('gx-progress-indeterminate');
            showError('The upload failed to reach the server. Check your connection and try again.');
            submitBtn.disabled = false;
            submitLabel.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Retry upload';
            statusEl.classList.add('d-none');
        });

        xhr.send(new FormData(form));
    });
}

export function initFileUpload() {
    document.querySelectorAll('[data-upload-form]').forEach(initForm);
}
