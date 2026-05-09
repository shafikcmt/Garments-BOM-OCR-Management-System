@extends('layouts.app')

@section('title', 'Booking Format - ' . $bookingPo->po_no)

@php
    $revisionNo = max(0, (int) (($bookingData['revision_no'] ?? 0)));
@endphp

@section('styles')
<style>
    .booking-format-wrap {
        background: #f4f8fb;
        min-height: calc(100vh - 110px);
    }
    .booking-format-shell {
        max-width: 980px;
        margin: 0 auto;
    }
</style>
@endsection

@section('content')
<div class="booking-format-wrap p-2 p-md-3">
    <div class="booking-format-shell">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <div>
                <h4 class="mb-1 fw-bold text-primary">Booking Format</h4>
                <div class="small text-muted">PO {{ $bookingPo->po_no }}@if($revisionNo > 0) · R-{{ $revisionNo }}@endif. Generated PO is read-only.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="{{ route('supply_chain.bookings.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
                <button type="button" class="btn btn-warning btn-sm fw-bold booking-show-regenerate-start" data-url="{{ route('supply_chain.bookings.regenerate_preview', $bookingPo) }}"><i class="bi bi-arrow-repeat me-1"></i>Re-generate PO</button>
                <a href="{{ route('supply_chain.bookings.print', $bookingPo) }}" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
                <a href="{{ route('supply_chain.bookings.download', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-pdf me-1"></i>PDF</a>
                <a href="{{ route('supply_chain.bookings.download_excel', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            </div>
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
            ])
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const previewContent = document.getElementById('bookingShowPreviewContent');
    const alertBox = document.getElementById('bookingShowAlert');

    function showAlert(message, type = 'success') {
        if (!alertBox) return;
        alertBox.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
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
                ? '<i class="bi bi-eye me-1"></i>Hide Edit'
                : '<i class="bi bi-pencil-square me-1"></i>Edit Preview';
            return;
        }

        const addNoteBtn = event.target.closest('.booking-preview-add-note');
        if (addNoteBtn) {
            const panel = addNoteBtn.closest('.booking-preview-edit-panel');
            const list = panel?.querySelector('.booking-preview-notes-list');
            if (list) {
                const row = document.createElement('div');
                row.className = 'booking-preview-note-row';
                row.innerHTML = '<textarea name="notes[]" rows="2" class="form-control" placeholder="Instruction text"></textarea><button type="button" class="btn btn-outline-danger btn-sm booking-preview-remove-note" title="Remove"><i class="bi bi-x-lg"></i></button>';
                list.appendChild(row);
            }
            return;
        }

        const removeNoteBtn = event.target.closest('.booking-preview-remove-note');
        if (removeNoteBtn) {
            const list = removeNoteBtn.closest('.booking-preview-notes-list');
            const row = removeNoteBtn.closest('.booking-preview-note-row');
            if (row && list && list.querySelectorAll('.booking-preview-note-row').length > 1) row.remove();
            else if (row) row.querySelector('textarea').value = '';
            return;
        }

        const generateBtn = event.target.closest('.preview-generate-po-btn');
        if (!generateBtn) return;
        const editForm = generateBtn.closest('.booking-preview-edit-form');
        const payload = editForm ? formDataToObject(editForm) : {};
        if (!window.confirm('Confirm re-generate this PO? PO number will stay the same and revision count will increase.')) return;

        generateBtn.disabled = true;
        try {
            const data = await postJson(generateBtn.dataset.url, payload);
            showAlert(data.message || 'PO re-generated successfully.');
            if (data.preview_html && previewContent) previewContent.innerHTML = data.preview_html;
            setTimeout(function () { window.location.reload(); }, 1200);
        } catch (error) {
            showAlert(error.message, 'danger');
        } finally {
            generateBtn.disabled = false;
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

