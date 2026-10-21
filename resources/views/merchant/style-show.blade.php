@extends('layouts.app')

@section('title', 'Style ' . $mother->style_no)

@section('content')
@php
    $stageTone = [
        'Not ordered' => 'bg-danger-subtle text-danger',
        'Ordered' => 'bg-primary-subtle text-primary',
        'PI received' => 'bg-primary-subtle text-primary',
        'Paid' => 'bg-info-subtle text-info',
        'Received' => 'bg-success-subtle text-success',
        'Issued' => 'bg-success-subtle text-success',
    ];
    $pct = fn ($n) => $kpis['materials'] > 0 ? round($n / $kpis['materials'] * 100) : 0;
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => $homeUrl],
        ['label' => $backLabel === 'Back to Style Library' ? 'Style Library' : 'Season Status', 'url' => $backUrl],
        ['label' => 'Style ' . $mother->style_no],
    ]" />

    <x-page-header data-aos="fade-down" icon="bar-chart-steps" eyebrow="{{ $mother->buyer?->buyer_name }} · {{ $mother->season?->code }}"
                   :title="'Style ' . $mother->style_no"
                   copy="Orders and materials for this style, and how far each material has come.">
        <x-slot:actions>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ $backLabel }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @if($children->isNotEmpty())
        <div class="mb-3">
            <span class="text-muted me-2">Child style:</span>
            <a href="{{ route($showRoute, $mother) }}" class="btn btn-sm {{ ! $childId ? 'btn-primary' : 'btn-outline-secondary' }} me-1">All</a>
            @foreach($children as $c)
                <a href="{{ route($showRoute, [$mother, 'child' => $c->id]) }}" class="btn btn-sm {{ $childId === $c->id ? 'btn-primary' : 'btn-outline-secondary' }} me-1">{{ $c->style_no }}</a>
            @endforeach
        </div>
    @endif

    {{-- Pre-costing --}}
    <x-card title="Pre-costing" class="mb-3" body-class="p-3">
        <x-slot:actions>
            <a href="{{ route('costing.show', $mother) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-calculator me-1" aria-hidden="true"></i>Open Pre-costing</a>
        </x-slot:actions>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Costing</th><th>Revision</th><th class="text-end">Total cost</th><th class="text-end">Quoted price</th><th class="text-end">Margin</th></tr></thead>
                <tbody>
                @foreach(\App\Models\Costing::MODES as $key => $label)
                    @php($c = $costingCards[$key])
                    <tr>
                        <td class="fw-semibold">{{ $label }}</td>
                        @if($c)
                            <td>{{ $c['costing']->revision }} <span class="text-muted">({{ $c['costing']->isDraft() ? 'draft' : 'final' }})</span></td>
                            <td class="text-end">{{ $c['costing']->currency }} {{ number_format($c['summary']['total'], 2) }}</td>
                            <td class="text-end">{{ $c['summary']['quoted'] !== null ? number_format($c['summary']['quoted'], 2) : '—' }}</td>
                            <td class="text-end">{{ $c['summary']['margin'] !== null ? $c['summary']['margin'] . '%' : '—' }}</td>
                        @else
                            <td colspan="4" class="text-muted">Not started</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- BOM approval --}}
    <x-card title="BOM approval" class="mb-3" body-class="p-3">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            @include('bom-approvals._badge', ['state' => $bomState, 'approval' => $bomApproval])
            @if($bomApproval?->submitted_at)<span class="text-muted small">Submitted by {{ $bomApproval->submitter?->name }} on {{ $bomApproval->submitted_at->format('d M Y') }}</span>@endif

            @if($canSubmitBom && in_array($bomState, ['not_submitted', 'returned', 'changed'], true))
                <form method="POST" action="{{ route('bom_approvals.submit', $mother) }}" class="ms-auto">@csrf
                    <button class="btn btn-primary btn-sm" {{ $kpis['materials'] === 0 ? 'disabled' : '' }}>
                        <i class="bi bi-send-check me-1" aria-hidden="true"></i>{{ $bomState === 'not_submitted' ? 'Submit for Approval' : 'Submit Again' }}
                    </button>
                </form>
            @endif
        </div>

        @if($bomState === 'changed')
            <div class="alert alert-danger py-2 mb-2">The BOM changed after it was approved. Submit it again for approval.</div>
        @endif

        @if($canDecideBom)
            <form method="POST" class="row g-2 align-items-end mb-2">
                @csrf
                <div class="col-12 col-md-6">
                    <label class="form-label mb-1" for="bomComment">Comment <span class="text-muted fw-normal">(required to return)</span></label>
                    <input type="text" name="comment" id="bomComment" class="form-control form-control-sm" maxlength="1000">
                </div>
                <div class="col-auto">
                    <button formaction="{{ route('bom_approvals.approve', $bomApproval) }}" class="btn btn-success btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve</button>
                    <button formaction="{{ route('bom_approvals.return', $bomApproval) }}" class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return to Merchant</button>
                </div>
            </form>
        @endif

        @if($bomApproval && $bomApproval->steps->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>When</th><th>Step</th><th>By</th><th>Comment</th></tr></thead>
                    <tbody>
                    @foreach($bomApproval->steps->reverse() as $step)
                        <tr>
                            <td class="text-nowrap">{{ $step->created_at?->format('d M Y H:i') }}</td>
                            <td>{{ ucfirst($step->action) }}@if($step->action !== 'submitted') · {{ \App\Models\BomApproval::LEVEL_LABELS[$step->level] ?? '' }}@endif</td>
                            <td>{{ $step->user?->name ?: '—' }}</td>
                            <td>{{ $step->comment ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    @if($canFollowUp || $followUpTasks->isNotEmpty())
    <x-card title="Follow-up" class="mb-3" body-class="p-3">
        @if($followUpTasks->isNotEmpty())
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Task</th><th>Assigned to</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                    @foreach($followUpTasks as $t)
                        @php($light = $t->light())
                        <tr>
                            <td>{{ $t->title }}</td>
                            <td>{{ $t->assignee?->name }}</td>
                            <td class="text-nowrap">
                                {{ $t->due_date->format('d M Y') }}
                                @if($light === 'red')<span class="badge bg-danger text-white ms-1">Overdue</span>
                                @elseif($light === 'amber')<span class="badge bg-warning text-dark ms-1">Due soon</span>@endif
                            </td>
                            <td class="text-end">
                                @if(auth()->user()->hasRole('admin') || in_array(auth()->id(), [$t->assigned_to, $t->created_by], true))
                                    <form method="POST" action="{{ route('follow_ups.done', $t) }}">@csrf
                                        <button class="btn btn-outline-success btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Mark Done</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($canFollowUp)
            @if($kpis['late'] > 0 || $kpis['due_soon'] > 0)
                <p class="mb-2 text-muted">Ask someone to chase the {{ $kpis['late'] + $kpis['due_soon'] }} material(s) that are late or due soon.</p>
            @endif
            <form method="POST" action="{{ route('follow_ups.store') }}" class="row g-2 align-items-end">
                @csrf
                <input type="hidden" name="mother_style_id" value="{{ $mother->id }}">
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1" for="fuAssignee">Who should follow up</label>
                    <select name="assigned_to" id="fuAssignee" class="form-select form-select-sm" required>
                        <option value="">Select person</option>
                        @foreach($assignees as $a)<option value="{{ $a->id }}" {{ (int) old('assigned_to') === $a->id ? 'selected' : '' }}>{{ $a->name }}{{ $a->designation ? ' — ' . $a->designation : '' }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1" for="fuDue">Due date</label>
                    <input type="date" name="due_date" id="fuDue" class="form-control form-control-sm" min="{{ now()->toDateString() }}" value="{{ old('due_date', now()->addDays(3)->toDateString()) }}" required>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1" for="fuNote">Note <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="note" id="fuNote" class="form-control form-control-sm" maxlength="1000" value="{{ old('note') }}" placeholder="What exactly needs to be done">
                </div>
                <div class="col-12 col-md-3">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Create Follow-up</button>
                </div>
            </form>
        @endif
    </x-card>
    @endif

    @if($kpis['order_lines'] === 0)
        <x-card>
            <p class="mb-1 fw-semibold">No orders are linked to this style yet.</p>
            <p class="text-muted mb-0">Import the buyer's Excel with the season <strong>{{ $mother->season?->code }}</strong> (Merchant Workspace → Import Buyer Excel). Its order lines and materials will appear here.</p>
        </x-card>
    @else
        @if($kpis['late'] > 0 || $kpis['due_soon'] > 0)
            <div class="alert {{ $kpis['late'] > 0 ? 'alert-danger' : 'alert-warning' }} d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div>
                    @if($kpis['late'] > 0)<strong>{{ $kpis['late'] }}</strong> material(s) not ordered and past the date they had to be ordered by.@endif
                    @if($kpis['due_soon'] > 0) <strong>{{ $kpis['due_soon'] }}</strong> due to be ordered within {{ \App\Services\StyleRollup::DUE_SOON_DAYS }} days.@endif
                </div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="receipt" tone="primary" label="Order lines" :value="$kpis['order_lines']" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="box-seam" tone="primary" label="Order qty" :value="number_format($kpis['order_qty'])" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="list-ul" tone="primary" label="Materials" :value="$kpis['materials']" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="cart-check" tone="{{ $kpis['ordered'] === $kpis['materials'] ? 'success' : 'warning' }}" label="Ordered" :value="$kpis['ordered'] . ' (' . $pct($kpis['ordered']) . '%)'" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="file-earmark-check" tone="primary" label="PI received" :value="$kpis['pi_received'] . ' (' . $pct($kpis['pi_received']) . '%)'" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="cash-coin" tone="primary" label="Paid" :value="$kpis['paid'] . ' (' . $pct($kpis['paid']) . '%)'" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="truck" tone="success" label="Received" :value="$kpis['received'] . ' (' . $pct($kpis['received']) . '%)'" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="box-arrow-right" tone="success" label="Issued" :value="$kpis['issued'] . ' (' . $pct($kpis['issued']) . '%)'" /></div>
        </div>

        <x-card title="Orders" class="mb-3" body-class="p-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>Color</th><th>Customer PO</th><th>Child</th><th>Ship date</th><th class="text-end">Order qty</th>
                        <th class="text-end">Materials</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Late</th>
                    </tr></thead>
                    <tbody>
                    @foreach($lines as $l)
                        <tr>
                            <td class="fw-semibold">{{ $l['color'] ?: '—' }}</td>
                            <td>{{ $l['customer_po'] ?: '—' }}</td>
                            <td>{{ $l['child'] ?: '—' }}</td>
                            <td>{{ $l['ship_date'] ? $l['ship_date']->format('d M Y') : '—' }}</td>
                            <td class="text-end">{{ $l['order_qty'] !== null ? number_format($l['order_qty']) : '—' }}</td>
                            <td class="text-end">{{ $l['materials'] }}</td>
                            <td class="text-end">{{ $l['ordered'] }}</td>
                            <td class="text-end">{{ $l['received'] }}</td>
                            <td class="text-end">@if($l['late'] > 0)<span class="badge bg-danger-subtle text-danger">{{ $l['late'] }}</span>@else — @endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Materials" body-class="p-3">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <input type="search" id="matSearch" class="form-control form-control-sm" style="max-width:260px" placeholder="Search material, SAP code, PO, vendor">
                <select id="matStage" class="form-select form-select-sm" style="max-width:200px">
                    <option value="">All stages</option>
                    @foreach(array_keys($stageTone) as $stage)<option value="{{ $stage }}">{{ $stage }}</option>@endforeach
                </select>
            </div>
            <div class="table-responsive" style="max-height:560px">
                <table class="table table-sm align-middle mb-0" id="matTable">
                    <thead class="sticky-top bg-white"><tr>
                        <th>Material</th><th>SAP code</th><th>Color</th><th class="text-end">BOM qty</th>
                        <th>PO no.</th><th>Vendor</th><th class="text-end">Ordered</th><th>PI</th><th>Payment</th><th class="text-end">Received</th><th>Stage</th>
                    </tr></thead>
                    <tbody>
                    @foreach($materials as $m)
                        <tr data-stage="{{ $m['stage'] }}" data-text="{{ mb_strtolower(implode(' ', [$m['description'], $m['sap_code'], $m['po_no'], $m['vendor'], $m['material_type']])) }}">
                            <td>{{ $m['description'] ?: '—' }} @if($m['material_type'])<small class="text-muted">· {{ $m['material_type'] }}</small>@endif</td>
                            <td>{{ $m['sap_code'] ?: '—' }}</td>
                            <td>{{ $m['color'] ?: '—' }}</td>
                            <td class="text-end">{{ $m['bom_qty'] !== null ? number_format($m['bom_qty'], 2) : '—' }} {{ $m['uom'] }}</td>
                            <td>{{ $m['po_no'] ?: '—' }}</td>
                            <td>{{ $m['vendor'] ?: '—' }}</td>
                            <td class="text-end">{{ $m['ordered_qty'] ? number_format($m['ordered_qty'], 2) : '—' }}</td>
                            <td>{{ $m['pi_status'] ?: '—' }}</td>
                            <td>{{ $m['payment_status'] ?: '—' }}</td>
                            <td class="text-end">{{ $m['receipt_qty'] ? number_format($m['receipt_qty'], 2) : '—' }}</td>
                            <td class="text-nowrap">
                                <span class="badge {{ $stageTone[$m['stage']] }}">{{ $m['stage'] }}</span>
                                @if($m['light'] === 'red')<span class="badge bg-danger text-white ms-1">Late</span>
                                @elseif($m['light'] === 'amber')<span class="badge bg-warning text-dark ms-1">Due soon</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="form-text mt-2">Stage is the furthest step reached: Not ordered, Ordered, PI received, Paid, Received, Issued. Late and Due soon apply only to material not yet ordered, from its "order to be placed by" date.</div>
        </x-card>
    @endif
</div>

@if($kpis['order_lines'] > 0)
<script>
(function () {
    var search = document.getElementById('matSearch'), stage = document.getElementById('matStage');
    var rows = document.querySelectorAll('#matTable tbody tr');
    function apply() {
        var q = (search.value || '').toLowerCase().trim(), s = stage.value;
        rows.forEach(function (r) {
            r.style.display = ((!q || r.dataset.text.indexOf(q) !== -1) && (!s || r.dataset.stage === s)) ? '' : 'none';
        });
    }
    search.addEventListener('input', apply);
    stage.addEventListener('change', apply);
})();
</script>
@endif
@endsection
