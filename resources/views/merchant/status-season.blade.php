@extends('layouts.app')

@section('title', 'Season ' . $season->code)

@section('content')
@php($pct = fn ($n, $of) => $of > 0 ? round($n / $of * 100) : 0)
@php($isMerchant = auth()->user()->hasRole('merchant'))
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => $isMerchant ? route('merchant.dashboard') : route('merchant.status.index')],
        ['label' => 'Style Status', 'url' => route('merchant.status.index', ['buyer' => $season->buyer_id])],
        ['label' => $season->buyer?->buyer_name . ' ' . $season->code],
    ]" />

    <x-page-header data-aos="fade-down" icon="bar-chart-steps" :eyebrow="$season->buyer?->buyer_name"
                   :title="'Season ' . $season->code"
                   copy="Every style of this season and how far its materials have come. Styles that need attention come first.">
        <x-slot:actions>
            <a href="{{ route('merchant.status.index', ['buyer' => $season->buyer_id]) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style Status
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @if($totals['materials'] === 0)
        <x-card>
            <p class="mb-1 fw-semibold">No orders are linked to this season yet.</p>
            <p class="text-muted mb-0">Import the buyer's Excel with the season <strong>{{ $season->code }}</strong> (Merchant Workspace → Import Buyer Excel).</p>
        </x-card>
    @else
        @if($totals['late'] > 0 || $totals['due_soon'] > 0)
            <div class="alert {{ $totals['late'] > 0 ? 'alert-danger' : 'alert-warning' }} d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div>
                    @if($totals['late'] > 0)<strong>{{ $totals['late'] }}</strong> material(s) not ordered and past the date they had to be ordered by.@endif
                    @if($totals['due_soon'] > 0) <strong>{{ $totals['due_soon'] }}</strong> due to be ordered within {{ \App\Services\StyleRollup::DUE_SOON_DAYS }} days.@endif
                </div>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="tag" tone="primary" label="Styles" :value="$styles->count()" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="box-seam" tone="primary" label="Order qty" :value="number_format($totals['order_qty'])" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="list-ul" tone="primary" label="Materials" :value="$totals['materials']" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="cart-check" tone="primary" :label="'Ordered · ' . $pct($totals['ordered'], $totals['materials']) . '%'" :value="$totals['ordered']" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="truck" tone="success" :label="'Received · ' . $pct($totals['received'], $totals['materials']) . '%'" :value="$totals['received']" /></div>
            <div class="col-6 col-md-3 col-xl"><x-stat-card icon="exclamation-triangle" tone="{{ $totals['late'] > 0 ? 'danger' : 'success' }}" label="Late" :value="$totals['late']" /></div>
        </div>
    @endif

    <x-card title="Styles" body-class="p-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>Style</th><th class="text-end">Order qty</th>
                    <th class="text-end">Materials</th>
                    <th style="min-width:92px">Ordered</th><th style="min-width:92px">PI received</th><th style="min-width:92px">Paid</th>
                    <th style="min-width:92px">Received</th><th style="min-width:92px">Issued</th><th>BOM</th><th class="text-end">Margin</th><th>Attention</th><th>Follow-up</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($styles as $row)
                    @php($m = $row['mother'])
                    @php($k = $row['kpis'])
                    @php($route = $isMerchant ? 'merchant.styles.mothers.show' : 'merchant.status.style')
                    <tr>
                        <td class="text-nowrap">
                            <a href="{{ route($route, $m) }}" class="fw-semibold text-decoration-none">{{ $m->style_no }}</a>
                            @foreach($m->children as $c)<span class="badge bg-light text-dark border ms-1">{{ $c->style_no }}</span>@endforeach
                            @if($m->product_group)<div class="small text-muted">{{ $m->product_group }}</div>@endif
                        </td>
                        <td class="text-end text-nowrap">{{ $k['order_qty'] ? number_format($k['order_qty']) : '—' }}
                            <div class="small text-muted">{{ $k['order_lines'] }} {{ \Illuminate\Support\Str::plural('line', $k['order_lines']) }}</div>
                        </td>
                        <td class="text-end">{{ $k['materials'] }}</td>
                        @if($k['materials'] > 0)
                            <td>@include('merchant._progress', ['value' => $pct($k['ordered'], $k['materials'])])</td>
                            <td>@include('merchant._progress', ['value' => $pct($k['pi_received'], $k['materials'])])</td>
                            <td>@include('merchant._progress', ['value' => $pct($k['paid'], $k['materials'])])</td>
                            <td>@include('merchant._progress', ['value' => $pct($k['received'], $k['materials']), 'tone' => 'success'])</td>
                            <td>@include('merchant._progress', ['value' => $pct($k['issued'], $k['materials']), 'tone' => 'success'])</td>
                        @else
                            <td colspan="5" class="text-muted">No orders linked yet</td>
                        @endif
                        <td>@include('bom-approvals._badge', ['state' => $bomStates[$m->id]['state'], 'approval' => $bomStates[$m->id]['approval']])</td>
                        <td class="text-end">{{ isset($margins[$m->id]) && $margins[$m->id] !== null ? $margins[$m->id] . '%' : '—' }}</td>
                        <td class="text-nowrap">
                            <div class="d-flex flex-column align-items-start gap-1">
                            @if($k['late'] > 0)<span class="badge bg-danger text-white">{{ $k['late'] }} late</span>@endif
                            @if($k['due_soon'] > 0)<span class="badge bg-warning text-dark">{{ $k['due_soon'] }} due soon</span>@endif
                            @if($k['late'] === 0 && $k['due_soon'] === 0)—@endif
                            </div>
                        </td>
                        <td>@if(($openFollowUps[$m->id] ?? 0) > 0)<span class="badge bg-primary-subtle text-primary">{{ $openFollowUps[$m->id] }} open</span>@else — @endif</td>
                        <td class="text-end"><a href="{{ route($route, $m) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>View Status</a></td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="text-center text-muted py-4">No styles in this season yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
