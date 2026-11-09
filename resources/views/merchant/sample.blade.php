@extends('layouts.app')

@section('title', 'Sample ' . $sample->tag_id)

@section('content')
@php
    $m = $sample->motherStyle;
    $styleRoute = auth()->user()->hasRole('merchant') ? 'merchant.styles.mothers.show' : 'merchant.status.style';
    $rework = $sample->reworkCount();
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Samples', 'url' => route('merchant.samples.index')], ['label' => $sample->tag_id ?: 'Sample']]" />

    <x-page-header icon="scissors" :eyebrow="$m->buyer?->buyer_name . ' · ' . $m->season?->code" :title="($sample->type?->name ?? 'Sample') . ' — style ' . $m->style_no"
                   :copy="'Tag ID ' . ($sample->tag_id ?: '—') . '. Every status change and buyer comment of this sample.'">
        <x-slot:actions><a href="{{ route('merchant.samples.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Samples</a></x-slot:actions>
    </x-page-header>

    <x-card class="mb-3" body-class="p-3">
        <div class="row g-3">
            <div class="col-6 col-md-2"><div class="small text-muted">Tag ID</div><div class="fw-semibold">{{ $sample->tag_id ?: '—' }}</div></div>
            <div class="col-6 col-md-3"><div class="small text-muted">Style</div><div><a href="{{ route($styleRoute, $m) }}">{{ $m->style_no }}</a>@if($sample->childStyle) · {{ $sample->childStyle->style_no }}@endif</div></div>
            <div class="col-6 col-md-3"><div class="small text-muted">Colour / Size / Qty</div><div>{{ $sample->gmts_color ?: '—' }} · {{ $sample->size ?: '—' }} · {{ $sample->qty }}</div></div>
            <div class="col-6 col-md-2"><div class="small text-muted">Needed by</div><div>{{ $sample->required_date?->format('d M Y') ?? '—' }}</div></div>
            <div class="col-6 col-md-2"><div class="small text-muted">Status</div><div><span class="badge {{ $sample->badgeClass() }}">{{ \App\Models\Sample::STATUSES[$sample->status] ?? $sample->status }}</span>
                @if($rework > 0)<span class="badge bg-warning text-dark ms-1">Sent back {{ $rework }}x</span>@endif</div></div>
        </div>
    </x-card>

    <x-card title="History" body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Date</th><th>Status</th><th>Buyer comment</th><th>Recorded by</th></tr></thead>
                <tbody>
                @forelse($sample->logs as $l)
                    <tr>
                        <td class="text-nowrap">{{ $l->event_date->format('d M Y') }}</td>
                        <td><span class="badge {{ (new \App\Models\Sample(['status' => $l->status]))->badgeClass() }}">{{ \App\Models\Sample::STATUSES[$l->status] ?? $l->status }}</span></td>
                        <td>{{ $l->note ?: '—' }}</td>
                        <td>{{ $l->user?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No history yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
