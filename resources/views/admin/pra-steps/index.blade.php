@extends('layouts.app')

@section('title', 'PRA Approval Steps')

@section('content')
<style>
    .ps-card { display:flex; gap:16px; align-items:flex-start; padding:16px 18px; border:1px solid #e3e8f4; border-radius:16px; background:#fff; }
    .ps-card + .ps-card { margin-top:10px; }
    .ps-card.is-off { background:#f7f8fb; opacity:.75; }
    .ps-num { flex:0 0 36px; height:36px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; background:#2f5bea; }
    .ps-card.is-off .ps-num { background:#9aa5c2; }
    .ps-body { flex:1 1 auto; min-width:0; }
    .ps-title { font-weight:700; color:#0f1b4d; }
    .ps-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
    .ps-tag { font-size:11.5px; border-radius:999px; padding:2px 10px; background:#eef3ff; color:#1d3a9a; font-weight:600; }
    .ps-tag.is-sign { background:#e7f6ec; color:#17673a; }
    .ps-tag.is-none { background:#f1f3f8; color:#6b7691; font-weight:500; }
    .ps-tag.is-flag { background:#fff3cd; color:#7a4b00; }
    .ps-actions { flex:0 0 auto; display:flex; flex-wrap:wrap; gap:6px; justify-content:flex-end; max-width:260px; }
    .ps-boxes { display:flex; flex-wrap:wrap; gap:8px; }
    .ps-box { border:1px dashed #8ea0d4; border-radius:10px; padding:6px 12px; font-size:12px; font-weight:600; color:#0f1b4d; background:#fbfcff; }
    @media (max-width: 767px) { .ps-card { flex-direction:column; } .ps-actions { justify-content:flex-start; max-width:none; } }
</style>

<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'PRA Approval'],
        ['label' => 'PRA Approval Steps'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-list-ol" aria-hidden="true"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Admin / PRA Approval</div>
                    <h3 class="app-hero-title mb-0">PRA Approval Steps &amp; Signatures</h3>
                    <div class="small text-muted">Decide the steps a PRA goes through and the signature boxes it prints.</div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.pra-routing.index') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-people me-1" aria-hidden="true"></i>Who approves each step</a>
                <a href="{{ route('admin.pra-send-back.index') }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Send PRAs back</a>
                <a href="{{ route('admin.pra-approvals.history') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Approval history</a>
                <button type="button" class="btn btn-primary btn-sm js-step-edit" data-mode="create"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add step</button>
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

    <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <h6 class="fw-bold mb-0">Signature boxes printed on a PRA</h6>
                <span class="small text-muted">{{ ($preparedEnabled ? 1 : 0) + $boxTitles->count() }} of {{ $maxBoxes }} possible</span>
            </div>
            <div class="ps-boxes">
                @if($preparedEnabled)
                    <span class="ps-box">{{ $preparedTitle }} <span class="text-muted fw-normal">(the person who created the PRA)</span></span>
                @endif
                @foreach($boxTitles as $title)<span class="ps-box">{{ $title }}</span>@endforeach
                @if(! $preparedEnabled && $boxTitles->isEmpty())<span class="small text-muted">No box is printed yet.</span>@endif
            </div>
            <div class="small text-muted mt-2">One box for each step that has a <strong>Signature box title</strong>, in the order of the steps below.</div>

            {{-- THE CREATOR'S OWN BOX. Printed first by default. Turn it off when one of
                 the steps below is already the first signature (for example "Supply
                 Chain"), so the sheet does not carry a box too many. --}}
            <form method="POST" action="{{ route('admin.pra-steps.prepared') }}" class="d-flex flex-wrap align-items-end gap-3 mt-3 pt-3 border-top">
                @csrf
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="preparedEnabled" name="prepared_enabled" value="1" {{ $preparedEnabled ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="preparedEnabled">Print a box for the person who created the PRA</label>
                </div>
                <div>
                    <label class="form-label small mb-1" for="preparedTitle">Its title</label>
                    <input type="text" class="form-control form-control-sm" id="preparedTitle" name="prepared_title" maxlength="80" value="{{ $preparedTitle }}" placeholder="Prepared By" style="min-width:240px;">
                </div>
                <button type="submit" class="btn btn-sm btn-primary mb-2"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">Protect Workspace data once a PRA is created</h6>
            <div class="small text-muted mb-3">Stops Merchant and Supply Chain from changing figures a Payment Request already uses. Admin users are never blocked. If someone tries, the save is refused and a message names the PRA.</div>
            <form method="POST" action="{{ route('admin.pra-steps.data-locks') }}">
                @csrf
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="lockAfterSubmit" name="lock_after_submit" value="1" {{ $lockAfterSubmit ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="lockAfterSubmit">After a PRA is submitted: lock Materials Ordered and Materials to be Ordered</label>
                    <div class="small text-muted">Also locks the order quantity, consumption and wastage that calculate them. A rejected or cancelled PRA does not lock.</div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="lockAfterApproval" name="lock_after_approval" value="1" {{ $lockAfterApproval ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="lockAfterApproval">After a PRA is approved: also lock PI Rate and PI Amount</label>
                </div>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
            </form>
        </div>
    </div>

    @foreach($steps as $index => $step)
        @php $waitingHere = (int) ($waiting[$step->sequence] ?? 0); @endphp
        <div class="ps-card {{ $step->is_active ? '' : 'is-off' }}">
            <div class="ps-num">{{ $loop->iteration }}</div>
            <div class="ps-body">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="ps-title">{{ $step->label }}</span>
                    @unless($step->is_active)<span class="badge bg-secondary-subtle text-secondary-emphasis">Switched off</span>@endunless
                    @if($waitingHere > 0)<span class="badge bg-warning-subtle text-warning-emphasis">{{ $waitingHere }} PRA waiting</span>@endif
                </div>
                <div class="ps-tags">
                    <span class="ps-tag">{{ $step->department ? ($departments[$step->department] ?? $step->department) . ' ' . strtolower(\App\Models\PraStep::KINDS[$step->actor_kind] ?? '') : 'Only the people you choose' }}</span>
                    <span class="ps-tag">{{ \App\Models\PraStep::STAGES[$step->stage] ?? $step->stage }}</span>
                    @if($step->buyer_scoped)<span class="ps-tag">Buyer access required</span>@endif
                    @if(trim((string) $step->signature_label) !== '')
                        <span class="ps-tag is-sign"><i class="bi bi-pen me-1" aria-hidden="true"></i>Box: {{ $step->signature_label }}</span>
                    @else
                        <span class="ps-tag is-none">No signature box</span>
                    @endif
                    @if($step->prints_ocr_check)<span class="ps-tag is-flag">Fills the OCR Checked box</span>@endif
                    @if($step->prints_completion)<span class="ps-tag is-flag">Prints "Paid / Completed by"</span>@endif
                </div>
            </div>
            <div class="ps-actions">
                <form method="POST" action="{{ route('admin.pra-steps.move', $step) }}">@csrf<input type="hidden" name="direction" value="up">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" {{ $loop->first ? 'disabled' : '' }}><i class="bi bi-arrow-up me-1" aria-hidden="true"></i>Up</button></form>
                <form method="POST" action="{{ route('admin.pra-steps.move', $step) }}">@csrf<input type="hidden" name="direction" value="down">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" {{ $loop->last ? 'disabled' : '' }}><i class="bi bi-arrow-down me-1" aria-hidden="true"></i>Down</button></form>
                <button type="button" class="btn btn-sm btn-primary js-step-edit" data-mode="edit"
                        data-action="{{ route('admin.pra-steps.update', $step) }}"
                        data-label="{{ $step->label }}" data-department="{{ $step->department }}" data-kind="{{ $step->actor_kind }}"
                        data-stage="{{ $step->stage }}" data-buyer="{{ $step->buyer_scoped ? 1 : 0 }}" data-sign="{{ $step->signature_label }}"
                        data-ocr="{{ $step->prints_ocr_check ? 1 : 0 }}" data-completion="{{ $step->prints_completion ? 1 : 0 }}">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                </button>
                <form method="POST" action="{{ route('admin.pra-steps.toggle', $step) }}" onsubmit="return confirm('{{ $step->is_active ? 'Take this step out of the chain?' : 'Put this step back in the chain?' }}');">
                    @csrf
                    <button type="submit" class="btn btn-sm {{ $step->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                        <i class="bi {{ $step->is_active ? 'bi-toggle-on' : 'bi-toggle-off' }} me-1" aria-hidden="true"></i>{{ $step->is_active ? 'Switch off' : 'Switch on' }}
                    </button>
                </form>
            </div>
        </div>
    @endforeach
</div>

{{-- ONE DIALOG for adding and editing a step. --}}
<div class="modal fade" id="stepModal" tabindex="-1" aria-labelledby="stepModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:18px;">
            <form method="POST" id="stepForm" action="{{ route('admin.pra-steps.store') }}">
                @csrf
                <input type="hidden" name="_method" id="stepMethod" value="POST" disabled>
                <div class="modal-header">
                    <h5 class="modal-title" id="stepModalTitle">Step</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="stepLabel">Step name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="stepLabel" name="label" maxlength="120" required placeholder="e.g. Merchant Team Check">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label fw-semibold" for="stepDepartment">Who does this step</label>
                            <select class="form-select" id="stepDepartment" name="department">
                                <option value="">Only the people I choose</option>
                                @foreach($departments as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold" for="stepKind">Level</label>
                            <select class="form-select" id="stepKind" name="actor_kind">
                                @foreach(\App\Models\PraStep::KINDS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div class="small text-muted mb-3">With a department, its Team or Head (people with the department's roles) can act. You can also name the exact people, for all buyers or one buyer, on <em>Who approves each step</em>.</div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="stepStage">What kind of step</label>
                        <select class="form-select" id="stepStage" name="stage">
                            @foreach(\App\Models\PraStep::STAGES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="stepBuyer" name="buyer_scoped" value="1">
                        <label class="form-check-label" for="stepBuyer">Only people who have access to the PRA's buyer</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="stepSign">Signature box title</label>
                        <input type="text" class="form-control" id="stepSign" name="signature_label" maxlength="120" placeholder="e.g. Checked By (leave empty for no box)">
                        <div class="form-text">Prints a signature box with this title for the person who completes this step. Empty = no box.</div>
                    </div>

                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="stepOcr" name="prints_ocr_check" value="1">
                        <label class="form-check-label" for="stepOcr">This step fills the printed "OCR Checked" box</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="stepCompletion" name="prints_completion" value="1">
                        <label class="form-check-label" for="stepCompletion">This step prints the "Paid / Completed by" line</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('stepModal');
        if (!modalEl || !window.bootstrap) return;
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const form = document.getElementById('stepForm');
        const method = document.getElementById('stepMethod');
        const storeUrl = @json(route('admin.pra-steps.store'));

        const set = function (id, value) { document.getElementById(id).value = value; };
        const check = function (id, on) { document.getElementById(id).checked = !!Number(on); };

        document.querySelectorAll('.js-step-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                const edit = button.dataset.mode === 'edit';
                document.getElementById('stepModalTitle').textContent = edit ? 'Edit step' : 'Add step';
                form.action = edit ? button.dataset.action : storeUrl;
                method.disabled = !edit;
                method.value = 'PUT';

                set('stepLabel', edit ? button.dataset.label : '');
                set('stepDepartment', edit ? (button.dataset.department || '') : '');
                set('stepKind', edit ? button.dataset.kind : 'team');
                set('stepStage', edit ? button.dataset.stage : 'check');
                set('stepSign', edit ? (button.dataset.sign || '') : '');
                check('stepBuyer', edit ? button.dataset.buyer : 0);
                check('stepOcr', edit ? button.dataset.ocr : 0);
                check('stepCompletion', edit ? button.dataset.completion : 0);
                modal.show();
            });
        });
    });
</script>
@endsection
