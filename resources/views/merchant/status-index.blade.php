@extends('layouts.app')

@section('title', 'Style Status')

@section('content')
@php($pct = fn ($n, $of) => $of > 0 ? round($n / $of * 100) : 0)
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="bar-chart-steps" eyebrow="Merchandising"
                   title="Style Status"
                   copy="Every season of a buyer, and how far its materials have come." />

    <x-flash />

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
            <label class="me-1" for="statusBuyer">Buyer</label>
            <select name="buyer" id="statusBuyer" class="form-select form-select-sm" style="max-width:260px" onchange="this.form.submit()">
                <option value="">All buyers</option>
                @foreach($buyers as $b)<option value="{{ $b->id }}" {{ $buyer && $buyer->id === $b->id ? 'selected' : '' }}>{{ $b->buyer_name }}</option>@endforeach
            </select>
            <noscript><button class="btn btn-outline-primary btn-sm">Show</button></noscript>
        </form>
    </x-card>

    <x-card body-class="p-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>Buyer</th><th>Season</th><th class="text-end">Styles</th><th class="text-end">Order lines</th>
                    <th class="text-end">Order qty</th><th class="text-end">Materials</th>
                    <th style="min-width:150px">Ordered</th><th style="min-width:150px">Received</th><th class="text-end">Late</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    @php($t = $r['totals'])
                    <tr>
                        <td>{{ $r['season']->buyer?->buyer_name }}</td>
                        <td class="fw-semibold">{{ $r['season']->code }}</td>
                        <td class="text-end">{{ $r['styles'] }}</td>
                        <td class="text-end">{{ $t['order_lines'] }}</td>
                        <td class="text-end">{{ number_format($t['order_qty']) }}</td>
                        <td class="text-end">{{ $t['materials'] }}</td>
                        <td>@include('merchant._progress', ['value' => $pct($t['ordered'], $t['materials'])])</td>
                        <td>@include('merchant._progress', ['value' => $pct($t['received'], $t['materials']), 'tone' => 'success'])</td>
                        <td class="text-end">@if($t['late'] > 0)<span class="badge bg-danger-subtle text-danger">{{ $t['late'] }}</span>@else — @endif</td>
                        <td class="text-end"><a href="{{ route('merchant.status.season', $r['season']) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>View Season</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">No season yet. Import a buyer's Excel with its season to start.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
