@extends('layouts.app')

@section('title', 'PRA Approval Routing')

@section('content')
@php
    $usersById = $users->keyBy('id');
    $namesOf = fn (array $ids) => collect($ids)->map(fn ($id) => $usersById[$id]['name'] ?? null)->filter()->values()->all();
    $stepData = collect($steps)->mapWithKeys(fn ($step) => [$step['sequence'] => [
        'label' => $step['label'],
        'automatic' => $step['automatic'],
        'global_ids' => $step['global_ids'],
        'buyer_rules' => collect($step['buyer_rules'])->mapWithKeys(fn ($rule) => [$rule['buyer_id'] => $rule['ids']])->all(),
    ]]);
@endphp

<style>
    .rt-step { display:flex; gap:16px; padding:18px 20px; border:1px solid #e3e8f4; border-radius:16px; background:#fff; }
    .rt-step + .rt-step { margin-top:12px; }
    .rt-num { flex:0 0 38px; height:38px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; background:#2f5bea; }
    .rt-body { flex:1 1 auto; min-width:0; }
    .rt-title { font-weight:700; font-size:15px; color:#0f1b4d; }
    .rt-auto { font-size:12px; color:#6b7691; }
    .rt-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
    .rt-chip { background:#eef3ff; color:#1d3a9a; border-radius:999px; padding:3px 10px; font-size:12px; font-weight:600; }
    .rt-chip.is-auto { background:#f1f3f8; color:#6b7691; font-weight:500; }
    .rt-rule { margin-top:10px; padding:8px 12px; border-radius:12px; background:#f7f9fd; display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
    .rt-actions { flex:0 0 auto; display:flex; flex-direction:column; gap:6px; align-items:stretch; }
    .rt-list { max-height:340px; overflow-y:auto; border:1px solid #e3e8f4; border-radius:12px; }
    .rt-person { display:flex; align-items:center; gap:10px; padding:8px 12px; margin:0; cursor:pointer; border-bottom:1px solid #f0f2f8; }
    .rt-person:last-child { border-bottom:0; }
    .rt-person:hover { background:#f7f9fd; }
    .rt-person.is-checked { background:#eef3ff; }
    .rt-role { font-size:11px; color:#6b7691; }
    @media (max-width: 767px) { .rt-step { flex-direction:column; } .rt-actions { flex-direction:row; } }
</style>

<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'PRA Approval'],
        ['label' => 'PRA Approval Routing'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / PRA Approval</div>
                    <h3 class="app-hero-title mb-0">PRA Approval Routing</h3>
                    <div class="small text-muted">Choose who approves each step of a Payment Request Approval.</div>
                </div>
            </div>
            <a href="{{ route('admin.pra-steps.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ol me-1" aria-hidden="true"></i>Edit steps &amp; signature boxes</a>
            <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#routingHelp" aria-expanded="false">
                <i class="bi bi-question-circle me-1" aria-hidden="true"></i>How it works
            </button>
        </div>
        <div class="collapse mt-3" id="routingHelp">
            <ul class="small text-muted mb-0 ps-3">
                <li>A PRA moves through five steps. Unless you choose people, each step goes to the right department <strong>automatically</strong> (the Merchandising steps only to people who have access to the PRA's buyer).</li>
                <li>Press <strong>Change approvers</strong> to choose people for a step, for <strong>all buyers</strong> or for <strong>one buyer</strong>. A buyer rule is used instead of the all-buyers rule for that buyer.</li>
                <li>Any <strong>one</strong> of the chosen people can complete the step. People you choose do not need buyer access.</li>
                <li>Clear everyone and save to go back to automatic. Changes apply to every PRA from now on, including ones already waiting.</li>
            </ul>
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

    @foreach($steps as $step)
        @php $isCustom = count($step['global_ids']) || count($step['buyer_rules']); @endphp
        <div class="rt-step">
            <div class="rt-num">{{ $step['sequence'] }}</div>
            <div class="rt-body">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="rt-title">{{ $step['label'] }}</span>
                    @if($isCustom)
                        <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis">Custom</span>
                    @else
                        <span class="badge rounded-pill bg-light text-muted border">Automatic</span>
                    @endif
                </div>

                <div class="rt-chips">
                    @if(count($step['global_ids']))
                        <span class="small text-muted align-self-center me-1">All buyers:</span>
                        @foreach($namesOf($step['global_ids']) as $name)
                            <span class="rt-chip">{{ $name }}</span>
                        @endforeach
                    @else
                        <span class="rt-chip is-auto"><i class="bi bi-magic me-1" aria-hidden="true"></i>{{ $step['automatic'] }}</span>
                    @endif
                </div>

                @foreach($step['buyer_rules'] as $rule)
                    <div class="rt-rule">
                        <div class="min-w-0">
                            <div class="small fw-semibold"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>{{ $rule['buyer'] }}</div>
                            <div class="rt-chips mt-1">
                                @foreach($rule['users'] as $name)<span class="rt-chip">{{ $name }}</span>@endforeach
                            </div>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button type="button" class="btn btn-sm btn-outline-primary js-open-routing" data-seq="{{ $step['sequence'] }}" data-mode="buyer" data-buyer="{{ $rule['buyer_id'] }}">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                            </button>
                            <form method="POST" action="{{ route('admin.pra-routing.remove_buyer', [$step['sequence'], $rule['buyer_id']]) }}" onsubmit="return confirm('Remove this buyer rule?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="rt-actions">
                <button type="button" class="btn btn-primary btn-sm js-open-routing" data-seq="{{ $step['sequence'] }}" data-mode="all" {{ $available ? '' : 'disabled' }}>
                    <i class="bi bi-people me-1" aria-hidden="true"></i>Change approvers
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm js-open-routing" data-seq="{{ $step['sequence'] }}" data-mode="buyer" data-buyer="" {{ $available ? '' : 'disabled' }}>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add buyer rule
                </button>
            </div>
        </div>
    @endforeach
</div>

{{-- ONE SHARED DIALOG for every step. The people list is built once by the script
     below and ticked from the step's current choice, so the page stays short. --}}
<div class="modal fade" id="routingModal" tabindex="-1" aria-labelledby="routingModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:18px;">
            <form method="POST" id="routingForm" action="">
                @csrf
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0" id="routingModalTitle">Approvers</h5>
                        <div class="small text-muted" id="routingModalSub"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="btn-group w-100 mb-3" role="group" aria-label="Who this applies to">
                        <input type="radio" class="btn-check" name="routing_mode" id="modeAll" value="all" autocomplete="off">
                        <label class="btn btn-outline-primary btn-sm" for="modeAll">All buyers</label>
                        <input type="radio" class="btn-check" name="routing_mode" id="modeBuyer" value="buyer" autocomplete="off">
                        <label class="btn btn-outline-primary btn-sm" for="modeBuyer">One buyer</label>
                    </div>

                    <div class="mb-3" id="routingBuyerWrap" hidden>
                        <label class="form-label small fw-semibold mb-1" for="routingBuyer">Buyer</label>
                        <select name="buyer_id" id="routingBuyer" class="form-select form-select-sm">
                            <option value="">— Choose a buyer —</option>
                            @foreach($buyers as $buyer)
                                <option value="{{ $buyer->id }}">{{ $buyer->buyer_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="d-flex gap-2 mb-2">
                        <input type="search" class="form-control form-control-sm" id="routingSearch" placeholder="Search by name or email" aria-label="Search people" autocomplete="off">
                        <div class="form-check form-switch align-self-center text-nowrap mb-0">
                            <input class="form-check-input" type="checkbox" id="routingOnlySelected">
                            <label class="form-check-label small" for="routingOnlySelected">Selected only</label>
                        </div>
                    </div>
                    <div class="small text-muted mb-2"><strong id="routingCount">0</strong> selected. Any one of them can complete this step.</div>

                    <div class="rt-list" id="routingList"></div>
                    <div class="small text-muted mt-2">Leave everyone unticked and save to use the automatic routing.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="routingSave"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const steps = @json($stepData);
        const people = @json($users);
        const urlAll = @json(route('admin.pra-routing.save', 0));
        const urlBuyer = @json(route('admin.pra-routing.save_buyer', 0));

        const modalEl = document.getElementById('routingModal');
        if (!modalEl || !window.bootstrap) return;
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        const form = document.getElementById('routingForm');
        const list = document.getElementById('routingList');
        const search = document.getElementById('routingSearch');
        const onlySelected = document.getElementById('routingOnlySelected');
        const countEl = document.getElementById('routingCount');
        const buyerWrap = document.getElementById('routingBuyerWrap');
        const buyerSelect = document.getElementById('routingBuyer');
        const modeAll = document.getElementById('modeAll');
        const modeBuyer = document.getElementById('modeBuyer');
        const titleEl = document.getElementById('routingModalTitle');
        const subEl = document.getElementById('routingModalSub');

        let seq = null;

        // The people list is drawn once; ticking and ordering happen on open.
        people.forEach(function (p) {
            const label = document.createElement('label');
            label.className = 'rt-person';
            label.dataset.id = p.id;
            label.dataset.name = (p.name + ' ' + p.email + ' ' + p.role).toLowerCase();
            label.innerHTML = '<input type="checkbox" class="form-check-input mt-0" name="user_ids[]" value="' + p.id + '">' +
                '<span class="flex-grow-1"><span class="fw-semibold"></span> <span class="rt-role"></span><div class="rt-role email"></div></span>';
            label.querySelector('.fw-semibold').textContent = p.name;
            label.querySelector('.rt-role').textContent = p.role ? '· ' + p.role : '';
            label.querySelector('.email').textContent = p.email;
            list.appendChild(label);
        });

        const rows = Array.from(list.querySelectorAll('.rt-person'));

        function currentIds() {
            const step = steps[seq] || { global_ids: [], buyer_rules: {} };
            if (modeBuyer.checked) {
                return (step.buyer_rules[buyerSelect.value] || []).map(Number);
            }
            return (step.global_ids || []).map(Number);
        }

        function applyFilter() {
            const term = search.value.trim().toLowerCase();
            rows.forEach(function (row) {
                const box = row.querySelector('input');
                const hide = (term && row.dataset.name.indexOf(term) === -1) || (onlySelected.checked && !box.checked);
                // d-flex style rows would beat a plain display:none, so the hide is !important.
                if (hide) { row.style.setProperty('display', 'none', 'important'); } else { row.style.removeProperty('display'); }
            });
        }

        function refreshCount() {
            const checked = rows.filter(function (r) { return r.querySelector('input').checked; });
            countEl.textContent = checked.length;
            rows.forEach(function (r) { r.classList.toggle('is-checked', r.querySelector('input').checked); });
        }

        // Tick the step's current people and float them to the top of the list.
        function loadSelection() {
            const ids = currentIds();
            rows.forEach(function (row) { row.querySelector('input').checked = ids.indexOf(Number(row.dataset.id)) !== -1; });
            rows.slice().sort(function (a, b) {
                const ca = a.querySelector('input').checked ? 0 : 1;
                const cb = b.querySelector('input').checked ? 0 : 1;
                return ca - cb;
            }).forEach(function (row) { list.appendChild(row); });
            list.scrollTop = 0;
            refreshCount();
            applyFilter();
        }

        function setMode(mode) {
            const buyer = mode === 'buyer';
            modeAll.checked = !buyer;
            modeBuyer.checked = buyer;
            buyerWrap.hidden = !buyer;
            buyerSelect.required = buyer;
            form.action = (buyer ? urlBuyer : urlAll).replace(/0$/, seq).replace(/\/0\/buyer$/, '/' + seq + '/buyer');
            subEl.textContent = buyer ? 'For one buyer only' : 'For every buyer';
            if (!buyer) buyerSelect.value = '';
        }

        document.querySelectorAll('.js-open-routing').forEach(function (button) {
            button.addEventListener('click', function () {
                seq = button.dataset.seq;
                titleEl.textContent = 'Step ' + seq + ' — ' + (steps[seq] ? steps[seq].label : '');
                search.value = '';
                onlySelected.checked = false;
                setMode(button.dataset.mode);
                if (button.dataset.mode === 'buyer') buyerSelect.value = button.dataset.buyer || '';
                loadSelection();
                modal.show();
            });
        });

        modeAll.addEventListener('change', function () { setMode('all'); loadSelection(); });
        modeBuyer.addEventListener('change', function () { setMode('buyer'); loadSelection(); });
        buyerSelect.addEventListener('change', loadSelection);
        search.addEventListener('input', applyFilter);
        onlySelected.addEventListener('change', applyFilter);
        list.addEventListener('change', function () { refreshCount(); applyFilter(); });
    });
</script>
@endsection
