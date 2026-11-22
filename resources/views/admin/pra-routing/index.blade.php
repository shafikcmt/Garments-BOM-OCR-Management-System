@extends('layouts.app')

@section('title', 'PRA Approval Routing')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'PRA Approval'],
        ['label' => 'PRA Approval Routing'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
            <div>
                <div class="app-hero-eyebrow">Admin / PRA Approval</div>
                <h3 class="app-hero-title mb-0">PRA Approval Routing</h3>
            </div>
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

    @unless($available)
        <div class="alert alert-warning border-0 shadow-sm rounded-3">This screen needs a database update that has not run yet. Routing stays automatic until it does.</div>
    @endunless

    <div class="alert alert-info border-0 shadow-sm rounded-3 small">
        <div class="fw-semibold mb-1">How it works</div>
        Each PRA moves through five steps. By default every step goes to the right department automatically
        (the Merchandising steps only to people who have access to the PRA's buyer).
        Choose people here to decide a step yourself: <strong>for all buyers</strong>, or <strong>for one buyer</strong>
        (a buyer rule is used instead of the all-buyers rule for that buyer). Any one of the chosen people can complete the step.
        Leave a step empty to keep the automatic routing. Changes apply to PRAs from now on, including ones already waiting.
    </div>

    @foreach($steps as $step)
        <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="mb-1">Step {{ $step['sequence'] }} &mdash; {{ $step['label'] }}</h5>
                        <div class="text-muted small">Automatic: {{ $step['automatic'] }}</div>
                    </div>
                    @if(count($step['global_ids']) || count($step['buyer_rules']))
                        <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis">Custom routing</span>
                    @else
                        <span class="badge rounded-pill bg-light text-muted border">Automatic</span>
                    @endif
                </div>

                <div class="row g-4">
                    {{-- All buyers --}}
                    <div class="col-12 col-xl-6">
                        <form method="POST" action="{{ route('admin.pra-routing.save', $step['sequence']) }}">
                            @csrf
                            <label class="form-label fw-semibold">Approvers for all buyers</label>
                            <input type="search" class="form-control form-control-sm mb-2 js-user-filter" placeholder="Type to find a person" aria-label="Find a person">
                            <div class="border rounded-3 p-2 mb-2 js-user-list" style="max-height:200px;overflow-y:auto;">
                                @foreach($users as $user)
                                    <label class="d-flex align-items-center gap-2 px-2 py-1 mb-0" style="cursor:pointer;" data-name="{{ strtolower($user->name . ' ' . $user->email) }}">
                                        <input type="checkbox" class="form-check-input mt-0" name="user_ids[]" value="{{ $user->id }}" {{ in_array($user->id, $step['global_ids'], true) ? 'checked' : '' }}>
                                        <span>{{ $user->name }} <span class="text-muted small">({{ $user->email }})</span></span>
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm" {{ $available ? '' : 'disabled' }}>
                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Save for all buyers
                            </button>
                            <span class="text-muted small ms-2">Untick everyone and save to go back to automatic.</span>
                        </form>
                    </div>

                    {{-- One buyer --}}
                    <div class="col-12 col-xl-6">
                        <label class="form-label fw-semibold">Buyer-specific approvers</label>
                        @forelse($step['buyer_rules'] as $rule)
                            <div class="d-flex justify-content-between align-items-start gap-2 border rounded-3 px-3 py-2 mb-2">
                                <div class="min-w-0">
                                    <div class="fw-semibold small">{{ $rule['buyer'] }}</div>
                                    <div class="small text-muted">{{ implode(', ', $rule['users']) ?: '—' }}</div>
                                </div>
                                <form method="POST" action="{{ route('admin.pra-routing.remove_buyer', [$step['sequence'], $rule['buyer_id']]) }}" onsubmit="return confirm('Remove this buyer rule?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button>
                                </form>
                            </div>
                        @empty
                            <p class="text-muted small mb-2">No buyer-specific rule. Every buyer follows the all-buyers rule above.</p>
                        @endforelse

                        <details class="mt-2">
                            <summary class="small fw-semibold text-primary" style="cursor:pointer;">Add or change a buyer rule</summary>
                            <form method="POST" action="{{ route('admin.pra-routing.save_buyer', $step['sequence']) }}" class="mt-2">
                                @csrf
                                <select name="buyer_id" class="form-select form-select-sm mb-2" required aria-label="Buyer">
                                    <option value="">— Choose a buyer —</option>
                                    @foreach($buyers as $buyer)
                                        <option value="{{ $buyer->id }}">{{ $buyer->buyer_name }}</option>
                                    @endforeach
                                </select>
                                <input type="search" class="form-control form-control-sm mb-2 js-user-filter" placeholder="Type to find a person" aria-label="Find a person">
                                <div class="border rounded-3 p-2 mb-2 js-user-list" style="max-height:180px;overflow-y:auto;">
                                    @foreach($users as $user)
                                        <label class="d-flex align-items-center gap-2 px-2 py-1 mb-0" style="cursor:pointer;" data-name="{{ strtolower($user->name . ' ' . $user->email) }}">
                                            <input type="checkbox" class="form-check-input mt-0" name="user_ids[]" value="{{ $user->id }}">
                                            <span>{{ $user->name }} <span class="text-muted small">({{ $user->email }})</span></span>
                                        </label>
                                    @endforeach
                                </div>
                                <button type="submit" class="btn btn-outline-primary btn-sm" {{ $available ? '' : 'disabled' }}>
                                    <i class="bi bi-check2 me-1" aria-hidden="true"></i> Save buyer rule
                                </button>
                                <span class="text-muted small ms-2">Saving for a buyer that already has a rule replaces it.</span>
                            </form>
                        </details>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Quick filter for the long people lists: hides rows, never unticks them.
        document.querySelectorAll('.js-user-filter').forEach(function (input) {
            const list = input.parentElement.querySelector('.js-user-list');
            input.addEventListener('input', function () {
                const term = input.value.trim().toLowerCase();
                list.querySelectorAll('label[data-name]').forEach(function (row) {
                    row.style.display = row.dataset.name.indexOf(term) === -1 ? 'none' : '';
                });
            });
        });
    });
</script>
@endsection
