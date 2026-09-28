{{--
    Stock Summary, Purchase View — one small chart: Requested vs Purchased vs
    To Purchase for the ten items with the most still to purchase. Screen
    only, inline HTML bars like the other charts.

    Expects: $chart  RequirementVsIssueCharts::purchase()
--}}
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.') ?: '0';
@endphp

<div class="rvi-chart-card mb-3 rvi-charts">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="rvi-chart-title mb-0">Top 10 items to purchase</div>
        <div class="rvi-key mb-0">
            <span class="rvi-dot rvi-requested"></span>Requested
            <span class="rvi-dot rvi-issued ms-2"></span>Purchased
            <span class="rvi-dot rvi-pending ms-2"></span>To Purchase
        </div>
    </div>
    @if($chart['empty'])
        <div class="text-center text-muted py-3">No data for these filters</div>
    @else
        <div class="row g-2 mt-1">
            @foreach($chart['items'] as $item)
                <div class="col-12 col-lg-6">
                    <div class="rvi-bar-row">
                        <span class="rvi-bar-label">{{ $item['label'] }}</span>
                        <span class="rvi-bar-track">
                            <span class="rvi-bar rvi-requested" style="width: {{ $chart['max'] > 0 ? $item['requested'] / $chart['max'] * 100 : 0 }}%"></span>
                            <span class="rvi-bar rvi-issued" style="width: {{ $chart['max'] > 0 ? $item['purchased'] / $chart['max'] * 100 : 0 }}%"></span>
                            <span class="rvi-bar rvi-pending" style="width: {{ $chart['max'] > 0 ? $item['pending'] / $chart['max'] * 100 : 0 }}%"></span>
                        </span>
                        <span class="rvi-bar-value">{{ $qty($item['pending']) }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@include('store.stock.requisitions._rvi-chart-styles')
