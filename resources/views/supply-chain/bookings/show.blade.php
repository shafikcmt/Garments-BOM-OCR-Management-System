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
                <a href="{{ route('supply_chain.bookings.print', $bookingPo) }}" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Print</a>
                <a href="{{ route('supply_chain.bookings.download', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-pdf me-1"></i>Download PDF</a>
                <a href="{{ route('supply_chain.bookings.download_excel', $bookingPo) }}" target="_blank" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bookingEmailModal" title="Email this PO Booking to the supplier as a PDF attachment"><i class="bi bi-envelope me-1"></i>Email to Supplier</button>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success border-0 shadow-sm rounded-3 py-2">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger border-0 shadow-sm rounded-3 py-2">{{ session('error') }}</div>
        @endif

        @if(($emailLogs ?? collect())->isNotEmpty())
            @php($lastEmail = $emailLogs->first())
            <div class="card border-0 shadow-sm rounded-3 mb-3">
                <div class="card-body py-2 px-3 d-flex flex-wrap align-items-center gap-2 small">
                    <span class="badge {{ $lastEmail->status === 'sent' ? 'bg-success' : 'bg-danger' }}">
                        <i class="bi bi-envelope-check me-1"></i>{{ $lastEmail->status === 'sent' ? 'Emailed' : 'Send failed' }}
                    </span>
                    <span class="text-muted">
                        Last sent to <strong>{{ $lastEmail->recipients }}</strong>
                        on {{ optional($lastEmail->created_at)->format('jS M-Y, g:i A') }}
                        by {{ optional($lastEmail->sentBy)->name ?? 'Unknown' }}
                    </span>
                    @if($emailLogs->count() > 1)
                        <span class="text-muted">· {{ $emailLogs->count() }} total sends</span>
                    @endif
                </div>
            </div>
        @endif

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

<div class="modal fade" id="bookingEmailModal" tabindex="-1" aria-labelledby="bookingEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <form method="POST" action="{{ route('supply_chain.bookings.email', $bookingPo) }}"
                  style="display:flex;flex-direction:column;min-height:0;overflow:hidden;">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingEmailModalLabel"><i class="bi bi-envelope me-1"></i> Email PO Booking to Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="flex:1 1 auto;overflow-y:auto;min-height:0;">
                    <div class="alert alert-info border-0 small py-2">
                        <i class="bi bi-paperclip me-1"></i> The PO Booking PDF (<strong>{{ $bookingPo->po_no }}</strong>) will be attached automatically.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">From</label>
                        <input type="email" name="from" class="form-control" maxlength="255"
                               value="{{ old('from', $emailDefaults['from']) }}"
                               placeholder="your@email.com">
                        <div class="form-text">Used as the Reply-To address. Pre-filled with your email; you can change it.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">To <span class="text-danger">*</span></label>
                        <input type="text" name="to" class="form-control" required value="{{ old('to', $emailDefaults['to']) }}"
                               placeholder="supplier@example.com, another@example.com">
                        <div class="form-text">Pre-filled with the supplier's email. Separate multiple recipients with commas.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cc</label>
                        <input type="text" name="cc" class="form-control" value="{{ old('cc', $emailDefaults['cc']) }}"
                               placeholder="optional, comma separated">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="255" required
                               value="{{ old('subject', $emailDefaults['subject']) }}">
                    </div>

                    <div class="mb-1">
                        <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
                        <div class="btn-toolbar mb-2" role="toolbar" aria-label="Formatting">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-light border" data-rt-cmd="bold" title="Bold"><i class="bi bi-type-bold"></i></button>
                                <button type="button" class="btn btn-light border" data-rt-cmd="italic" title="Italic"><i class="bi bi-type-italic"></i></button>
                                <button type="button" class="btn btn-light border" data-rt-cmd="underline" title="Underline"><i class="bi bi-type-underline"></i></button>
                                <button type="button" class="btn btn-light border" data-rt-cmd="insertUnorderedList" title="Bullet list"><i class="bi bi-list-ul"></i></button>
                            </div>
                        </div>
                        <div id="bookingEmailBodyEditor" class="form-control" contenteditable="true"
                             style="height:220px;max-height:40vh;overflow-y:auto;font-size:14px;line-height:1.6;">{!! old('body', $emailDefaults['body']) !!}</div>
                        <textarea name="body" id="bookingEmailBodyInput" class="d-none" required>{{ old('body', $emailDefaults['body']) }}</textarea>
                        <div class="form-text">Pre-filled from the admin template. Edit the text directly — formatting is kept in the email.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Send</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const editor = document.getElementById('bookingEmailBodyEditor');
        const input = document.getElementById('bookingEmailBodyInput');

        if (editor && input) {
            const sync = function () { input.value = editor.innerHTML.trim(); };
            editor.addEventListener('input', sync);
            sync();

            document.querySelectorAll('[data-rt-cmd]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    editor.focus();
                    document.execCommand(btn.dataset.rtCmd, false, null);
                    sync();
                });
            });

            const form = input.closest('form');
            if (form) {
                form.addEventListener('submit', sync);
                form.querySelectorAll('input[type="email"], input[type="text"]').forEach(function (field) {
                    field.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                        }
                    });
                });
            }
        }

        @if($errors->any() && old('to') !== null)
            const modalEl = document.getElementById('bookingEmailModal');
            if (modalEl && window.bootstrap) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        @endif
    });
</script>
@endsection
