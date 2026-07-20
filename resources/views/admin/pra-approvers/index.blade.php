@extends('layouts.app')

@section('title', 'Manage PRA Approvers')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'PRA Approval'],
        ['label' => 'Manage PRA Approvers'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-person-check" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / PRA Approval</div>
                    <h3 class="app-hero-title mb-0">Manage PRA Approvers</h3>
                </div>
            </div>
            <a href="{{ route('admin.pra-approvals.history') }}" class="btn btn-outline-primary rounded-3">
                <i class="bi bi-clock-history me-1" aria-hidden="true"></i> Approval History
            </a>
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
        @endif
    @endforeach

    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-4">
        {{-- Add approver + settings --}}
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-1">Add Approver</h5>
                    <p class="text-muted small mb-3">Add a user to the PRA approver pool. Only pooled users can be selected by creators to approve a PRA.</p>

                    <form method="POST" action="{{ route('admin.pra-approvers.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Select user</label>
                        <select name="user_id" class="form-select mb-3" required>
                            <option value="">— Choose a user —</option>
                            @foreach($availableUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-primary w-100" {{ $availableUsers->isEmpty() ? 'disabled' : '' }}>
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add to Pool
                        </button>
                        @if($availableUsers->isEmpty())
                            <div class="form-text">All users are already in the pool.</div>
                        @endif
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-1">Notification Settings</h5>
                    <p class="text-muted small mb-3">Dashboard notifications are always on. Email can be turned off here.</p>
                    <form method="POST" action="{{ route('admin.pra-approvers.settings') }}">
                        @csrf
                        @method('PUT')
                        <div class="form-check form-switch">
                            <input type="hidden" name="pra_approval_mail_enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="praMailEnabled"
                                   name="pra_approval_mail_enabled" value="1" {{ $mailEnabled ? 'checked' : '' }}
                                   onchange="this.form.submit()">
                            <label class="form-check-label fw-semibold" for="praMailEnabled">Send approval emails</label>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Approver pool table --}}
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Approver Pool <span class="badge bg-primary-subtle text-primary ms-1">{{ $approvers->count() }}</span></h5>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Approver</th>
                                    <th>Added By</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Checker</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($approvers as $approver)
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-slate-900">{{ optional($approver->user)->name ?? '—' }}</div>
                                            <div class="small text-muted">{{ optional($approver->user)->email }}</div>
                                        </td>
                                        <td class="small text-muted">{{ optional($approver->addedBy)->name ?? '—' }}</td>
                                        <td class="text-center">
                                            <form method="POST" action="{{ route('admin.pra-approvers.update', $approver) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $approver->is_active ? 0 : 1 }}">
                                                <button type="submit" class="btn btn-sm {{ $approver->is_active ? 'btn-success' : 'btn-outline-secondary' }} rounded-pill px-3">
                                                    {{ $approver->is_active ? 'Active' : 'Inactive' }}
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" action="{{ route('admin.pra-approvers.checker', $approver) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="can_check" value="{{ $approver->can_check ? 0 : 1 }}">
                                                <button type="submit" class="btn btn-sm {{ $approver->can_check ? 'btn-info text-white' : 'btn-outline-secondary' }} rounded-pill px-3"
                                                        title="Whether this user can be selected as the Checker">
                                                    {{ $approver->can_check ? 'Checker' : 'Not a checker' }}
                                                </button>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('admin.pra-approvers.destroy', $approver) }}" class="d-inline"
                                                  onsubmit="return confirm('Remove this approver from the pool? Existing approval history stays intact.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                    <i class="bi bi-trash" aria-hidden="true"></i> Remove
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-5">No approvers yet. Add a user from the pool on the left.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
