@extends('layouts.app')

@section('title', 'Inquiries')

@section('content')
@php
    $tone = ['new' => 'secondary', 'costing' => 'info', 'quoted' => 'primary', 'won' => 'success', 'lost' => 'danger', 'cancelled' => 'secondary'];
    $money = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Inquiries']]" />

    <x-page-header icon="chat-left-text" eyebrow="Merchandising" title="Inquiries and Quotations"
                   copy="A buyer asks for a price on a style. Cost it, send the quotation, and record whether it was won or lost. A won inquiry is followed by the buyer's order on the same style.">
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card icon="inbox" tone="primary" label="Open inquiries" :value="$kpis['open']" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="exclamation-triangle" tone="danger" label="Quote date passed" :value="$kpis['late']" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="hourglass-split" tone="primary" label="Quoted, waiting for buyer" :value="$kpis['awaiting']" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="trophy" tone="success" :label="'Won ' . $kpis['won'] . ' · Lost ' . $kpis['lost']" :value="$kpis['win_rate'] === null ? '—' : $kpis['win_rate'] . '% won'" /></div>
    </div>

    @if($canEdit)
        <x-card title="New inquiry" class="mb-3" body-class="p-3">
            <form method="POST" action="{{ route('merchant.inquiries.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1" for="iqBuyer">Buyer *</label>
                    <select name="buyer_id" id="iqBuyer" class="form-select form-select-sm" required>
                        <option value="">Choose</option>
                        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected(old('buyer_id', $buyer?->id) == $b->id)>{{ $b->buyer_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="iqStyle">Style / design no. *</label>
                    <input type="text" name="style_ref" id="iqStyle" class="form-control form-control-sm" maxlength="80" required value="{{ old('style_ref') }}"></div>
                <div class="col-12 col-md-3"><label class="form-label mb-1" for="iqDesc">Description</label>
                    <input type="text" name="description" id="iqDesc" class="form-control form-control-sm" maxlength="255" value="{{ old('description') }}"></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="iqGroup">Product group</label>
                    <input type="text" name="product_group" id="iqGroup" class="form-control form-control-sm" maxlength="60" value="{{ old('product_group') }}"></div>
                <div class="col-6 col-md-1"><label class="form-label mb-1" for="iqQty">Est. qty</label>
                    <input type="number" name="est_qty" id="iqQty" min="1" class="form-control form-control-sm text-end" value="{{ old('est_qty') }}"></div>
                <div class="col-4 col-md-1"><label class="form-label mb-1" for="iqTarget">Target price</label>
                    <input type="number" step="any" min="0" name="target_price" id="iqTarget" class="form-control form-control-sm text-end" value="{{ old('target_price') }}"></div>
                <div class="col-2 col-md-1"><label class="form-label mb-1" for="iqCur">Curr.</label>
                    <select name="currency" id="iqCur" class="form-select form-select-sm">@foreach(\App\Models\Costing::CURRENCIES as $c)<option>{{ $c }}</option>@endforeach</select></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="iqRecv">Received *</label>
                    <input type="date" name="received_date" id="iqRecv" class="form-control form-control-sm" required value="{{ old('received_date', $today->toDateString()) }}"></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="iqDue">Quote by</label>
                    <input type="date" name="quote_due_date" id="iqDue" class="form-control form-control-sm" value="{{ old('quote_due_date') }}"></div>
                <div class="col-12 col-md-4"><label class="form-label mb-1" for="iqNote">Note</label>
                    <input type="text" name="note" id="iqNote" class="form-control form-control-sm" maxlength="2000" value="{{ old('note') }}"></div>
                <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Inquiry</button></div>
            </form>
        </x-card>
    @endif

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="fBuyer">Buyer</label>
                <select name="buyer" id="fBuyer" class="form-select form-select-sm">
                    <option value="">All buyers</option>
                    @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1" for="fStatus">Status</label>
                <select name="status" id="fStatus" class="form-select form-select-sm">
                    <option value="open" @selected($status === 'open')>Open</option>
                    <option value="all" @selected($status === 'all')>All</option>
                    @foreach(\App\Models\Inquiry::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button></div>
        </form>
    </x-card>

    <x-card body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Ref.</th><th>Buyer</th><th>Style</th><th class="text-end">Est. qty</th><th class="text-end">Target</th><th class="text-end">Quoted</th><th>Quote by</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($inquiries as $i)
                    <tr>
                        <td class="text-nowrap">{{ $i->reference }}</td>
                        <td>{{ $i->buyer?->buyer_name }}</td>
                        <td class="fw-semibold">{{ $i->style_ref }}@if($i->description)<div class="small text-muted fw-normal">{{ $i->description }}</div>@endif</td>
                        <td class="text-end">{{ $i->est_qty ? number_format($i->est_qty) : '—' }}</td>
                        <td class="text-end">{{ $money($i->target_price) }}</td>
                        <td class="text-end">{{ $money($i->quoted_price) }}</td>
                        <td class="text-nowrap">{{ $i->quote_due_date?->format('d M Y') ?? '—' }}@if($i->isQuoteLate($today))<span class="badge bg-danger text-white ms-1">Late</span>@endif</td>
                        <td><span class="badge bg-{{ $tone[$i->status] ?? 'secondary' }}">{{ \App\Models\Inquiry::STATUSES[$i->status] ?? $i->status }}</span></td>
                        <td class="text-end"><a href="{{ route('merchant.inquiries.show', $i) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No inquiries here.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
