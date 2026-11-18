@php($old = old('type') === $type)
@php($canManageRecipients = $canManageRecipients ?? true)
<form method="POST" action="{{ route('admin.email-templates.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="type" value="{{ $type }}">

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-1">{{ $heading }}</h5>
                    <p class="text-muted small mb-3">{{ $description }}</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subject</label>
                        <input type="text" name="subject" class="form-control" maxlength="255" required
                               value="{{ $old ? old('subject') : ($template->subject ?? $defaultSubject) }}">
                    </div>

                    {{-- Recipients stay administrator-only: changing them silently
                         redirects future mail. Disabled inputs post nothing, so a
                         non-admin save simply leaves the stored lists alone — the
                         controller re-checks the same rule regardless. --}}
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Default To <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="default_to" class="form-control" maxlength="1000"
                                   value="{{ $old ? old('default_to') : ($template->default_to ?? '') }}"
                                   placeholder="e.g. team@humanaapparels.com" @disabled(! $canManageRecipients)>
                            <div class="form-text">
                                Pre-fills the "To" field. Comma separated. Senders can still edit it.
                                @unless($canManageRecipients) Administrator only. @endunless
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Default Cc <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="default_cc" class="form-control" maxlength="1000"
                                   value="{{ $old ? old('default_cc') : ($template->default_cc ?? '') }}"
                                   placeholder="e.g. manager@humanaapparels.com" @disabled(! $canManageRecipients)>
                            <div class="form-text">
                                Pre-fills the "Cc" field. Comma separated. Senders can still edit it.
                                @unless($canManageRecipients) Administrator only. @endunless
                            </div>
                        </div>
                    </div>

                    @php($bodyValue = $old ? old('body') : ($template->body ?? ''))
                    <div class="mb-2 tpl-editor" data-labels='@json($placeholders)'>
                        <label class="form-label fw-semibold">Email message</label>
                        <div class="tpl-toolbar d-flex flex-wrap gap-1 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="bold"><i class="bi bi-type-bold me-1" aria-hidden="true"></i>Bold</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="italic"><i class="bi bi-type-italic me-1" aria-hidden="true"></i>Italic</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="insertUnorderedList"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>Bullet list</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="undo"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Undo</button>
                        </div>
                        <div class="tpl-surface form-control" contenteditable="true" role="textbox" aria-multiline="true" style="min-height:260px;height:auto;line-height:1.6;"></div>
                        <textarea name="body" rows="14" class="form-control d-none tpl-source" style="font-family:monospace;font-size:13px;" required>{{ $bodyValue }}</textarea>

                        <div class="small fw-semibold mt-3 mb-1">Insert data from the document <span class="text-muted fw-normal">(click where you want it, then click an item)</span></div>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($placeholders as $token => $desc)
                                <button type="button" class="btn btn-sm btn-outline-primary" data-token="{{ $token }}">{{ $desc }}</button>
                            @endforeach
                        </div>
                        <div class="form-text">Type your message as in a normal letter. The blue boxes are filled in automatically (for example the buyer name) when the email opens. Senders can still change the text before sending.</div>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input tpl-html-toggle" type="checkbox" id="tplHtml{{ $type }}">
                            <label class="form-check-label small text-muted" for="tplHtml{{ $type }}">Advanced: edit HTML code</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-2">Available placeholders</h6>
                    <p class="text-muted small mb-3">These are replaced with the document's actual data when the email form opens.</p>
                    <ul class="list-unstyled mb-0 small">
                        @foreach($placeholders as $token => $desc)
                            <li class="d-flex justify-content-between gap-2 py-1 border-bottom">
                                <code>{{ $token }}</code>
                                <span class="text-muted text-end">{{ $desc }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Template</button>
    </div>
</form>

