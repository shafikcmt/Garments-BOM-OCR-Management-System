{{--
    Drag-and-drop upload zone.

    Wraps a real <input type="file"> inside a normal <form>, so with JavaScript
    disabled it degrades to the plain file picker that was here before and the
    server flow is untouched. The script upgrades it to drag-and-drop with a
    byte-accurate progress bar.

    Props:
      action    form action
      name      file field name (the server expects a single file)
      accept    accept attribute, e.g. ".xlsx,.xls,.csv"
      maxMb     size limit to show and pre-check against
      hint      supporting line under the title
--}}
@props([
    'action' => '',
    'name' => 'file',
    'accept' => '.xlsx,.xls,.csv',
    'maxMb' => 40,
    'hint' => null,
])

<form method="POST" action="{{ $action }}" enctype="multipart/form-data"
      class="gx-upload" data-upload-form data-max-mb="{{ $maxMb }}">
    @csrf

    {{-- The zone is a label, so clicking or pressing Enter on it opens the
         file picker without any script. --}}
    <label class="gx-dropzone" data-dropzone>
        <input type="file" name="{{ $name }}" accept="{{ $accept }}" required
               class="visually-hidden" data-upload-input>

        <span class="gx-dropzone-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></span>

        <span class="gx-dropzone-title">Drag a file here, or click to browse</span>

        <span class="gx-dropzone-hint">
            {{ $hint ?? 'Excel or CSV' }}
            <span class="text-muted">&middot;</span>
            up to {{ $maxMb }} MB
        </span>

        <span class="gx-dropzone-formats">
            @foreach(array_filter(explode(',', $accept)) as $ext)
                <span class="gx-format-pill">{{ ltrim(trim($ext), '.') }}</span>
            @endforeach
        </span>
    </label>

    {{-- Selected file card, populated by the script. --}}
    <div class="gx-upload-file d-none" data-upload-file>
        <span class="gx-upload-file-icon" aria-hidden="true"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i></span>
        <span class="gx-upload-file-body">
            <span class="gx-upload-file-name" data-upload-name></span>
            <span class="gx-upload-file-meta" data-upload-meta></span>

            <span class="progress gx-upload-progress d-none" data-upload-progress-wrap
                  role="progressbar" aria-label="Upload progress"
                  aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span class="progress-bar" data-upload-progress-bar style="width:0%"></span>
            </span>
        </span>

        <button type="button" class="btn btn-sm btn-outline-danger gx-upload-remove"
                data-upload-remove aria-label="Remove selected file">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="alert alert-danger py-2 small mt-3 d-none" data-upload-error role="alert"></div>

    {{ $slot }}

    <div class="d-flex align-items-center gap-2 mt-3">
        <button type="submit" class="btn btn-primary px-4" data-upload-submit>
            <span data-upload-submit-label><i class="bi bi-upload me-1" aria-hidden="true"></i>Upload File</span>
        </button>
        <span class="small text-muted d-none" data-upload-status aria-live="polite"></span>
    </div>
</form>
