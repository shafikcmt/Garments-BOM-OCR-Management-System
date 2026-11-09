@extends('layouts.app')

@section('title', 'Inquiry ' . $inquiry->reference)

@section('content')
@php
    $tone = ['new' => 'secondary', 'costing' => 'info', 'quoted' => 'primary', 'won' => 'success', 'lost' => 'danger', 'cancelled' => 'secondary'];
    $st = $inquiry->status;
    $styleRoute = auth()->user()->hasRole('merchant') ? 'merchant.styles.mothers.show' : 'merchant.status.style';
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Inquiries', 'url' => route('merchant.inquiries.index')], ['label' => $inquiry->reference]]" />

    <x-page-header icon="chat-left-text" :eyebrow="$inquiry->buyer?->buyer_name" :title="'Inquiry ' . $inquiry->reference . ' — ' . $inquiry->style_ref"
                   copy="Cost the style, record the quotation, then mark the result.">
        <x-slot:actions>
            <a href="{{ route('merchant.inquiries.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Inquiries</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <x-card class="mb-3" body-class="p-3">
        <x-slot:title>Status <span class="badge bg-{{ $tone[$st] ?? 'secondary' }} ms-2">{{ \App\Models\Inquiry::STATUSES[$st] ?? $st }}</span>
            @if($inquiry->isQuoteLate($today))<span class="badge bg-danger text-white ms-1">Quote date passed</span>@endif</x-slot:title>
        <div class="row g-3 mb-2">
            <div class="col-6 col-md-3"><div class="small text-muted">Received</div><div>{{ $inquiry->received_date?->format('d M Y') }}</div></div>
            <div class="col-6 col-md-3"><div class="small text-muted">Quote by</div><div>{{ $inquiry->quote_due_date?->format('d M Y') ?? '—' }}</div></div>
            <div class="col-6 col-md-3"><div class="small text-muted">Quoted</div><div>@if($inquiry->quoted_price !== null)<strong>{{ $inquiry->currency }} {{ rtrim(rtrim(number_format((float) $inquiry->quoted_price, 4), '0'), '.') }}</strong> on {{ $inquiry->quoted_date?->format('d M Y') }}@else — @endif</div></div>
            <div class="col-6 col-md-3"><div class="small text-muted">Style in Style Library</div>
                <div>@if($inquiry->motherStyle)<a href="{{ route($styleRoute, $inquiry->motherStyle) }}">{{ $inquiry->motherStyle->style_no }}</a> · <a href="{{ route('costing.show', [$inquiry->motherStyle, 'mode' => 'buyer']) }}">Pre-costing</a>@else — @endif</div></div>
        </div>
        @if($st === 'lost' && $inquiry->lost_reason)<p class="mb-2"><strong>Lost because:</strong> {{ $inquiry->lost_reason }}</p>@endif

        @if($canEdit)
            <div class="d-flex flex-wrap gap-3 align-items-start border-top pt-3">
                @if(in_array($st, ['new', 'costing'], true))
                    <form method="POST" action="{{ route('merchant.inquiries.start_costing', $inquiry) }}" class="row g-2 align-items-end">
                        @csrf
                        @unless($inquiry->motherStyle)
                            <div class="col-auto"><label class="form-label mb-1">Season</label>
                                <select name="season_id" class="form-select form-select-sm"><option value="">Choose</option>@foreach($seasons as $s)<option value="{{ $s->id }}" @selected($inquiry->buyer_season_id === $s->id)>{{ $s->code }}</option>@endforeach</select></div>
                            <div class="col-auto"><label class="form-label mb-1">or new season</label><input type="text" name="new_season" class="form-control form-control-sm" maxlength="50" placeholder="e.g. FA27" style="width:110px"></div>
                        @endunless
                        <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-calculator me-1" aria-hidden="true"></i>{{ $inquiry->motherStyle ? 'Open Pre-costing' : 'Start Costing' }}</button></div>
                    </form>
                @endif
                @if(in_array($st, ['new', 'costing', 'quoted'], true))
                    <form method="POST" action="{{ route('merchant.inquiries.quote', $inquiry) }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-auto"><label class="form-label mb-1">Quoted price *</label>
                            <input type="number" step="any" min="0" name="quoted_price" class="form-control form-control-sm text-end" style="width:120px" required value="{{ old('quoted_price', $inquiry->quoted_price ?? $suggested['price'] ?? '') }}"></div>
                        <div class="col-auto"><label class="form-label mb-1">Currency</label>
                            <select name="currency" class="form-select form-select-sm">@foreach(\App\Models\Costing::CURRENCIES as $c)<option @selected(($suggested['currency'] ?? $inquiry->currency) === $c)>{{ $c }}</option>@endforeach</select></div>
                        <div class="col-auto"><label class="form-label mb-1">Date sent</label><input type="date" name="quoted_date" class="form-control form-control-sm" value="{{ $inquiry->quoted_date?->toDateString() ?? $today->toDateString() }}"></div>
                        <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>{{ $st === 'quoted' ? 'Update Quotation' : 'Record Quotation' }}</button></div>
                        @if($suggested)<div class="col-12 form-text">Prefilled from pre-costing revision {{ $suggested['revision'] }} ({{ $suggested['currency'] }} {{ rtrim(rtrim(number_format($suggested['price'], 4), '0'), '.') }}).</div>@endif
                    </form>
                @endif
                @if($st === 'quoted')
                    <form method="POST" action="{{ route('merchant.inquiries.won', $inquiry) }}">@csrf
                        <button class="btn btn-success btn-sm"><i class="bi bi-trophy me-1" aria-hidden="true"></i>Mark Won</button></form>
                    <form method="POST" action="{{ route('merchant.inquiries.lost', $inquiry) }}" class="row g-2 align-items-end">@csrf
                        <div class="col-auto"><input type="text" name="lost_reason" class="form-control form-control-sm" maxlength="255" placeholder="Why was it lost?" required></div>
                        <div class="col-auto"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Mark Lost</button></div>
                    </form>
                @endif
                @if(in_array($st, ['new', 'costing', 'quoted'], true))
                    <form method="POST" action="{{ route('merchant.inquiries.cancel', $inquiry) }}" onsubmit="return confirm('Cancel this inquiry?')">@csrf
                        <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Cancel Inquiry</button></form>
                @endif
                @if(in_array($st, ['lost', 'cancelled'], true))
                    <form method="POST" action="{{ route('merchant.inquiries.reopen', $inquiry) }}">@csrf
                        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reopen</button></form>
                @endif
            </div>
        @endif
        @if($st === 'won')
            <p class="small text-muted mt-3 mb-0">When the buyer's order comes, use Import Buyer Excel with the same season and style so it lands on this style{{ $inquiry->motherStyle ? ' (' . $inquiry->motherStyle->style_no . ')' : '' }}.</p>
        @endif
    </x-card>

    <x-card title="Details" body-class="p-3">
        <form method="POST" action="{{ route('merchant.inquiries.update', $inquiry) }}" class="row g-2 align-items-end">
            @csrf @method('PUT')
            <div class="col-6 col-md-2"><label class="form-label mb-1">Style / design no. *</label><input type="text" name="style_ref" class="form-control form-control-sm" maxlength="80" required value="{{ old('style_ref', $inquiry->style_ref) }}" @disabled(! $canEdit)></div>
            <div class="col-12 col-md-4"><label class="form-label mb-1">Description</label><input type="text" name="description" class="form-control form-control-sm" maxlength="255" value="{{ old('description', $inquiry->description) }}" @disabled(! $canEdit)></div>
            <div class="col-6 col-md-2"><label class="form-label mb-1">Product group</label><input type="text" name="product_group" class="form-control form-control-sm" maxlength="60" value="{{ old('product_group', $inquiry->product_group) }}" @disabled(! $canEdit)></div>
            <div class="col-6 col-md-2"><label class="form-label mb-1">Est. qty</label><input type="number" min="1" name="est_qty" class="form-control form-control-sm text-end" value="{{ old('est_qty', $inquiry->est_qty) }}" @disabled(! $canEdit)></div>
            <div class="col-4 col-md-1"><label class="form-label mb-1">Target price</label><input type="number" step="any" min="0" name="target_price" class="form-control form-control-sm text-end" value="{{ old('target_price', $inquiry->target_price !== null ? rtrim(rtrim(number_format((float) $inquiry->target_price, 4, '.', ''), '0'), '.') : '') }}" @disabled(! $canEdit)></div>
            <div class="col-2 col-md-1"><label class="form-label mb-1">Curr.</label><select name="currency" class="form-select form-select-sm" @disabled(! $canEdit)>@foreach(\App\Models\Costing::CURRENCIES as $c)<option @selected(old('currency', $inquiry->currency) === $c)>{{ $c }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><label class="form-label mb-1">Quote by</label><input type="date" name="quote_due_date" class="form-control form-control-sm" value="{{ old('quote_due_date', $inquiry->quote_due_date?->toDateString()) }}" @disabled(! $canEdit)></div>
            <div class="col-12 col-md-6"><label class="form-label mb-1">Note</label><input type="text" name="note" class="form-control form-control-sm" maxlength="2000" value="{{ old('note', $inquiry->note) }}" @disabled(! $canEdit)></div>
            @if($canEdit)<div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Details</button></div>@endif
        </form>
        <p class="small text-muted mt-3 mb-0">Added by {{ $inquiry->creator?->name ?? '—' }} on {{ $inquiry->created_at?->format('d M Y') }}.</p>
    </x-card>
</div>
@endsection
