@php
    /**
     * Shared "Sent Emails" history table + actions (View/Edit/Forward/Reply/Delete).
     * Used by the PRA detail page, the PO Booking detail page, and the
     * consolidated Sent Emails page.
     *
     * Expects:
     *   $emailLogs        Collection|Paginator<EmailLog>
     *   $composeModalId   id of the compose modal to prefill
     *   $composeEditorId  id of the contenteditable body editor inside it
     *   $composeInputId   id of the hidden body textarea inside it
     * Optional:
     *   $attachmentName   fixed attachment name (per-record pages); when null it
     *                     is derived per row from the log's document.
     *   $showType         show Type + Reference columns (consolidated page)
     *   $dynamicSendUrl   emit each row's document send route so Forward/Reply/
     *                     Edit post to the correct PO/PRA (consolidated page)
     */
    // Keep a paginator intact (the consolidated Sent Emails page passes one);
    // collect() would flatten it to its meta array and iterate ints. Any other
    // input (per-record Collection, array, null) is normalised to a Collection.
    $emailLogs = $emailLogs instanceof \Illuminate\Pagination\AbstractPaginator
        ? $emailLogs
        : collect($emailLogs ?? []);
    $composeModalId = $composeModalId ?? 'sendEmailModal';
    $composeEditorId = $composeEditorId ?? 'emailBodyEditor';
    $composeInputId = $composeInputId ?? 'emailBodyInput';
    $attachmentName = $attachmentName ?? null;
    $showType = $showType ?? false;
    $dynamicSendUrl = $dynamicSendUrl ?? false;
@endphp

@if($emailLogs->isNotEmpty())
<div class="card border-0 shadow-sm rounded-3 mb-3" id="emailHistoryCard">
    @unless($showType)
    <div class="card-header bg-white border-0 pt-3 pb-0 d-flex align-items-center gap-2">
        <i class="bi bi-envelope-paper text-primary" aria-hidden="true"></i>
        <h6 class="mb-0 fw-bold">Sent Emails <span class="text-muted fw-normal">({{ $emailLogs->count() }})</span></h6>
    </div>
    @endunless
    <div class="card-body pt-2">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <thead>
                    <tr class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.03em;">
                        @if($showType)
                            <th>Type</th>
                            <th>Reference</th>
                        @endif
                        <th>Sent</th>
                        <th>Sent By</th>
                        <th>To</th>
                        <th>Cc</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Attachment</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($emailLogs as $log)
                        @php
                            $bodyId = 'emailBody-' . $log->id;
                            $isPra = $log->payment_request_id !== null;
                            $type = $isPra ? 'PRA' : 'PO Booking';
                            $needsRelation = $showType || $dynamicSendUrl || ! $attachmentName;

                            $ref = $refLink = $sendUrl = null;
                            if ($isPra) {
                                $praNo = $needsRelation ? optional($log->paymentRequest)->request_no : null;
                                $ref = $praNo ?: ('PR #' . $log->payment_request_id);
                                $refLink = route('supply_chain.payment_requests.show', $log->payment_request_id);
                                $sendUrl = route('supply_chain.payment_requests.email', $log->payment_request_id);
                                $rowAttachment = $attachmentName ?: ('PAYMENT_REQUEST_APPROVAL_' . ($praNo ?: $log->payment_request_id) . '.pdf');
                            } else {
                                $poNo = $needsRelation ? optional($log->bookingPo)->po_no : null;
                                $ref = $poNo ?: ('PO #' . $log->booking_po_id);
                                $refLink = $log->booking_po_id ? route('supply_chain.bookings.show', $log->booking_po_id) : '#';
                                $sendUrl = $log->booking_po_id ? route('supply_chain.bookings.email', $log->booking_po_id) : '';
                                $rowAttachment = $attachmentName ?: (($poNo ?: 'booking') . '_booking_format.pdf');
                            }

                            $rowData = [
                                'to' => $log->recipients,
                                'cc' => $log->cc ?? '',
                                'subject' => $log->subject,
                                'sent' => optional($log->created_at)->format('d M Y, g:i A'),
                                'by' => optional($log->sentBy)->name ?? '—',
                                'status' => $log->status,
                                'attachment' => $rowAttachment,
                            ];
                        @endphp
                        <tr>
                            @if($showType)
                                <td><span class="badge {{ $isPra ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' }}">{{ $type }}</span></td>
                                <td class="text-nowrap"><a href="{{ $refLink }}" class="fw-semibold text-decoration-none">{{ $ref }}</a></td>
                            @endif
                            <td class="text-nowrap">{{ optional($log->created_at)->format('d M Y, g:i A') }}</td>
                            <td class="text-nowrap">{{ optional($log->sentBy)->name ?? '—' }}</td>
                            <td style="max-width:180px;"><span class="d-inline-block text-truncate" style="max-width:180px;" title="{{ $log->recipients }}">{{ $log->recipients }}</span></td>
                            <td style="max-width:140px;"><span class="d-inline-block text-truncate" style="max-width:140px;" title="{{ $log->cc }}">{{ $log->cc ?: '—' }}</span></td>
                            <td style="max-width:220px;"><span class="d-inline-block text-truncate" style="max-width:220px;" title="{{ $log->subject }}">{{ $log->subject }}</span></td>
                            <td>
                                @if($log->status === 'sent')
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Sent</span>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="{{ $log->error }}">Failed</span>
                                @endif
                            </td>
                            <td class="text-nowrap"><i class="bi bi-file-earmark-pdf text-danger me-1" aria-hidden="true"></i><span class="small">{{ $rowAttachment }}</span></td>
                            <td class="text-end text-nowrap">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-secondary email-action" title="View full email"
                                            data-email-action="view" data-body-id="{{ $bodyId }}" @if($dynamicSendUrl) data-send-url="{{ $sendUrl }}" @endif @foreach($rowData as $k => $v) data-{{ $k }}="{{ $v }}" @endforeach>
                                        <i class="bi bi-eye" aria-hidden="true"></i><span class="d-none d-lg-inline ms-1">View</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary email-action" title="Duplicate and resend"
                                            data-email-action="edit" data-body-id="{{ $bodyId }}" @if($dynamicSendUrl) data-send-url="{{ $sendUrl }}" @endif @foreach($rowData as $k => $v) data-{{ $k }}="{{ $v }}" @endforeach>
                                        <i class="bi bi-pencil" aria-hidden="true"></i><span class="d-none d-lg-inline ms-1">Edit</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary email-action" title="Forward to a new recipient"
                                            data-email-action="forward" data-body-id="{{ $bodyId }}" @if($dynamicSendUrl) data-send-url="{{ $sendUrl }}" @endif @foreach($rowData as $k => $v) data-{{ $k }}="{{ $v }}" @endforeach>
                                        <i class="bi bi-arrow-right-circle" aria-hidden="true"></i><span class="d-none d-lg-inline ms-1">Forward</span>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary email-action" title="Reply to the same recipient"
                                            data-email-action="reply" data-body-id="{{ $bodyId }}" @if($dynamicSendUrl) data-send-url="{{ $sendUrl }}" @endif @foreach($rowData as $k => $v) data-{{ $k }}="{{ $v }}" @endforeach>
                                        <i class="bi bi-reply" aria-hidden="true"></i><span class="d-none d-lg-inline ms-1">Reply</span>
                                    </button>
                                    @if($log->canBeDeletedBy(auth()->user()))
                                        <form method="POST" action="{{ route('emails.destroy', $log) }}" class="d-inline"
                                              onsubmit="return confirm('Remove this email from the history? This only hides the record — the email already sent to the recipient is not affected.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger" title="Remove from history">
                                                <i class="bi bi-trash" aria-hidden="true"></i><span class="d-none d-lg-inline ms-1">Delete</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                                {{-- Hidden HTML body for View / Forward / Reply prefill --}}
                                <div id="{{ $bodyId }}" class="d-none">{!! $log->body !!}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Read-only View modal --}}
<div class="modal fade" id="emailViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:var(--gx-radius);overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-envelope-open me-1" aria-hidden="true"></i> Email Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-2 small">
                    <dt class="col-sm-2 text-muted">Sent</dt><dd class="col-sm-10" id="viewEmailSent"></dd>
                    <dt class="col-sm-2 text-muted">Sent By</dt><dd class="col-sm-10" id="viewEmailBy"></dd>
                    <dt class="col-sm-2 text-muted">To</dt><dd class="col-sm-10" id="viewEmailTo"></dd>
                    <dt class="col-sm-2 text-muted">Cc</dt><dd class="col-sm-10" id="viewEmailCc"></dd>
                    <dt class="col-sm-2 text-muted">Subject</dt><dd class="col-sm-10 fw-semibold" id="viewEmailSubject"></dd>
                    <dt class="col-sm-2 text-muted">Attachment</dt><dd class="col-sm-10" id="viewEmailAttachment"></dd>
                </dl>
                <hr>
                <div id="viewEmailBody" style="font-size:14px;line-height:1.6;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const composeModalId = @json($composeModalId);
        const editorId = @json($composeEditorId);
        const inputId = @json($composeInputId);

        const quote = function (d, bodyHtml) {
            return bodyHtml +
                '<br><br>---------- Original message ----------<br>' +
                'To: ' + (d.to || '') + '<br>' +
                (d.cc ? 'Cc: ' + d.cc + '<br>' : '') +
                'Subject: ' + (d.subject || '') + '<br><br>';
        };

        document.querySelectorAll('.email-action').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const action = btn.dataset.emailAction;
                const d = btn.dataset;
                const bodyEl = document.getElementById(d.bodyId);
                const bodyHtml = bodyEl ? bodyEl.innerHTML : '';

                if (action === 'view') {
                    document.getElementById('viewEmailSent').textContent = d.sent || '';
                    document.getElementById('viewEmailBy').textContent = d.by || '';
                    document.getElementById('viewEmailTo').textContent = d.to || '';
                    document.getElementById('viewEmailCc').textContent = d.cc || '—';
                    document.getElementById('viewEmailSubject').textContent = d.subject || '';
                    document.getElementById('viewEmailAttachment').textContent = d.attachment || '';
                    document.getElementById('viewEmailBody').innerHTML = bodyHtml;
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('emailViewModal')).show();
                    return;
                }

                const modalEl = document.getElementById(composeModalId);
                if (!modalEl) return;
                const form = modalEl.querySelector('form');

                // On the consolidated page each row targets its own PO/PRA send route.
                if (form && d.sendUrl) form.action = d.sendUrl;

                let subject = d.subject || '';
                let to = d.to || '';
                let cc = d.cc || '';
                let body = bodyHtml;

                if (action === 'forward') {
                    subject = 'Fwd: ' + subject;
                    to = '';
                    body = quote(d, bodyHtml);
                } else if (action === 'reply') {
                    subject = 'Re: ' + subject;
                    body = quote(d, bodyHtml);
                }
                // 'edit' keeps subject/to/cc/body identical (duplicate & resend).

                if (form.querySelector('[name=subject]')) form.querySelector('[name=subject]').value = subject;
                if (form.querySelector('[name=to]')) form.querySelector('[name=to]').value = to;
                if (form.querySelector('[name=cc]')) form.querySelector('[name=cc]').value = cc;

                const editor = document.getElementById(editorId);
                const input = document.getElementById(inputId);
                if (editor) editor.innerHTML = body;
                if (input) input.value = body;

                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        });
    });
</script>
@endif
