@extends('layouts.app')

@section('title', 'Pre-costing ' . $mother->style_no)

@section('content')
@php
    $fmt = fn ($n) => number_format((float) $n, 4);
    $money = fn ($n) => $n === null ? '—' : number_format((float) $n, 2);
    $editable = $canEdit && $costing && $costing->isDraft();
    $isMerchant = auth()->user()->hasRole('merchant');
    $backUrl = $isMerchant ? route('merchant.styles.mothers.show', $mother) : route('merchant.status.style', $mother);
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => $isMerchant ? route('merchant.dashboard') : route('merchant.status.index')],
        ['label' => 'Style ' . $mother->style_no, 'url' => $backUrl],
        ['label' => 'Pre-costing'],
    ]" />

    <x-page-header data-aos="fade-down" icon="calculator" :eyebrow="$mother->buyer?->buyer_name . ' · ' . $mother->season?->code"
                   :title="'Pre-costing — style ' . $mother->style_no"
                   copy="What the style costs, by cost head, and what is left of the price.">
        <x-slot:actions>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    {{-- Mode tabs: what is quoted to the buyer vs what the factory expects to spend --}}
    <div class="btn-group mb-3" role="group">
        @foreach(\App\Models\Costing::MODES as $key => $label)
            <a href="{{ route('costing.show', [$mother, 'mode' => $key]) }}" class="btn btn-sm {{ $mode === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }} costing</a>
        @endforeach
    </div>

    @if($both['buyer'] && $both['inhouse'])
        <div class="alert alert-light border py-2">
            Buyer costing margin <strong>{{ $both['buyer']['summary']['margin'] !== null ? $both['buyer']['summary']['margin'] . '%' : '—' }}</strong>
            · In-house costing margin <strong>{{ $both['inhouse']['summary']['margin'] !== null ? $both['inhouse']['summary']['margin'] . '%' : '—' }}</strong>
            <span class="text-muted">(latest revision of each)</span>
        </div>
    @endif

    @if(! $costing)
        <x-card>
            <p class="fw-semibold mb-1">No {{ \App\Models\Costing::MODES[$mode] }} costing for this style yet.</p>
            <p class="text-muted">{{ $mode === 'buyer' ? 'The buyer costing is the price build-up you quote to the buyer.' : 'The in-house costing is what the factory expects to spend.' }}</p>
            @if($canEdit)
                <form method="POST" action="{{ route('costing.start', $mother) }}">@csrf
                    <input type="hidden" name="mode" value="{{ $mode }}">
                    <button class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Start {{ \App\Models\Costing::MODES[$mode] }} Costing</button>
                </form>
            @endif
        </x-card>
    @else
        {{-- Revisions --}}
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="text-muted">Revision:</span>
            @foreach($revisions as $r)
                <a href="{{ route('costing.show', [$mother, 'mode' => $mode, 'rev' => $r->revision]) }}" class="btn btn-sm {{ $costing->id === $r->id ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ $r->revision }} <span class="opacity-75">{{ $r->isDraft() ? '(draft)' : '(final)' }}</span>
                </a>
            @endforeach
            @if($canEdit && ! $revisions->first()->isDraft())
                <form method="POST" action="{{ route('costing.revise', $mother) }}" class="ms-2">@csrf
                    <input type="hidden" name="mode" value="{{ $mode }}">
                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-files me-1" aria-hidden="true"></i>New Revision</button>
                </form>
            @endif
            @if(! $costing->isDraft())
                <span class="text-muted small ms-2">Final · {{ $costing->finalizer?->name }} · {{ $costing->finalized_at?->format('d M Y') }}</span>
            @endif
        </div>

        {{-- Approval of a finalized revision. --}}
        @php($appr = $costing->approval_status)
        @if(! $costing->isDraft() || $appr === \App\Models\Costing::APPROVAL_RETURNED)
            <div class="alert {{ $appr === 'approved' ? 'alert-success' : ($appr === 'returned' ? 'alert-danger' : ($appr === 'pending' ? 'alert-warning' : 'alert-light border')) }} d-flex flex-wrap align-items-center gap-2 py-2">
                <i class="bi {{ $appr === 'approved' ? 'bi-patch-check' : ($appr === 'returned' ? 'bi-arrow-return-left' : 'bi-hourglass-split') }}" aria-hidden="true"></i>
                <div class="me-auto">
                    @if($appr === 'approved')
                        <strong>Approved</strong> by {{ $costing->approver?->name }} on {{ $costing->approved_at?->format('d M Y') }}.@if($costing->approval_comment) <span class="text-muted">"{{ $costing->approval_comment }}"</span>@endif
                    @elseif($appr === 'returned')
                        <strong>Returned</strong> by {{ $costing->approver?->name }} on {{ $costing->approved_at?->format('d M Y') }}: {{ $costing->approval_comment }}@if($costing->isDraft()) <span class="text-muted">Correct it, then finalize again.</span>@endif
                    @elseif($appr === 'pending')
                        <strong>Waiting for approval</strong> by the Merchandising Head, Management or Admin.
                    @else
                        <strong>Not sent for approval.</strong> This revision was finalized before costing approval existed.
                    @endif
                </div>
                @if($appr === null && ! $costing->isDraft() && $canEdit)
                    <form method="POST" action="{{ route('costing.send', $costing) }}">@csrf
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Send for Approval</button>
                    </form>
                @endif
            </div>
            @if($canApprove)
                <x-card class="mb-3" body-class="p-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md">
                            <label class="form-label mb-1" for="apprComment">Comment <span class="text-muted fw-normal">(required to return)</span></label>
                            <input type="text" name="comment" id="apprComment" form="approveForm" class="form-control form-control-sm" maxlength="1000" placeholder="What should change, or a note for the approval">
                        </div>
                        <div class="col-auto d-flex gap-2">
                            <button class="btn btn-sm btn-success" form="approveForm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve Costing</button>
                            <button class="btn btn-sm btn-outline-danger" form="returnForm" onclick="document.getElementById('returnComment').value = document.getElementById('apprComment').value"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return to Merchant</button>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('costing.approve', $costing) }}" id="approveForm" class="d-none">@csrf</form>
                    <form method="POST" action="{{ route('costing.return', $costing) }}" id="returnForm" class="d-none">@csrf<input type="hidden" name="comment" id="returnComment"></form>
                </x-card>
            @endif
        @endif

        <div class="row g-3 mb-3" id="costSummary">
            <div class="col-6 col-xl-3"><x-stat-card icon="cash-stack" tone="primary" label="Total cost per {{ \App\Models\Costing::BASES[$costing->basis] }}" :value="$costing->currency . ' ' . $money($summary['total'])" /></div>
            <div class="col-6 col-xl-3"><x-stat-card icon="tag" tone="primary" label="Quoted price" :value="$summary['quoted'] !== null ? $costing->currency . ' ' . $money($summary['quoted']) : '—'" /></div>
            <div class="col-6 col-xl-3"><x-stat-card icon="graph-up" tone="{{ ($summary['profit'] ?? 0) >= 0 ? 'success' : 'danger' }}" label="Profit" :value="$summary['profit'] !== null ? $costing->currency . ' ' . $money($summary['profit']) : '—'" /></div>
            <div class="col-6 col-xl-3"><x-stat-card icon="percent" tone="{{ ($summary['margin'] ?? 0) >= 0 ? 'success' : 'danger' }}" label="Margin" :value="$summary['margin'] !== null ? $summary['margin'] . '%' : '—'" /></div>
        </div>

        <form method="POST" action="{{ route('costing.update', $costing) }}" id="costForm">
            @csrf @method('PUT')

            <x-card class="mb-3" body-class="p-3">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1" for="cCur">Currency</label>
                        <select name="currency" id="cCur" class="form-select form-select-sm" {{ $editable ? '' : 'disabled' }}>
                            @foreach(\App\Models\Costing::CURRENCIES as $c)<option value="{{ $c }}" {{ $costing->currency === $c ? 'selected' : '' }}>{{ $c }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1" for="cBasis">Costs are per</label>
                        <select name="basis" id="cBasis" class="form-select form-select-sm" {{ $editable ? '' : 'disabled' }}>
                            @foreach(\App\Models\Costing::BASES as $k => $label)<option value="{{ $k }}" {{ $costing->basis === $k ? 'selected' : '' }}>{{ ucfirst($label) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label mb-1" for="cPrice">Quoted price</label>
                        <input type="number" step="0.0001" min="0" name="quoted_price" id="cPrice" class="form-control form-control-sm" value="{{ $costing->quoted_price !== null ? (float) $costing->quoted_price : '' }}" {{ $editable ? '' : 'disabled' }}>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1" for="cNote">Note</label>
                        <input type="text" name="note" id="cNote" maxlength="1000" class="form-control form-control-sm" value="{{ $costing->note }}" {{ $editable ? '' : 'disabled' }}>
                    </div>
                </div>
            </x-card>

            @php($idx = 0)
            @foreach(\App\Services\Costings::HEADS as $head => [$label, $group])
                @php($rows = $costing->lines->where('head', $head))
                <x-card class="mb-3" body-class="p-3">
                    <x-slot:title>{{ $label }} <span class="badge bg-light text-muted border ms-1" data-head-total="{{ $head }}">{{ $money($summary['heads'][$head]) }}</span></x-slot:title>
                    @if($editable)
                        <x-slot:actions><button type="button" class="btn btn-outline-primary btn-sm js-add-line" data-head="{{ $head }}"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Line</button></x-slot:actions>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Description</th><th style="width:110px">Qty</th><th style="width:90px">Unit</th><th style="width:100px">Wastage %</th><th style="width:120px">Rate</th><th class="text-end" style="width:120px">Amount</th>@if($editable)<th style="width:90px"></th>@endif</tr></thead>
                            <tbody data-head-body="{{ $head }}">
                            @foreach($rows as $line)
                                @include('costing._line', ['i' => $idx++, 'head' => $head, 'line' => $line, 'editable' => $editable])
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endforeach

            @if($editable)
                {{-- One action bar: running total, Save and Finalize side by side. --}}
                <div class="costing-actions d-flex flex-wrap align-items-center gap-2 mb-4">
                    <div class="me-auto">
                        <span class="text-muted small d-block">Total cost per {{ \App\Models\Costing::BASES[$costing->basis] }}</span>
                        <span class="fs-5 fw-bold">{{ $costing->currency }} <span data-grand-total>{{ $money($summary['total']) }}</span></span>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Costing</button>
                    <button class="btn btn-success" form="finalizeForm"><i class="bi bi-lock me-1" aria-hidden="true"></i>Finalize Revision {{ $costing->revision }}</button>
                </div>
            @endif
        </form>

        @if($editable)
            <form method="POST" action="{{ route('costing.finalize', $costing) }}" id="finalizeForm" class="d-none" onsubmit="return confirm('Finalize revision {{ $costing->revision }}? It can no longer be edited; changes need a new revision. Save your changes first.')">@csrf</form>
        @endif

        {{-- Sub-totals --}}
        <x-card title="Summary" class="mb-3" body-class="p-3">
            <table class="table table-sm mb-0" style="max-width:480px">
                <tbody>
                @foreach(\App\Services\Costings::GROUPS as $group => $label)
                    <tr><td>{{ $label }}</td><td class="text-end">{{ $money($summary['groups'][$group]) }}</td></tr>
                @endforeach
                <tr class="fw-semibold"><td>Total cost</td><td class="text-end">{{ $money($summary['total']) }}</td></tr>
                </tbody>
            </table>
        </x-card>

        {{-- Cost of the garment by size, from the style's Size-wise BOM. --}}
        @if($sizeCost)
            @php($sc4 = fn ($n) => $n === null ? '—' : number_format((float) $n, 2))
            <x-card class="mb-3" body-class="p-3">
                <x-slot:title>Cost by size <span class="text-muted fw-normal fs-6">per {{ \App\Models\Costing::BASES[$costing->basis] }}, {{ $costing->currency }}</span></x-slot:title>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead><tr><th>Size</th><th class="text-end">Pieces</th><th class="text-end">Cost</th><th class="text-end">Against sheet</th><th class="text-end">Margin</th></tr></thead>
                        <tbody>
                        @foreach($sizeCost['sizes'] as $r)
                            <tr>
                                <td class="fw-semibold">{{ $r['size'] }}@if($r['changed'] === 0)<span class="small text-muted fw-normal"> · as costed</span>@endif</td>
                                <td class="text-end">{{ $r['pieces'] > 0 ? number_format($r['pieces']) : '—' }}</td>
                                <td class="text-end">{{ $sc4($r['cost']) }}</td>
                                <td class="text-end {{ $r['difference'] > 0.00005 ? 'text-danger' : ($r['difference'] < -0.00005 ? 'text-success' : 'text-muted') }}">{{ $r['difference'] > 0 ? '+' : '' }}{{ $sc4($r['difference']) }}</td>
                                <td class="text-end">{{ $r['margin'] === null ? '—' : $r['margin'] . '%' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold"><td>Sheet (one figure)</td><td></td><td class="text-end">{{ $sc4($sizeCost['sheet_total']) }}</td><td></td><td class="text-end">{{ $costing->marginPct() === null ? '—' : $costing->marginPct() . '%' }}</td></tr>
                            @if($sizeCost['average'] !== null)
                                <tr class="fw-semibold"><td>Average by pieces</td><td class="text-end">{{ number_format($sizeCost['pieces']) }}</td><td class="text-end">{{ $sc4($sizeCost['average']) }}</td><td class="text-end {{ $sizeCost['average'] > $sizeCost['sheet_total'] + 0.00005 ? 'text-danger' : 'text-success' }}">{{ $sizeCost['average'] > $sizeCost['sheet_total'] ? '+' : '' }}{{ $sc4($sizeCost['average'] - $sizeCost['sheet_total']) }}</td><td class="text-end">{{ $sizeCost['average_margin'] === null ? '—' : $sizeCost['average_margin'] . '%' }}</td></tr>
                            @endif
                        </tfoot>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">Only the material lines with a size-wise consumption change ({{ collect($sizeCost['lines'])->map(fn ($l) => $l['costing'])->implode(', ') ?: 'none matched' }}); the rate and wastage of the line are kept. Red = costs more than the sheet.</p>
                @if($sizeCost['unmatched'])<p class="small text-warning mt-1 mb-0">No costing line matches: {{ implode(', ', $sizeCost['unmatched']) }}. Name the costing line like the BOM material so it is counted.</p>@endif
            </x-card>
        @endif

        {{-- Estimate against the actual BOM. --}}
        @if($comparison && $comparison['has_bom'])
            @php($m2 = fn ($n) => $n === null ? '—' : number_format((float) $n, 2))
            @php($m4 = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 4), '0'), '.'))
            <x-card class="mb-3" body-class="p-3">
                <x-slot:title>Pre-costing vs BOM <span class="text-muted fw-normal fs-6">per {{ \App\Models\Costing::BASES[$costing->basis] }}</span></x-slot:title>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr>
                            <th>Costing line</th><th class="text-end">Costed Qty</th><th class="text-end">Costed Rate</th><th class="text-end">Costed Amount</th>
                            <th>BOM material</th><th class="text-end">BOM Cons.</th><th class="text-end">PI Rate</th><th class="text-end">BOM Amount</th><th class="text-end">Difference</th>
                        </tr></thead>
                        <tbody>
                        @foreach($comparison['rows'] as $r)
                            <tr>
                                <td class="fw-semibold">{{ $r['description'] }}<div class="small text-muted">{{ $r['head'] }}</div></td>
                                <td class="text-end">{{ $m4($r['cost_qty']) }}</td><td class="text-end">{{ $m4($r['cost_rate']) }}</td><td class="text-end">{{ $m2($r['cost_amount']) }}</td>
                                <td>{{ $r['bom']['description'] ?? '—' }}@if(! $r['bom'])<div class="small text-warning">Not in the BOM</div>@endif</td>
                                <td class="text-end {{ $r['bom'] && $r['bom']['cons'] > $r['cost_qty'] + 0.0001 ? 'text-danger fw-semibold' : '' }}">{{ $r['bom'] ? $m4($r['bom']['cons']) . ' ' . ($r['bom']['uom'] ?? '') : '—' }}</td>
                                <td class="text-end">{{ $r['bom'] ? $m4($r['bom']['rate']) : '—' }}</td>
                                <td class="text-end">{{ $m2($r['bom_amount']) }}</td>
                                <td class="text-end fw-semibold {{ ($r['difference'] ?? 0) > 0 ? 'text-danger' : (($r['difference'] ?? 0) < 0 ? 'text-success' : '') }}">{{ $r['difference'] === null ? '—' : ($r['difference'] > 0 ? '+' : '') . $m2($r['difference']) }}</td>
                            </tr>
                        @endforeach
                        @foreach($comparison['uncosted'] as $u)
                            <tr>
                                <td class="text-warning">Not costed</td><td></td><td></td><td></td>
                                <td>{{ $u['description'] }}</td>
                                <td class="text-end">{{ $m4($u['cons']) }} {{ $u['uom'] }}</td><td class="text-end">{{ $m4($u['rate']) }}</td>
                                <td class="text-end">{{ $u['rate'] !== null ? $m2($u['cons'] * $u['rate']) : '—' }}</td><td></td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr class="fw-semibold"><td colspan="3">Material total</td><td class="text-end">{{ $m2($comparison['costing_total']) }}</td><td colspan="3" class="text-muted small fw-normal">{{ $comparison['priced'] }} of {{ $comparison['rows']->count() }} costed lines have a PI rate</td><td class="text-end">{{ $m2($comparison['bom_total']) }}</td><td></td></tr></tfoot>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">BOM consumption is the average per garment including wastage{{ $costing->basis === 'dz' ? ', times 12 for a dozen' : '' }}. PI rate is what Supply Chain recorded. Red difference = the BOM costs more than the costing.</p>
            </x-card>
        @endif

        @if($revisions->count() > 1)
            <x-card title="Revision history" body-class="p-3">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Revision</th><th>Status</th><th class="text-end">Total cost</th><th class="text-end">Quoted price</th><th class="text-end">Margin</th><th>Finalized</th></tr></thead>
                    <tbody>
                    @foreach($revisions as $r)
                        <tr>
                            <td>{{ $r->revision }}</td><td>{{ ucfirst($r->status) }}</td>
                            <td class="text-end">{{ $money($r->total_cost) }}</td><td class="text-end">{{ $money($r->quoted_price) }}</td>
                            <td class="text-end">{{ $r->marginPct() !== null ? $r->marginPct() . '%' : '—' }}</td>
                            <td>{{ $r->finalized_at ? $r->finalized_at->format('d M Y') . ' · ' . $r->finalizer?->name : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        @if($editable)
            <template id="lineTemplate">
                @include('costing._line', ['i' => '__I__', 'head' => '__HEAD__', 'line' => null, 'editable' => true])
            </template>
            <script>
            (function () {
                var next = {{ $idx }};
                var form = document.getElementById('costForm');

                function amountOf(tr) {
                    var q = parseFloat(tr.querySelector('[data-f=qty]').value) || 0,
                        w = parseFloat(tr.querySelector('[data-f=wastage]').value) || 0,
                        r = parseFloat(tr.querySelector('[data-f=rate]').value) || 0;
                    return Math.round(q * (1 + w / 100) * r * 10000) / 10000;
                }
                function refresh() {
                    var total = 0;
                    document.querySelectorAll('[data-head-body]').forEach(function (body) {
                        var sub = 0;
                        body.querySelectorAll('tr').forEach(function (tr) {
                            var a = amountOf(tr); sub += a;
                            tr.querySelector('[data-amount]').textContent = a.toFixed(2);
                        });
                        total += sub;
                        var badge = document.querySelector('[data-head-total="' + body.dataset.headBody + '"]');
                        if (badge) badge.textContent = sub.toFixed(2);
                    });
                    var grand = document.querySelector('[data-grand-total]');
                    if (grand) grand.textContent = total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                form.addEventListener('input', refresh);
                form.addEventListener('click', function (e) {
                    var add = e.target.closest('.js-add-line'), del = e.target.closest('.js-del-line');
                    if (add) {
                        var html = document.getElementById('lineTemplate').innerHTML.replace(/__I__/g, next++).replace(/__HEAD__/g, add.dataset.head);
                        document.querySelector('[data-head-body="' + add.dataset.head + '"]').insertAdjacentHTML('beforeend', html);
                    }
                    if (del) { del.closest('tr').remove(); refresh(); }
                });
                refresh();
            })();
            </script>
        @endif
    @endif
</div>
@endsection

@section('styles')
<style>
    .costing-actions {
        position: sticky;
        bottom: 12px;
        z-index: 5;
        padding: 12px 16px;
        background: var(--bs-body-bg, #fff);
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 12px 28px rgba(15, 23, 42, .10);
    }
</style>
@endsection
