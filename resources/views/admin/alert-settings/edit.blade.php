@extends('layouts.app')

@section('title', 'Alert Settings')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-bell"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / Settings</div>
                    <h3 class="app-hero-title mb-0">PI Missing Alert Settings</h3>
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

    <form method="POST" action="{{ route('admin.alert-settings.update') }}">
        @csrf
        @method('PUT')

        <div class="row g-4">
            {{-- Alert Days --}}
            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h5 class="mb-1">Alert Timing</h5>
                        <p class="text-muted small mb-3">When should the system flag a PO as PI-missing?</p>

                        <label class="form-label fw-semibold">Alert after X days of PO generation</label>
                        <div class="input-group" style="max-width:260px;">
                            <input type="number" name="pi_alert_days" min="1" max="365"
                                   class="form-control" value="{{ old('pi_alert_days', $piAlertDays) }}" required>
                            <span class="input-group-text">days</span>
                        </div>
                        <div class="form-text">Default is 3 days. The PI missing alert triggers once a PO stays without PI beyond this period.</div>
                    </div>
                </div>
            </div>

            {{-- Department Visibility --}}
            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h5 class="mb-1">Department Visibility</h5>
                        <p class="text-muted small mb-3">Only selected departments will receive this alert in their notification panel.</p>

                        <div class="row row-cols-1 row-cols-sm-2 g-2">
                            @foreach($departmentOptions as $value => $label)
                                <div class="col">
                                    <label class="d-flex align-items-center gap-2 border rounded-3 px-3 py-2 mb-0" style="cursor:pointer;">
                                        <input type="checkbox" class="form-check-input mt-0" name="pi_alert_departments[]"
                                               value="{{ $value }}"
                                               {{ in_array($value, old('pi_alert_departments', $selectedDepartments), true) ? 'checked' : '' }}>
                                        <span>{{ $label }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text">If none selected, alert falls back to PO creator and file uploader only.</div>
                    </div>
                </div>
            </div>

            {{-- Mail Notification --}}
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:14px;">
                    <div class="card-body p-4">
                        <h5 class="mb-1">Mail Notification</h5>
                        <p class="text-muted small mb-3">Optionally send the PI missing alert by email.</p>

                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="pi_alert_mail_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="piAlertMailEnabled" name="pi_alert_mail_enabled" value="1"
                                   {{ old('pi_alert_mail_enabled', $mailEnabled) ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="piAlertMailEnabled">Send PI missing alert email</label>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-5">
                                <label class="form-label fw-semibold">Mail recipients</label>
                                <select name="pi_alert_mail_recipients" class="form-select" id="piAlertMailMode">
                                    <option value="department_users" {{ old('pi_alert_mail_recipients', $mailRecipientsMode) === 'department_users' ? 'selected' : '' }}>
                                        Selected department users
                                    </option>
                                    <option value="specific" {{ old('pi_alert_mail_recipients', $mailRecipientsMode) === 'specific' ? 'selected' : '' }}>
                                        Specific email list
                                    </option>
                                </select>
                            </div>
                            <div class="col-12 col-md-7">
                                <label class="form-label fw-semibold">Specific email addresses</label>
                                <textarea name="pi_alert_mail_emails" rows="2" class="form-control"
                                          placeholder="email1@example.com, email2@example.com">{{ old('pi_alert_mail_emails', $mailEmails) }}</textarea>
                                <div class="form-text">Comma or newline separated. Used only when "Specific email list" is chosen.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Settings</button>
        </div>
    </form>
</div>
@endsection
