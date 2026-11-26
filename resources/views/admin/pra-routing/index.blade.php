@extends('layouts.app')

@section('title', 'PRA Approval Routing')

@section('content')
@php
    $usersById = $users->keyBy('id');
    $namesOf = fn (array $ids) => collect($ids)->map(fn ($id) => $usersById[$id]['name'] ?? null)->filter()->values()->all();
    $stepData = collect($steps)->mapWithKeys(fn ($step) => [$step['sequence'] => [
        'label' => $step['label'],
        'ordinal' => $step['ordinal'],
        'automatic' => $step['automatic'],
        'global_ids' => $step['global_ids'],
        // Rule per buyer, so choosing a buyer can show who is already set for it.
        'buyer_rules' => collect($step['buyer_groups'])->flatMap(fn ($group) => collect($group['buyer_ids'])->mapWithKeys(fn ($id) => [$id => $group['ids']]))->all(),
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
                <li>Press <strong>Change approvers</strong> to choose people for a step, for <strong>all buyers</strong> or for <strong>specific buyers</strong> (one or several, but not all). A rule for specific buyers is used instead of the all-buyers rule for those buyers.</li>
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
        @php $isCustom = count($step['global_ids']) || count($step['buyer_groups']); @endphp
        <div class="rt-step">
            <div class="rt-num">{{ $step['ordinal'] }}</div>
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

                @foreach($step['buyer_groups'] as $group)
                    <div class="rt-rule">
                        <div class="min-w-0">
                            <div class="rt-chips mt-0">
                                <span class="small text-muted align-self-center me-1"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>{{ count($group['buyers']) > 1 ? 'Buyers:' : 'Buyer:' }}</span>
                                @foreach($group['buyers'] as $buyerName)<span class="rt-chip" style="background:#fff3cd;color:#7a4b00;">{{ $buyerName }}</span>@endforeach
                            </div>
                            <div class="rt-chips mt-1">
                                <span class="small text-muted align-self-center me-1">Approvers:</span>
                                @foreach($group['users'] as $name)<span class="rt-chip">{{ $name }}</span>@endforeach
                            </div>
                        </div>
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button type="button" class="btn btn-sm btn-outline-primary js-open-routing" data-seq="{{ $step['sequence'] }}" data-mode="buyer"
                                    data-buyers="{{ implode(',', $group['buyer_ids']) }}" data-people="{{ implode(',', $group['ids']) }}">
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                            </button>
                            {{-- Clearing the people for these buyers removes the rule. --}}
                            <form method="POST" action="{{ route('admin.pra-routing.save_buyer', $step['sequence']) }}" onsubmit="return confirm('Remove this rule for {{ count($group['buyers']) > 1 ? 'these buyers' : 'this buyer' }}?');">
                                @csrf
                                @foreach($group['buyer_ids'] as $groupBuyerId)
                                    <input type="hidden" name="buyer_ids[]" value="{{ $groupBuyerId }}">
                                @endforeach
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
                <button type="button" class="btn btn-outline-primary btn-sm js-open-routing" data-seq="{{ $step['sequence'] }}" data-mode="buyer" data-buyers="" data-people="" {{ $available ? '' : 'disabled' }}>
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add specific buyers
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
                        <label class="btn btn-outline-primary btn-sm" for="modeBuyer">Specific buyers</label>
                    </div>

                    <div class="mb-3" id="routingBuyerWrap" hidden>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label small fw-semibold mb-0">Buyers <span class="text-muted fw-normal">(tick one or more)</span></label>
                            <span class="small text-muted"><strong id="routingBuyerCount">0</strong> selected</span>
                        </div>
                        <input type="search" class="form-control form-control-sm mb-2" id="routingBuyerSearch" placeholder="Search buyers" aria-label="Search buyers" autocomplete="off">
                        <div class="rt-list" id="routingBuyerList" style="max-height:150px;">
                            @foreach($buyers as $buyer)
                                <label class="rt-person" data-name="{{ strtolower($buyer->buyer_name) }}">
                                    <input type="checkbox" class="form-check-input mt-0" name="buyer_ids[]" value="{{ $buyer->id }}" disabled>
                                    <span class="flex-grow-1 fw-semibold">{{ $buyer->buyer_name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div id="routingOriginalBuyers"></div>
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
        const buyerList = document.getElementById('routingBuyerList');
        const buyerSearch = document.getElementById('routingBuyerSearch');
        const buyerCount = document.getElementById('routingBuyerCount');
        const originalWrap = document.getElementById('routingOriginalBuyers');
        const saveBtn = document.getElementById('routingSave');
        const buyerBoxes = Array.from(buyerList.querySelectorAll('input[type=checkbox]'));
        const modeAll = document.getElementById('modeAll');
        const modeBuyer = document.getElementById('modeBuyer');
        const titleEl = document.getElementById('routingModalTitle');
        const subEl = document.getElementById('routingModalSub');

        let seq = null;
        // The people to tick when the dialog is in "specific buyers" mode (from the rule being edited).
        let buyerPeople = [];

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
            const step = steps[seq] || { global_ids: [] };
            return (modeBuyer.checked ? buyerPeople : (step.global_ids || [])).map(Number);
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

        function refreshBuyers() {
            const picked = buyerBoxes.filter(function (box) { return box.checked; });
            buyerCount.textContent = picked.length;
            buyerBoxes.forEach(function (box) { box.closest('.rt-person').classList.toggle('is-checked', box.checked); });
            // "Specific buyers" needs at least one buyer to save.
            saveBtn.disabled = modeBuyer.checked && picked.length === 0;
        }

        function filterBuyers() {
            const term = buyerSearch.value.trim().toLowerCase();
            buyerList.querySelectorAll('.rt-person').forEach(function (row) {
                if (term && row.dataset.name.indexOf(term) === -1) { row.style.setProperty('display', 'none', 'important'); }
                else { row.style.removeProperty('display'); }
            });
        }

        function setMode(mode) {
            const buyer = mode === 'buyer';
            modeAll.checked = !buyer;
            modeBuyer.checked = buyer;
            buyerWrap.hidden = !buyer;
            // Buyer ticks are only posted in "specific buyers" mode.
            buyerBoxes.forEach(function (box) { box.disabled = !buyer; });
            form.action = (buyer ? urlBuyer : urlAll).replace(/0$/, seq).replace(/\/0\/buyer$/, '/' + seq + '/buyer');
            subEl.textContent = buyer ? 'For specific buyers only' : 'For every buyer';
            refreshBuyers();
        }

        document.querySelectorAll('.js-open-routing').forEach(function (button) {
            button.addEventListener('click', function () {
                seq = button.dataset.seq;
                titleEl.textContent = 'Step ' + (steps[seq] ? steps[seq].ordinal : seq) + ' — ' + (steps[seq] ? steps[seq].label : '');
                search.value = '';
                buyerSearch.value = '';
                onlySelected.checked = false;

                // Buyers (and people) of the rule being edited; empty for a new rule.
                const tickedBuyers = (button.dataset.buyers || '').split(',').filter(Boolean).map(Number);
                buyerPeople = (button.dataset.people || '').split(',').filter(Boolean).map(Number);
                buyerBoxes.forEach(function (box) { box.checked = tickedBuyers.indexOf(Number(box.value)) !== -1; });
                // Remember which buyers the rule covered, so one that is unticked loses it.
                originalWrap.innerHTML = '';
                tickedBuyers.forEach(function (id) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'original_buyer_ids[]';
                    hidden.value = id;
                    originalWrap.appendChild(hidden);
                });
                // Ticked buyers first.
                buyerBoxes.map(function (box) { return box.closest('.rt-person'); })
                    .sort(function (a, b) { return (b.querySelector('input').checked ? 1 : 0) - (a.querySelector('input').checked ? 1 : 0); })
                    .forEach(function (row) { buyerList.appendChild(row); });
                filterBuyers();

                setMode(button.dataset.mode);
                loadSelection();
                modal.show();
            });
        });

        modeAll.addEventListener('change', function () { setMode('all'); loadSelection(); });
        modeBuyer.addEventListener('change', function () { setMode('buyer'); loadSelection(); });
        buyerList.addEventListener('change', refreshBuyers);
        buyerSearch.addEventListener('input', filterBuyers);
        search.addEventListener('input', applyFilter);
        onlySelected.addEventListener('change', applyFilter);
        list.addEventListener('change', function () { refreshCount(); applyFilter(); });
    });
</script>
@endsection
