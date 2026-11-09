@extends('layouts.app')

@section('title', 'Merchandising Analytics')

@section('content')
@php
    $num = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 0);
    $isBuyer = $data['group_by'] === 'buyer';
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Analytics']]" />

    <x-page-header icon="graph-up-arrow" eyebrow="Merchandising" title="Analytics"
                   copy="What the orders are worth and what is left of the price, by buyer, and how many quotations are won. Figures are estimates from each style's final buyer pre-costing.">
    </x-page-header>

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="anBuyer">Buyer</label>
                <select name="buyer" id="anBuyer" class="form-select form-select-sm">
                    <option value="">All buyers (grouped by buyer)</option>
                    @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }} (by season)</option>@endforeach
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button></div>
        </form>
    </x-card>

    @if($data['uncosted'] > 0)
        <div class="alert alert-warning py-2">
            <strong>{{ $data['uncosted'] }}</strong> style(s) with <strong>{{ $num($data['uncosted_qty']) }}</strong> pieces have no final buyer pre-costing with a quoted price, so they are not in the money columns. Finalize their costing to include them.
        </div>
    @endif

    <x-card :title="'Order value by ' . ($isBuyer ? 'buyer' : 'season')" class="mb-3" body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>{{ $isBuyer ? 'Buyer' : 'Season' }}</th><th>Currency</th><th class="text-end">Styles</th><th class="text-end">Pieces</th><th class="text-end">Order value</th><th class="text-end">Cost</th><th class="text-end">Profit</th><th class="text-end">Margin</th></tr></thead>
                <tbody>
                @forelse($data['rows'] as $r)
                    <tr>
                        <td class="fw-semibold">{{ $r['group'] }}</td><td>{{ $r['currency'] }}</td><td class="text-end">{{ $r['styles'] }}</td>
                        <td class="text-end">{{ $num($r['qty']) }}</td><td class="text-end">{{ $money($r['revenue']) }}</td><td class="text-end">{{ $money($r['cost']) }}</td>
                        <td class="text-end">{{ $money($r['profit']) }}</td>
                        <td class="text-end fw-semibold {{ ($r['margin'] ?? 0) < 0 ? 'text-danger' : '' }}">{{ $r['margin'] === null ? '—' : $r['margin'] . '%' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No costed orders yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <x-card title="Styles, lowest margin first" body-class="p-0">
                <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead class="sticky-top bg-white"><tr><th>Style</th><th>{{ $isBuyer ? 'Buyer' : 'Season' }}</th><th class="text-end">Pieces</th><th class="text-end">Value</th><th class="text-end">Margin</th></tr></thead>
                        <tbody>
                        @forelse(array_slice($data['styles'], 0, 50) as $s)
                            <tr>
                                <td><a href="{{ route(auth()->user()->hasRole('merchant') ? 'merchant.styles.mothers.show' : 'merchant.status.style', $s['style']) }}">{{ $s['style']->style_no }}</a></td>
                                <td>{{ $s['group'] }}</td><td class="text-end">{{ $num($s['qty']) }}</td>
                                <td class="text-end">{{ $s['currency'] }} {{ $money($s['revenue']) }}</td>
                                <td class="text-end fw-semibold {{ $s['margin'] < 0 ? 'text-danger' : ($s['margin'] < 10 ? 'text-warning' : '') }}">{{ $s['margin'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">Nothing to show.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
        <div class="col-12 col-xl-5">
            <x-card title="Quotations won" body-class="p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead><tr><th>Buyer</th><th class="text-end">Open</th><th class="text-end">Won</th><th class="text-end">Lost</th><th class="text-end">Win rate</th></tr></thead>
                        <tbody>
                        @forelse($inquiries as $q)
                            <tr><td class="fw-semibold">{{ $q['buyer'] }}</td><td class="text-end">{{ $q['open'] }}</td><td class="text-end">{{ $q['won'] }}</td><td class="text-end">{{ $q['lost'] }}</td><td class="text-end">{{ $q['win_rate'] === null ? '—' : $q['win_rate'] . '%' }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No inquiries yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>
    <p class="small text-muted mt-3 mb-0">Pieces are the order lines of the style (a colour / PO repeated in two files counts once). Price and cost are per piece from the latest final buyer pre-costing (a dozen sheet is divided by 12). Different currencies are never added together. Capacity is not shown yet.</p>
</div>
@endsection
