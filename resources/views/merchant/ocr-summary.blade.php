@extends('layouts.app')

@section('title', 'OCR Summary')

@section('content')
@php
    $num = fn ($n, $d = 0) => $n === null ? '—' : number_format((float) $n, $d);
    $date = fn ($d) => $d ? $d->format('d M Y') : '—';
    $query = fn (array $extra = []) => array_filter(array_merge(['buyer' => $buyer?->id, 'season' => $season?->id, 'tab' => $tab], $extra));
    $counts = ['contract' => $contract->count(), 'material' => $material->count(), 'payment' => $payment->count(), 'clearance' => $clearance->count()];
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'OCR Summary']]" />

    <x-page-header icon="table" eyebrow="Reporting" title="OCR Summary"
                   copy="Contract, material, payment and clearance summaries made from the workspace. No hand-made pivots.">
        <x-slot:actions>
            <a href="{{ route('merchant.ocr.excel', $query()) }}" class="btn btn-success">
                <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Download OCR Excel
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label mb-1" for="ocrBuyer">Buyer</label>
                <select name="buyer" id="ocrBuyer" class="form-select" onchange="this.form.season && (this.form.season.value=''); this.form.submit()">
                    <option value="">All buyers</option>
                    @foreach($buyers as $b)
                        <option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label mb-1" for="ocrSeason">Season</label>
                <select name="season" id="ocrSeason" class="form-select" {{ $buyer ? '' : 'disabled' }} onchange="this.form.submit()">
                    <option value="">{{ $buyer ? 'All seasons' : 'Choose a buyer first' }}</option>
                    @foreach($seasons as $s)
                        <option value="{{ $s->id }}" @selected($season?->id === $s->id)>{{ $s->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <a href="{{ route('merchant.ocr.index', ['tab' => $tab]) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
            </div>
            <div class="col text-muted small text-md-end">
                Excel: a Database sheet{{ $buyer ? ' (in ' . $buyer->buyer_name . "'s column layout if a column matching is saved)" : '' }} plus the four summary sheets.
            </div>
        </form>
    </x-card>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-stat-card icon="receipt" tone="primary" label="Order lines" :value="$kpis['order_lines']" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="box-seam" tone="primary" label="Order qty" :value="number_format($kpis['order_qty'])" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="cart-check" tone="primary" :label="'Ordered · ' . ($kpis['materials'] ? round($kpis['ordered'] / $kpis['materials'] * 100) : 0) . '%'" :value="$kpis['ordered'] . ' / ' . $kpis['materials']" /></div>
        <div class="col-6 col-md-3"><x-stat-card icon="truck" tone="success" :label="'Received · ' . ($kpis['materials'] ? round($kpis['received'] / $kpis['materials'] * 100) : 0) . '%'" :value="$kpis['received'] . ' / ' . $kpis['materials']" /></div>
    </div>

    <ul class="nav nav-tabs mb-3">
        @foreach($tabs as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('merchant.ocr.index', $query(['tab' => $key])) }}">
                    {{ $label }} <span class="badge bg-light text-muted border ms-1">{{ $counts[$key] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <x-card body-class="p-3">
        <div class="table-responsive">
        @if($tab === 'contract')
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>Buyer</th><th>Season</th><th>Style</th><th>GMTS Color</th><th>Contract / PO</th><th>Ship Date</th>
                    <th class="text-end">Order Qty</th><th class="text-end">Materials</th>
                    <th class="text-end">Ordered</th><th class="text-end">PI</th><th class="text-end">Paid</th><th class="text-end">Received</th><th class="text-end">Late</th>
                </tr></thead>
                <tbody>
                @forelse($contract as $r)
                    <tr>
                        <td>{{ $r['buyer'] ?? '—' }}</td><td>{{ $r['season'] ?? '—' }}</td>
                        <td class="fw-semibold">{{ $r['style'] }}@if($r['child'])<span class="badge bg-light text-dark border ms-1">{{ $r['child'] }}</span>@endif</td>
                        <td>{{ $r['color'] ?: '—' }}</td><td>{{ $r['contract'] ?: '—' }}</td><td class="text-nowrap">{{ $date($r['ship_date']) }}</td>
                        <td class="text-end">{{ $num($r['order_qty']) }}</td><td class="text-end">{{ $r['materials'] }}</td>
                        <td class="text-end">{{ $r['ordered_pct'] }}%</td><td class="text-end">{{ $r['pi_pct'] }}%</td><td class="text-end">{{ $r['paid_pct'] }}%</td><td class="text-end">{{ $r['received_pct'] }}%</td>
                        <td class="text-end">@if($r['late'] > 0)<span class="badge bg-danger text-white">{{ $r['late'] }}</span>@else — @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="text-center text-muted py-4">No orders for this choice.</td></tr>
                @endforelse
                </tbody>
            </table>
        @elseif($tab === 'material')
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>Type</th><th>Material</th><th>Material Color</th><th>Unit</th><th class="text-end">Lines</th>
                    <th class="text-end">BOM Qty</th><th class="text-end">Ordered Qty</th><th class="text-end">(Short) / Excess</th><th class="text-end">Received Qty</th><th class="text-end">Received</th><th>Vendors</th>
                </tr></thead>
                <tbody>
                @forelse($material as $r)
                    <tr>
                        <td>{{ $r['type'] ?: '—' }}</td>
                        <td class="fw-semibold">{{ $r['description'] ?: '—' }}@if($r['sap_code'])<div class="small text-muted">{{ $r['sap_code'] }}</div>@endif</td>
                        <td>{{ $r['material_color'] ?: '—' }}</td><td>{{ $r['uom'] ?: '—' }}</td>
                        <td class="text-end">{{ $r['lines'] }}@if($r['not_ordered'] > 0)<div class="small text-danger">{{ $r['not_ordered'] }} not ordered</div>@endif</td>
                        <td class="text-end">{{ $num($r['bom_qty'], 2) }}</td><td class="text-end">{{ $num($r['ordered_qty'], 2) }}</td>
                        <td class="text-end {{ ($r['short_excess'] ?? 0) < 0 ? 'text-danger' : '' }}">{{ $r['short_excess'] === null ? '—' : ($r['short_excess'] < 0 ? '(' . $num(abs($r['short_excess']), 2) . ')' : $num($r['short_excess'], 2)) }}</td>
                        <td class="text-end">{{ $num($r['received_qty'], 2) }}</td><td class="text-end">{{ $r['received_pct'] }}%</td>
                        <td class="small">{{ $r['vendors'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">No materials for this choice.</td></tr>
                @endforelse
                </tbody>
            </table>
        @elseif($tab === 'payment')
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>Supplier</th><th class="text-end">POs</th><th class="text-end">PIs</th><th class="text-end">Lines Without PI</th>
                    <th class="text-end">PI Amount</th><th class="text-end">Paid</th><th class="text-end">Pending</th><th class="text-end">Unpaid Lines</th><th>Next Payment Due</th>
                </tr></thead>
                <tbody>
                @forelse($payment as $r)
                    <tr>
                        <td class="fw-semibold">{{ $r['vendor'] }}</td>
                        <td class="text-end">{{ $r['pos'] }}</td><td class="text-end">{{ $r['pis'] }}</td><td class="text-end">{{ $r['pi_pending'] }}</td>
                        <td class="text-end">{{ $num($r['pi_amount'], 2) }}</td><td class="text-end">{{ $num($r['paid_amount'], 2) }}</td>
                        <td class="text-end fw-semibold {{ $r['pending_amount'] > 0 ? 'text-danger' : '' }}">{{ $num($r['pending_amount'], 2) }}</td>
                        <td class="text-end">{{ $r['unpaid_lines'] }}</td>
                        <td class="text-nowrap {{ $r['next_due'] && $r['next_due']->isPast() ? 'text-danger fw-semibold' : '' }}">{{ $date($r['next_due']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No material is ordered yet for this choice.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="small text-muted mt-2 mb-0">A PI repeated on several lines is counted once. Only ordered material is listed.</p>
        @else
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>BL / AWB</th><th>Ship Mode</th><th>Forwarder</th><th>Suppliers</th><th class="text-end">POs</th><th class="text-end">Lines</th>
                    <th>ETA</th><th>Committed In-house</th><th>Arrival Status</th>
                </tr></thead>
                <tbody>
                @forelse($clearance as $r)
                    <tr>
                        <td class="fw-semibold">{{ $r['bl'] }}</td><td>{{ $r['ship_mode'] ?: '—' }}</td><td>{{ $r['forwarder'] ?: '—' }}</td>
                        <td class="small">{{ $r['vendors'] ?: '—' }}</td><td class="text-end">{{ $r['pos'] }}</td><td class="text-end">{{ $r['lines'] }}</td>
                        <td class="text-nowrap">{{ $date($r['eta']) }}</td><td class="text-nowrap">{{ $date($r['committed_inhouse']) }}</td><td>{{ $r['arrival_status'] ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Nothing in transit: every ordered material has been received.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="small text-muted mt-2 mb-0">Ordered material not yet received in store, by BL / AWB. ETA is the actual ETA, else the committed ETA.</p>
        @endif
        </div>
    </x-card>
</div>
@endsection
