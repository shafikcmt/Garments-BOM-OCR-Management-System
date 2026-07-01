@php($old = old('type') === $type)
<form method="POST" action="{{ route('admin.email-templates.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="type" value="{{ $type }}">

    <div class="row g-4">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-1">{{ $heading }}</h5>
                    <p class="text-muted small mb-3">{{ $description }}</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subject</label>
                        <input type="text" name="subject" class="form-control" maxlength="255" required
                               value="{{ $old ? old('subject') : ($template->subject ?? $defaultSubject) }}">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Default To <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="default_to" class="form-control" maxlength="1000"
                                   value="{{ $old ? old('default_to') : ($template->default_to ?? '') }}"
                                   placeholder="e.g. team@humanaapparels.com">
                            <div class="form-text">Pre-fills the "To" field. Comma separated. Senders can still edit it.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Default Cc <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="default_cc" class="form-control" maxlength="1000"
                                   value="{{ $old ? old('default_cc') : ($template->default_cc ?? '') }}"
                                   placeholder="e.g. manager@humanaapparels.com">
                            <div class="form-text">Pre-fills the "Cc" field. Comma separated. Senders can still edit it.</div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold">Body (HTML)</label>
                        <textarea name="body" rows="14" class="form-control" style="font-family:monospace;font-size:13px;" required>{{ $old ? old('body') : ($template->body ?? '') }}</textarea>
                        <div class="form-text">Raw HTML allowed (e.g. &lt;p&gt;, &lt;strong&gt;, &lt;br&gt;) — admin only. Senders see this as formatted text, not code. Use the placeholders on the right.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
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
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Template</button>
    </div>
</form>
