@extends('layouts.app')

@section('title', 'Post-costing ' . $mother->style_no)

@section('content')
@php
    $m0 = fn ($n) => number_format((float) $n, 0);
    $m2 = fn ($n) => number_format((float) $n, 2);
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $cur = $data['costing']->currency ?? '';
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Style ' . $mother->style_no, 'url' => $backUrl], ['label' => 'Post-costing']]" />

    <x-page-header icon="clipboard-data" :eyebrow="$mother->buyer?->buyer_name . ' · ' . $mother->season?->code" :title="'Material Post-costing — style ' . $mother->style_no"
                   copy="What the final costing said the materials would cost, against what Store has received and issued so far.">
        <x-slot:actions><a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style</a></x-slot:actions>
    </x-page-header>

    @if(! $data)
        <x-card body-class="p-3"><p class="text-muted mb-0">This style has no BOM yet, so there is nothing to compare.</p></x-card>
    @else
        @unless($data['costing'])
            <div class="alert alert-warning py-2">This style has no <strong>final buyer pre-costing</strong>, so there is no budget to compare with. Received and issued values are shown anyway. <a href="{{ route('costing.show', $mother) }}">Open the costing</a>.</div>
        @endunless

        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-3"><x-stat-card icon="wallet2" tone="primary" :label="'Material budget (' . $m0($data['order_qty']) . ' pcs)'" :value="$data['budget'] !== null ? $cur . ' ' . $m0($data['budget']) : '—'" /></div>
            <div class="col-6 col-xl-3"><x-stat-card icon="box-arrow-in-down" tone="primary" :label="'Received' . ($data['received_pct'] !== null ? ' · ' . $data['received_pct'] . '% of budget' : '')" :value="$m0($data['received_value'])" /></div>
            <div class="col-6 col-xl-3"><x-stat-card icon="box-arrow-up" tone="primary" :label="'Issued to production' . ($data['issued_pct'] !== null ? ' · ' . $data['issued_pct'] . '% of budget' : '')" :value="$m0($data['issued_value'])" /></div>
            <div class="col-6 col-xl-3"><x-stat-card icon="archive" tone="{{ $data['in_stock_value'] > 0 ? 'warning' : 'success' }}" label="Bought, not issued (in stock)" :value="$m0($data['in_stock_value'])" /></div>
        </div>

        @if($data['variance'] !== null && $data['has_receipts'])
            <div class="alert py-2 alert-{{ $data['variance'] > 0 ? 'danger' : 'success' }}">
                Material bought so far is <strong>{{ $cur }} {{ $m0(abs($data['variance'])) }} {{ $data['variance'] > 0 ? 'above' : 'below' }}</strong> the material budget ({{ $data['variance_pct'] > 0 ? '+' : '' }}{{ $data['variance_pct'] }}%).
                @if($data['variance'] < 0)This is normal while the order is still being received.@endif
            </div>
        @endif
        @if($data['unpriced'] > 0)
            <div class="alert alert-warning py-2">{{ $data['unpriced'] }} material(s) were received with <strong>no price</strong>, so their value is zero. Add the price on Material Receiving.</div>
        @endif
        @if($data['issued_without_receipt'] > 0)
            <div class="alert alert-warning py-2">{{ $data['issued_without_receipt'] }} material(s) were <strong>issued but never received</strong> against this style, so their issued value cannot be priced.</div>
        @endif

        <x-card title="By material" body-class="p-0" class="mb-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th>Material</th><th>Unit</th><th class="text-end">Received qty</th><th class="text-end">Received value</th><th class="text-end">Issued qty</th><th class="text-end">Issued value</th><th class="text-end">In stock value</th></tr></thead>
                    <tbody>
                    @foreach($data['materials'] as $r)
                        <tr>
                            <td class="fw-semibold">{{ $r['name'] }}@if($r['unpriced'])<span class="badge bg-warning text-dark ms-1">No price</span>@endif @if($r['issued_without_receipt'])<span class="badge bg-warning text-dark ms-1">Issued, not received</span>@endif</td>
                            <td>{{ $r['uom'] ?: '—' }}</td>
                            <td class="text-end">{{ $q($r['received_qty']) }}</td><td class="text-end">{{ $m2($r['received_value']) }}</td>
                            <td class="text-end">{{ $q($r['issued_qty']) }}</td><td class="text-end">{{ $m2($r['issued_value']) }}</td>
                            <td class="text-end {{ $r['in_stock_value'] > 0.005 ? 'text-warning-emphasis' : '' }}">{{ $m2($r['in_stock_value']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="3">Total</td><td class="text-end">{{ $m2($data['received_value']) }}</td><td></td><td class="text-end">{{ $m2($data['issued_value']) }}</td><td class="text-end">{{ $m2($data['in_stock_value']) }}</td></tr></tfoot>
                </table>
            </div>
        </x-card>

        <p class="small text-muted mb-0">
            Budget = the material cost of the latest final buyer costing{{ $data['costing'] ? ' (revision ' . $data['costing']->revision . ', ' . $m2($data['per_piece_budget']) . ' ' . $cur . ' per piece)' : '' }} x the order quantity.
            Received value = physical quantity x the unit price Store booked on each GRN. Issued value = bulk issue quantity x the average price that material was received at. Prices carry no currency here and are taken to be in the costing's currency.
            <strong>Not included yet:</strong> CM, overhead, commission, claims and the quantity actually shipped are not recorded in the system, so the real profit of the order cannot be worked out.
        </p>
    @endif
</div>
@endsection
