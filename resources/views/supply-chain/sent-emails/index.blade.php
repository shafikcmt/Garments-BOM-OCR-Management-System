@extends('layouts.app')

@section('title', 'Sent Emails')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold text-primary"><i class="bi bi-envelope-paper me-1"></i> Sent Emails</h4>
            <div class="small text-muted">All PO Booking and Payment Request emails sent from the system.</div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3 py-2">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger border-0 shadow-sm rounded-3 py-2">{{ session('error') }}</div>
    @endif

    {{-- Filters --}}
    <div class="card border-0 shadow-sm rounded-3 mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('supply_chain.sent_emails.index') }}" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="po_booking" @selected(($filters['type'] ?? '') === 'po_booking')>PO Booking</option>
                        <option value="pra" @selected(($filters['type'] ?? '') === 'pra')>PRA</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="sent" @selected(($filters['status'] ?? '') === 'sent')>Sent</option>
                        <option value="failed" @selected(($filters['status'] ?? '') === 'failed')>Failed</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">From date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">To date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ $filters['search'] ?? '' }}" placeholder="Ref no, subject or recipient">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100" title="Apply filters"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="{{ route('supply_chain.sent_emails.index') }}" class="btn btn-outline-secondary btn-sm w-100" title="Reset filters"><i class="bi bi-x-lg me-1"></i>Reset</a>
                </div>
            </form>
        </div>
    </div>

    @if($emailLogs->total() === 0)
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-4 text-center text-muted">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                No sent emails found for the selected filters.
            </div>
        </div>
    @else
        @include('partials.email-history', [
            'emailLogs' => $emailLogs,
            'composeModalId' => 'consolidatedEmailModal',
            'composeEditorId' => 'consolidatedEmailBodyEditor',
            'composeInputId' => 'consolidatedEmailBodyInput',
            'showType' => true,
            'dynamicSendUrl' => true,
        ])

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
            <div class="small text-muted">
                Showing {{ $emailLogs->firstItem() }}–{{ $emailLogs->lastItem() }} of {{ $emailLogs->total() }}
            </div>
            {{ $emailLogs->links() }}
        </div>
    @endif
</div>

{{-- Generic compose modal: Forward / Reply / Edit set its action per row --}}
<div class="modal fade" id="consolidatedEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--gx-radius);overflow:hidden;">
            <form method="POST" action="" style="display:flex;flex-direction:column;min-height:0;overflow:hidden;">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-envelope me-1"></i> Compose Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="flex:1 1 auto;overflow-y:auto;min-height:0;">
                    <div class="alert alert-info border-0 small py-2">
                        <i class="bi bi-paperclip me-1"></i> The related document PDF will be attached automatically.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">From</label>
                        <input type="email" name="from" class="form-control" maxlength="255" value="{{ auth()->user()?->email }}" placeholder="your@email.com">
                        <div class="form-text">Used as the Reply-To address.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">To <span class="text-danger">*</span></label>
                        <input type="text" name="to" class="form-control" required placeholder="name@example.com, another@example.com">
                        <div class="form-text">Separate multiple recipients with commas.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Cc</label>
                        <input type="text" name="cc" class="form-control" placeholder="optional, comma separated">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" maxlength="255" required>
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
                        <div id="consolidatedEmailBodyEditor" class="form-control" contenteditable="true"
                             style="height:220px;max-height:40vh;overflow-y:auto;font-size:14px;line-height:1.6;"></div>
                        <textarea name="body" id="consolidatedEmailBodyInput" class="d-none" required></textarea>
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
        const editor = document.getElementById('consolidatedEmailBodyEditor');
        const input = document.getElementById('consolidatedEmailBodyInput');
        if (editor && input) {
            const sync = function () { input.value = editor.innerHTML.trim(); };
            editor.addEventListener('input', sync);

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
                form.querySelectorAll('input[type=email], input[type=text]').forEach(function (field) {
                    field.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') { e.preventDefault(); }
                    });
                });
            }
        }
    });
</script>
@endsection
