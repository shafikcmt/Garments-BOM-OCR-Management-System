@extends('layouts.app')

@section('title', 'PO Control - ' . $bookingPo->po_no)

@php
    $revisionNo = max(0, (int) (($bookingData['revision_no'] ?? 0)));
    $poAdminControl = $poAdminControl ?? [];
    $poLocked = (bool) ($poAdminControl['locked'] ?? false);
    $poPermissionMode = $poAdminControl['edit_permission'] ?? 'authorized_users';
    $poPermissionText = match ($poPermissionMode) {
        'authorized_users' => 'Supply Chain users only',
        'all_users' => 'All users can edit',
        default => 'Admin only',
    };
    $poAuthorizedIds = collect($poAdminControl['authorized_user_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
    $poLockScope = $poAdminControl['lock_scope'] ?? 'all_users';
    $poLockScopeText = match ($poLockScope) {
        'specific_users' => 'Locked users only',
        'specific_roles' => 'Locked roles only',
        default => 'All users locked',
    };
    $poLockedUserIds = collect($poAdminControl['locked_user_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
    $poLockedRoleIds = collect($poAdminControl['locked_role_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
@endphp

@section('styles')
@include('supply-chain.bookings.partials._po-module-theme')
<style>
    /* Page chrome only — the embedded Humana Apparels sheet is styled by the
       preview partial and is not touched here. Everything visual comes from the
       module theme, so Admin and Supply Chain look like one product. */
    .po-control-show-wrap {
        min-height: calc(100vh - 110px);
        background: linear-gradient(180deg, #FFFFFF 0%, var(--po-paper) 220px, var(--po-paper) 100%);
    }
    /* Full width for the chrome; the printable sheet inside stays 198mm and
       centres itself. Same reasoning as the Generated PO view. */
    .po-control-show-shell { max-width: none; margin: 0 auto; }
    .po-control-hero-note { color: var(--po-muted); max-width: 720px; }
    .po-control-actionbar,
    .po-admin-access-card {
        background: var(--po-surface);
        border: 1px solid var(--po-line);
        border-radius: var(--po-r-surface);
        box-shadow: var(--po-shadow-sm);
    }
    .po-control-actionbar { padding: 12px 16px; }
    .po-control-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: var(--po-r-pill);
        padding: 5px 11px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
    }
    .po-control-chip.locked { background: var(--po-danger-bg); color: var(--po-danger); border: 1px solid var(--po-danger-border); }
    .po-control-chip.open { background: var(--po-ok-bg); color: var(--po-ok); border: 1px solid var(--po-ok-border); }
    .po-control-chip.permission { background: var(--po-tint); color: var(--po-blue-deep); border: 1px solid #BAE0F2; }
    .po-control-chip .bi,
    .btn .bi { display: inline-flex; align-items: center; justify-content: center; width: 1em; height: 1em; line-height: 1; }
    .po-control-chip .bi::before,
    .btn .bi::before { line-height: 1; }
    .po-admin-access-card .form-control,
    .po-admin-access-card .form-select {
        border-radius: var(--po-r-control);
        border-color: var(--po-line);
        min-height: 40px;
        font-weight: 600;
        font-size: 13px;
    }
    .po-admin-access-card .form-control:focus,
    .po-admin-access-card .form-select:focus {
        border-color: var(--po-blue);
        box-shadow: 0 0 0 3px rgba(0, 108, 157, .14);
    }
    .po-admin-access-card .form-label {
        color: var(--po-muted);
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .09em;
    }
</style>
@endsection

@section('content')
<div class="po-control-show-wrap po-scope p-2 p-md-3">
    <div class="po-control-show-shell">
        <div class="po-head po-rail {{ $poLocked ? 'po-rail-warn' : '' }} mb-3">
            <div>
                <div class="po-micro">Admin / PO Generate Control</div>
                <h4 class="po-head-title">PO {{ $bookingPo->po_no }} @if($revisionNo > 0)<span class="po-pill po-pill-warn ms-1">R-{{ $revisionNo }}</span>@endif</h4>
                <div class="small po-control-hero-note mt-1">Admin can edit, lock, authorize Supply Chain users, delete and check before/after changes. Default PO generate/re-generate owner is Supply Chain only.</div>
            </div>
            {{-- The rail turns amber when this PO is locked, so the page's state
                 is readable from its left edge before any chip is read. --}}
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                    @if($poLocked)
                        <span class="po-control-chip locked"><i class="bi bi-lock-fill" aria-hidden="true"></i>Locked</span>
                    @else
                        <span class="po-control-chip open"><i class="bi bi-unlock" aria-hidden="true"></i>Open</span>
                    @endif
                <span class="po-control-chip permission"><i class="bi bi-person-gear" aria-hidden="true"></i>{{ $poPermissionText }}</span>
                @if($poLocked)
                    <span class="po-control-chip locked"><i class="bi bi-people-fill" aria-hidden="true"></i>{{ $poLockScopeText }}</span>
                @endif
            </div>
        </div>

        {{-- Buttons unchanged: same routes, labels, classes and disabled states. --}}
        <div class="po-control-actionbar mb-3">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <a href="{{ route('admin.po-generate-control.index') }}" class="btn btn-outline-secondary btn-sm rounded-pill fw-bold"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</a>
                <button type="button" class="btn btn-primary btn-sm fw-bold rounded-pill booking-show-edit-start" data-url="{{ route('admin.po-generate-control.edit_preview', $bookingPo) }}" {{ $poLocked ? 'disabled' : '' }} title="{{ $poLocked ? 'Unlock this PO first' : 'Edit PO' }}"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit PO</button>
                <button type="button" class="btn btn-warning btn-sm fw-bold rounded-pill booking-show-regenerate-start" data-url="{{ route('admin.po-generate-control.regenerate_preview', $bookingPo) }}" {{ $poLocked ? 'disabled' : '' }} title="{{ $poLocked ? 'Unlock this PO first' : 'Re-generate PO' }}"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Re-generate PO</button>
                <a href="{{ route('admin.po-generate-control.print', $bookingPo) }}" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill fw-bold"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</a>
                <a href="{{ route('admin.po-generate-control.download', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm rounded-pill fw-bold"><i class="bi bi-filetype-pdf me-1" aria-hidden="true"></i>PDF</a>
                <a href="{{ route('admin.po-generate-control.download_excel', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm rounded-pill fw-bold"><i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Excel</a>
                {{-- Delete stays last and visually separated, so the destructive
                     action is never the one next to Back. --}}
                <form method="POST" action="{{ route('admin.po-generate-control.destroy', $bookingPo) }}" class="ms-auto" onsubmit="return confirm('Delete PO {{ $bookingPo->po_no }}? This will remove only the generated PO number and move source rows back to Pending PO.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill fw-bold"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                </form>
            </div>
        </div>

        <div class="po-admin-access-card p-3 p-md-4 mb-3">
            <form method="POST" action="{{ route('admin.po-generate-control.access', $bookingPo) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="locked" value="0">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-3">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-shield-check text-primary me-1" aria-hidden="true"></i>Admin Access Control</h5>
                        <div class="small text-muted">Manage PO lock, edit permission, active Supply Chain authorized users and admin notes from this panel.</div>
                    </div>
                    <button type="submit" class="btn btn-primary rounded-pill fw-bold px-4"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Save Control</button>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">PO Lock</label>
                        <div class="form-check form-switch border rounded-4 p-3 ps-5 bg-light">
                            <input class="form-check-input" type="checkbox" role="switch" id="lockPoShow{{ $bookingPo->id }}" name="locked" value="1" @checked($poLocked)>
                            <label class="form-check-label fw-bold" for="lockPoShow{{ $bookingPo->id }}">Lock source row edit / update</label>
                        </div>
                        <div class="form-text">Locked source rows become read-only in the worksheet.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Lock Applies To</label>
                        <select name="lock_scope" class="form-select">
                            <option value="all_users" @selected($poLockScope === 'all_users')>Lock all users</option>
                            <option value="specific_roles" @selected($poLockScope === 'specific_roles')>Lock selected roles</option>
                            <option value="specific_users" @selected($poLockScope === 'specific_users')>Lock selected users</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Edit Permission</label>
                        <select name="edit_permission" class="form-select">
                            <option value="admin_only" @selected($poPermissionMode === 'admin_only')>Admin only</option>
                            <option value="authorized_users" @selected($poPermissionMode === 'authorized_users')>Only authorized Supply Chain users</option>
                            <option value="all_users" @selected($poPermissionMode === 'all_users')>All users can edit</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Locked Roles</label>
                        <select name="locked_role_ids[]" class="form-select" multiple size="5">
                            @foreach($poControlRoles as $controlRole)
                                <option value="{{ $controlRole->id }}" @selected(in_array((int) $controlRole->id, $poLockedRoleIds, true))>{{ ucfirst(str_replace('_', ' ', $controlRole->name)) }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Used when Lock Applies To is "Lock selected roles".</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Locked Users</label>
                        <select name="locked_user_ids[]" class="form-select" multiple size="5">
                            @foreach($poLockUsers as $lockUser)
                                <option value="{{ $lockUser->id }}" @selected(in_array((int) $lockUser->id, $poLockedUserIds, true))>{{ $lockUser->name }} - {{ $lockUser->email }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Used when Lock Applies To is "Lock selected users".</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Authorized Supply Chain Users</label>
                        <select name="authorized_user_ids[]" class="form-select" multiple size="5">
                            @foreach($poControlUsers as $controlUser)
                                <option value="{{ $controlUser->id }}" @selected(in_array((int) $controlUser->id, $poAuthorizedIds, true))>{{ $controlUser->name }} - {{ $controlUser->email }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Only active Supply Chain users are suggested here.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Lock Reason</label>
                        <textarea name="lock_reason" rows="3" class="form-control" placeholder="Why is this PO locked?">{{ $poAdminControl['lock_reason'] ?? '' }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Control Note</label>
                        <textarea name="control_note" rows="3" class="form-control" placeholder="Admin note for permission / authorization">{{ $poAdminControl['control_note'] ?? '' }}</textarea>
                    </div>
                </div>
            </form>
        </div>

        <div id="bookingShowAlert"></div>
        <div id="bookingShowPreviewContent">
            @include('supply-chain.bookings.partials.preview', [
                'bookingPo' => $bookingPo,
                'bookingData' => $bookingData,
                'previewMode' => false,
                'generateUrl' => null,
                'instructionOptions' => $instructionOptions ?? collect(),
                'deliveryDestinationOptions' => $deliveryDestinationOptions ?? collect(),
                'bookingRoutePrefix' => $bookingRoutePrefix,
                'canControlPo' => $canControlPo,
            ])
        </div>
    </div>
</div>
@endsection

@section('scripts')
@include('supply-chain.bookings.partials._ex-mill-autofill')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const previewContent = document.getElementById('bookingShowPreviewContent');
    const alertBox = document.getElementById('bookingShowAlert');

    function showAlert(message, type = 'success') {
        if (!alertBox) return;
        alertBox.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show rounded-4" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
    }

    async function postJson(url, payload = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok || data.success === false) throw new Error(data.message || 'Request failed.');
        return data;
    }

    function formDataToObject(formEl) {
        const payload = {};
        if (!formEl) return payload;
        const assign = (name, value) => {
            const parts = [];
            name.replace(/([^\[\]]+)|\[([^\]]*)\]/g, function (_, first, second) { parts.push(first ?? second); });
            let cursor = payload;
            parts.forEach(function (part, index) {
                const last = index === parts.length - 1;
                const nextPart = parts[index + 1];
                if (last) {
                    if (part === '') {
                        if (Array.isArray(cursor)) cursor.push(value);
                    } else if (Object.prototype.hasOwnProperty.call(cursor, part)) {
                        if (!Array.isArray(cursor[part])) cursor[part] = [cursor[part]];
                        cursor[part].push(value);
                    } else {
                        cursor[part] = value;
                    }
                    return;
                }
                if (part === '') return;
                if (!Object.prototype.hasOwnProperty.call(cursor, part)) cursor[part] = nextPart === '' ? [] : {};
                cursor = cursor[part];
            });
        };
        new FormData(formEl).forEach((value, name) => assign(name, value));
        return payload;
    }

    function setGenerateLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            btn.disabled = true;
            btn.classList.add('is-loading');
            const label = btn.dataset.loadingLabel || 'Saving...';
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + label;
        } else {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            const label = btn.getAttribute('aria-label') || 'Generate PO';
            const icon = btn.dataset.regenerate === '1' ? 'bi-arrow-repeat' : 'bi-check2-circle';
            btn.innerHTML = '<span class="bf-btn-generate-content"><i class="bi ' + icon + ' me-1"></i>' + label + '</span>';
        }
    }

    document.querySelector('.booking-show-edit-start')?.addEventListener('click', async function () {
        this.disabled = true;
        try {
            const data = await postJson(this.dataset.url, {});
            showAlert(data.message || 'PO edit panel ready.');
            if (data.preview_html && previewContent) previewContent.innerHTML = data.preview_html;
            previewContent?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showAlert(error.message, 'danger');
        } finally {
            this.disabled = false;
        }
    });

    document.querySelector('.booking-show-regenerate-start')?.addEventListener('click', async function () {
        this.disabled = true;
        try {
            const data = await postJson(this.dataset.url, {});
            showAlert(data.message || 'Re-generate preview ready.');
            if (data.preview_html && previewContent) previewContent.innerHTML = data.preview_html;
            previewContent?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            showAlert(error.message, 'danger');
        } finally {
            this.disabled = false;
        }
    });

    previewContent?.addEventListener('click', async function (event) {
        const editToggle = event.target.closest('.booking-preview-edit-toggle');
        if (editToggle) {
            const box = editToggle.closest('.booking-format-preview-box');
            const panel = box?.querySelector('.booking-preview-edit-panel');
            panel?.classList.toggle('d-none');
            editToggle.innerHTML = panel && !panel.classList.contains('d-none')
                ? '<i class="bi bi-eye me-1" aria-hidden="true"></i>Hide Edit'
                : '<i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit Preview';
            return;
        }

        // The chips are positional, so they are rewritten after every add and
        // remove — otherwise deleting instruction 2 leaves the list reading
        // 1, 3, 4. Same helper as the Supply Chain screen.
        function renumberNotes(list) {
            list.querySelectorAll('.booking-preview-note-row').forEach(function (row, i) {
                const chip = row.querySelector('.bp-note-index');
                if (chip) chip.textContent = i + 1;
            });
        }

        const addNoteBtn = event.target.closest('.booking-preview-add-note');
        if (addNoteBtn) {
            const panel = addNoteBtn.closest('.booking-preview-edit-panel');
            const list = panel?.querySelector('.booking-preview-notes-list');
            if (list) {
                const row = document.createElement('div');
                row.className = 'booking-preview-note-row';
                // Must match the server-rendered row in preview.blade.php.
                row.innerHTML = '<span class="bp-note-index" aria-hidden="true"></span><textarea name="notes[]" rows="2" class="form-control" placeholder="Instruction text"></textarea><button type="button" class="bp-remove booking-preview-remove-note"><i class="bi bi-x-lg" aria-hidden="true"></i>Remove</button>';
                list.appendChild(row);
                renumberNotes(list);
            }
            return;
        }

        const removeNoteBtn = event.target.closest('.booking-preview-remove-note');
        if (removeNoteBtn) {
            const list = removeNoteBtn.closest('.booking-preview-notes-list');
            const row = removeNoteBtn.closest('.booking-preview-note-row');
            if (row && list && list.querySelectorAll('.booking-preview-note-row').length > 1) {
                row.remove();
                renumberNotes(list);
            } else if (row) {
                row.querySelector('textarea').value = '';
            }
            return;
        }

        const cancelBtn = event.target.closest('.booking-preview-cancel');
        if (cancelBtn) {
            window.location.reload();
            return;
        }

        const generateBtn = event.target.closest('.preview-generate-po-btn');
        if (!generateBtn || generateBtn.classList.contains('is-loading')) return;
        const editForm = generateBtn.closest('.booking-preview-edit-form');
        const payload = editForm ? formDataToObject(editForm) : {};
        const isEdit = generateBtn.dataset.edit === '1';
        const isRegenerate = generateBtn.dataset.regenerate === '1';
        const confirmText = isEdit
            ? 'Save this PO edit? The PO number will stay the same.'
            : (isRegenerate ? 'Re-generate this PO? The PO number will stay the same and the revision count will increase.' : 'Generate this PO?');
        if (!window.confirm(confirmText)) return;

        setGenerateLoading(generateBtn, true);
        try {
            const data = await postJson(generateBtn.dataset.url, payload);
            showAlert(data.message || (isEdit ? 'PO edited successfully.' : 'PO re-generated successfully.'));
            if (data.preview_html && previewContent) previewContent.innerHTML = data.preview_html;
            setTimeout(function () { window.location.reload(); }, 1200);
        } catch (error) {
            showAlert('Unable to complete the request. Please try again or contact admin.', 'danger');
            setGenerateLoading(generateBtn, false);
        }
    });

    previewContent?.addEventListener('change', function (event) {
        const select = event.target.closest('.booking-delivery-destination-select');
        if (!select) return;
        const selected = select.options[select.selectedIndex];
        const panel = select.closest('.booking-preview-edit-panel');
        const nameInput = panel?.querySelector('.booking-delivery-destination-name');
        const detailsInput = panel?.querySelector('.booking-delivery-destination-details');
        if (nameInput) nameInput.value = selected?.dataset?.title || '';
        if (detailsInput && selected?.dataset?.details) detailsInput.value = selected.dataset.details;
        if (detailsInput && !select.value) detailsInput.value = '';
    });
});
</script>
@endsection
