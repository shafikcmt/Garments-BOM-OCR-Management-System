@extends('layouts.app')

@section('title', 'PI / PRA Settings')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Settings'],
        ['label' => 'PI / PRA Settings'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Settings</div>
                    <h3 class="app-hero-title mb-0">PI / PRA Settings</h3>
                </div>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm rounded-3">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.payment-settings.update') }}" enctype="multipart/form-data">
        @csrf

        <div class="row g-4">
            {{-- Payment Require Date --}}
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                    <div class="card-body p-4">
                        <h5 class="mb-1">Payment Require Date</h5>
                        <p class="text-muted small mb-3">
                            The Payment Require Date is counted forward from the apply/created date using Bangladesh
                            working days only — Friday and Saturday are skipped, so the result always lands on a working
                            day (Sunday–Thursday).
                        </p>

                        <label class="form-label fw-semibold">Working days to add</label>
                        <div class="input-group" style="max-width:260px;">
                            <input type="number" name="pra_working_days" min="1" max="60"
                                   class="form-control" value="{{ old('pra_working_days', $workingDays) }}" required>
                            <span class="input-group-text">working days</span>
                        </div>
                        <div class="form-text">Default is 7 working days. Used when no manual date is entered on the PRA preview.</div>
                    </div>
                </div>
            </div>

            {{-- The workflow switch. NEW PRAs only — a PRA's flow is fixed on its
                 own record when it is created, so anything already in progress
                 carries on exactly as it started whatever this is set to. --}}
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                    <div class="card-body p-4">
                        <h5 class="mb-1">Approval Workflow for New PRAs</h5>
                        <p class="text-muted small mb-3">
                            Chooses which approval process a payment request enters when it is created.
                            Payment requests already in progress are not affected and finish on the
                            process they started on.
                        </p>

                        <div class="form-check form-switch mb-2">
                            <input type="hidden" name="pra_departmental_flow_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" value="1"
                                   id="departmentalFlowToggle" name="pra_departmental_flow_enabled"
                                   {{ old('pra_departmental_flow_enabled', $departmentalFlowEnabled) ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="departmentalFlowToggle">
                                Use the departmental approval chain
                            </label>
                        </div>
                        <div class="form-text mb-3">
                            On: the steps set on
                            <a href="{{ route('admin.pra-steps.index') }}">Admin &rsaquo; PRA Approval Steps</a>,
                            routed automatically.
                            Off: the creator picks a checker and approvers when raising the request.
                        </div>

                        {{-- Moved here from the PRA Approvers page: it governs the emails of BOTH
                             approval processes, so it belongs with the general PRA settings. --}}
                        <div class="form-check form-switch mb-1 pt-3 border-top">
                            <input type="hidden" name="pra_approval_mail_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" value="1"
                                   id="praMailEnabled" name="pra_approval_mail_enabled"
                                   {{ old('pra_approval_mail_enabled', $mailEnabled) ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="praMailEnabled">Send approval emails</label>
                        </div>
                        <div class="form-text mb-3">Approval requests and results are also emailed. In-app notifications are always on.</div>

                        @if($activeApproverCount === 0)
                            <div class="alert alert-warning border-0 small mb-0">
                                <strong>No approvers are configured.</strong>
                                With this switch off, a new payment request has nobody to send to and is
                                saved without any approval request at all. Add approvers under
                                <a href="{{ route('admin.pra-approvers.index') }}">Admin &rsaquo; PRA Approvers</a>
                                before turning it off.
                            </div>
                        @elseif($activeCheckerCount === 0)
                            <div class="alert alert-info border-0 small mb-0">
                                No approver is marked as a checker, so with this switch off a new payment
                                request goes straight to approvers with no checking step. Set one under
                                <a href="{{ route('admin.pra-approvers.index') }}">Admin &rsaquo; PRA Approvers</a>
                                if a check is wanted.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Signatures --}}
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                    <div class="card-body p-4">
                        <h5 class="mb-1">Digital Signatures</h5>
                        <p class="text-muted small mb-4">
                            Configure the signature shown in the PRA footer for each officer. When the toggle is on and an
                            image is uploaded, the signature appears above the "Signature &amp; Date" line on the PDF and
                            preview. Otherwise the line stays blank for manual signing.
                        </p>

                        <div class="row g-4">
                            @foreach($officers as $slug => $officer)
                                <div class="col-12 col-xl-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <h6 class="fw-bold mb-3">{{ $officer['title'] }}</h6>

                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold">Officer name</label>
                                            <input type="text" name="officers[{{ $slug }}][name]" maxlength="120"
                                                   class="form-control form-control-sm"
                                                   value="{{ old("officers.$slug.name", $officer['name']) }}"
                                                   placeholder="e.g. Md. Rahim">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold">Designation</label>
                                            <input type="text" name="officers[{{ $slug }}][designation]" maxlength="120"
                                                   class="form-control form-control-sm"
                                                   value="{{ old("officers.$slug.designation", $officer['designation']) }}"
                                                   placeholder="e.g. Supply Chain Officer">
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold">Signature image</label>
                                            @if($officer['image_url'])
                                                <div class="border rounded-3 p-2 mb-2 bg-light text-center">
                                                    <img src="{{ $officer['image_url'] }}" alt="{{ $officer['title'] }} signature"
                                                         style="max-height:60px;max-width:100%;object-fit:contain;">
                                                </div>
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" value="1"
                                                           name="officers[{{ $slug }}][remove_image]"
                                                           id="remove_{{ $slug }}">
                                                    <label class="form-check-label small text-danger" for="remove_{{ $slug }}">
                                                        Remove current signature
                                                    </label>
                                                </div>
                                            @endif
                                            <input type="file" name="officers[{{ $slug }}][image]"
                                                   class="form-control form-control-sm" accept="image/png,image/jpeg">
                                            <div class="form-text">PNG or JPG, max 2 MB. Transparent PNG recommended.</div>
                                        </div>

                                        <div class="form-check form-switch">
                                            <input type="hidden" name="officers[{{ $slug }}][enabled]" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch" value="1"
                                                   name="officers[{{ $slug }}][enabled]" id="enabled_{{ $slug }}"
                                                   {{ old("officers.$slug.enabled", $officer['enabled']) ? 'checked' : '' }}>
                                            <label class="form-check-label fw-semibold" for="enabled_{{ $slug }}">
                                                Show signature on PRA PDF
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Settings</button>
        </div>
    </form>
</div>
@endsection
