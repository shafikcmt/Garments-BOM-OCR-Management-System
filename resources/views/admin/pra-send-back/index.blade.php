@extends('layouts.app')

@section('title', 'Send PRAs Back')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'PRA Approval', 'url' => route('admin.pra-steps.index')],
        ['label' => 'Send PRAs back'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Admin / PRA Approval</div>
                <h3 class="app-hero-title mb-0">Send PRAs back to an earlier step</h3>
                <div class="small text-muted">Use this to fix a PRA that skipped a step or was signed at the wrong step. Nothing is deleted: the history stays.</div>
            </div>
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
        @endif
    @endforeach
    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('admin.pra-send-back.store') }}" id="sendBackForm">
        @csrf
        <div class="card border-0 shadow-sm mb-3" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold" for="sbStep">Send the selected PRAs back to</label>
                        <select class="form-select" name="sequence" id="sbStep" required>
                            <option value="">Choose a step</option>
                            @foreach($steps as $step)
                                <option value="{{ $step['sequence'] }}">Step {{ $step['ordinal'] }} — {{ $step['label'] }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">A PRA can only go back to an earlier step (or stay on its own), never forward.</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold" for="sbNote">Reason</label>
                        <input type="text" class="form-control" name="note" id="sbNote" maxlength="500" required placeholder="e.g. SCM Head check was not done">
                    </div>
                    <div class="col-md-3 d-grid">
                        <button type="submit" class="btn btn-warning fw-semibold" id="sbSubmit" onclick="return confirm('Send the selected PRAs back? Approvers from that step onward will have to act again.');"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Send back selected</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:44px;"><input type="checkbox" class="form-check-input" id="sbAll" aria-label="Select all PRAs"></th>
                            <th>PRA</th>
                            <th>Buyer / Supplier</th>
                            <th class="text-end">PI Amount</th>
                            <th>Waiting at</th>
                            <th>Already approved</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            @php $pra = $row['pra']; @endphp
                            <tr>
                                <td><input type="checkbox" class="form-check-input sb-pick" name="payment_request_ids[]" value="{{ $pra->id }}" aria-label="Select {{ $pra->request_no }}"></td>
                                <td class="fw-semibold"><a href="{{ route('pra_departmental.show', $pra) }}" target="_blank" rel="noopener">{{ $pra->request_no }}</a><div class="small text-muted">by {{ $pra->createdBy?->name ?? '—' }}</div></td>
                                <td>{{ $pra->buyer_name ?: '—' }}<div class="small text-muted">{{ $pra->supplier_name ?: '—' }}</div></td>
                                <td class="text-end">{{ number_format((float) $pra->total_pi_amount, 2) }}</td>
                                <td>@if($row['stage'])<span class="badge text-bg-primary">Step {{ $row['ordinal'] }} — {{ $row['stage']->label }}</span>@endif</td>
                                <td class="small">@forelse($row['done'] as $line)<div>{{ $line }}</div>@empty<span class="text-muted">Nobody yet</span>@endforelse</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-5">No PRA is part-way through approval.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>
<script>
document.getElementById('sbAll')?.addEventListener('change', function () {
    document.querySelectorAll('.sb-pick').forEach(function (box) { box.checked = this.checked; }, this);
});
</script>
@endsection
