@extends('layouts.app')

@section('title', 'Buyer Orders ' . $mother->style_no)

@section('content')
@php
    $num = fn ($n) => $n === null ? '—' : number_format((float) $n);
    $tone = ['draft' => 'secondary', 'pending' => 'warning', 'approved' => 'success', 'returned' => 'danger'];
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.status.index')],
        ['label' => 'Style ' . $mother->style_no, 'url' => $backUrl],
        ['label' => 'Buyer Orders'],
    ]" />

    <x-page-header icon="file-earmark-check" :eyebrow="$mother->buyer?->buyer_name . ' · ' . $mother->season?->code"
                   :title="'Buyer Orders — style ' . $mother->style_no"
                   copy="One card per customer PO. Quantity, colour and ship date come from the workspace; destination, contract and quotation are kept here.">
        <x-slot:actions>
            <a href="{{ route('merchant.sizes.show', $mother) }}" class="btn btn-outline-secondary"><i class="bi bi-grid-3x3 me-1" aria-hidden="true"></i>Size Breakdown</a>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    @if($gateOn)
        <div class="alert alert-info py-2">Supply Chain cannot generate a PO for a customer PO below until it is <strong>Approved</strong>. Send each one for approval.</div>
    @endif

    @forelse($rows as $r)
        @php($o = $r['order'])
        @php($label = $r['po'] !== '' ? $r['po'] : 'No PO number')
        <x-card class="mb-3" body-class="p-3">
            <x-slot:title>PO {{ $label }}
                <span class="badge bg-{{ $tone[$r['status']] ?? 'secondary' }} ms-2">{{ \App\Models\BuyerOrder::STATUSES[$r['status']] ?? $r['status'] }}</span>
                @if($r['changed_after_approval'])<span class="badge bg-warning text-dark ms-1">Changed after approval</span>@endif
            </x-slot:title>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><div class="small text-muted">Colours</div><div>{{ $r['colors'] ? implode(', ', $r['colors']) : '—' }}</div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Order qty</div><div class="fw-semibold">{{ $num($r['order_qty']) }}</div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Size total</div>
                    <div>{{ $r['size_total'] > 0 ? number_format($r['size_total']) : '—' }}
                        @if($r['size_total'] > 0 && $r['order_qty'] !== null && abs($r['size_total'] - $r['order_qty']) > 0.001)<span class="badge bg-warning text-dark ms-1">Differs</span>@endif
                    </div></div>
                <div class="col-6 col-md-2"><div class="small text-muted">Ship date</div><div>{{ $r['ship_date'] ? \Illuminate\Support\Carbon::parse($r['ship_date'])->format('d M Y') : '—' }}</div></div>
                @unless($r['in_workspace'])<div class="col-12"><span class="text-muted small">This PO is no longer in the workspace.</span></div>@endunless
            </div>

            @if($o && $o->approval_status !== 'draft')
                <p class="small text-muted mb-3">
                    @if($o->submitted_at)Sent by {{ $o->submitter?->name ?? '—' }} on {{ $o->submitted_at->format('d M Y H:i') }}.@endif
                    @if($o->decided_at) {{ $o->approval_status === 'returned' ? 'Returned' : 'Approved' }} by {{ $o->decider?->name ?? '—' }} on {{ $o->decided_at->format('d M Y H:i') }}.@endif
                    @if($o->decision_comment)<br><strong>Comment:</strong> {{ $o->decision_comment }}@endif
                </p>
            @endif

            <form method="POST" action="{{ route('merchant.orders.update', $mother) }}" class="row g-2 align-items-end">
                @csrf @method('PUT')
                <input type="hidden" name="po" value="{{ $r['po'] }}">
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Destination</label>
                    <input type="text" name="destination" class="form-control form-control-sm" maxlength="160" value="{{ $o?->destination }}" @disabled(! $canEdit)>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Contract / L/C ref.</label>
                    <input type="text" name="contract_ref" class="form-control form-control-sm" maxlength="160" value="{{ $o?->contract_ref }}" @disabled(! $canEdit)>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Quotation (final costing)</label>
                    <select name="costing_id" class="form-select form-select-sm" @disabled(! $canEdit)>
                        <option value="">None</option>
                        @foreach($costings as $c)
                            <option value="{{ $c->id }}" @selected($o?->costing_id === $c->id)>{{ \App\Models\Costing::MODES[$c->mode] ?? $c->mode }} rev {{ $c->revision }}@if($c->quoted_price) · {{ $c->currency }} {{ rtrim(rtrim(number_format((float) $c->quoted_price, 4), '0'), '.') }}@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1">Remark</label>
                    <input type="text" name="remark" class="form-control form-control-sm" maxlength="2000" value="{{ $o?->remark }}" @disabled(! $canEdit)>
                </div>
                @if($canEdit)
                    <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                        @if($r['in_workspace'] && $r['status'] !== 'pending')
                            <button formaction="{{ route('merchant.orders.submit', $mother) }}" class="btn btn-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Send for Approval</button>
                        @endif
                    </div>
                @endif
            </form>

            @if($r['can_decide'])
                <form method="POST" action="{{ route('merchant.orders.approve', $o) }}" class="row g-2 align-items-end mt-2 border-top pt-3">
                    @csrf
                    <div class="col-12 col-md-6">
                        <label class="form-label mb-1">Comment (needed to return)</label>
                        <input type="text" name="comment" class="form-control form-control-sm" maxlength="1000">
                    </div>
                    <div class="col-12 col-md-6 d-flex gap-2">
                        <button class="btn btn-success btn-sm"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Approve</button>
                        <button formaction="{{ route('merchant.orders.return', $o) }}" class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Return</button>
                    </div>
                </form>
            @endif
        </x-card>
    @empty
        <x-card body-class="p-3"><p class="text-muted mb-0">No orders yet for this style. Import the buyer's Excel or add the order first.</p></x-card>
    @endforelse
</div>
@endsection
