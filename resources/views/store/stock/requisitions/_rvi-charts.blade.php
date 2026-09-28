{{--
    Requirement vs Issue charts — screen only, drawn as inline SVG and HTML
    like the dashboard charts (no charting library). Every figure comes from
    RequirementVsIssueCharts::issue(), built from the table's own rows.

    Expects:
      $charts      RequirementVsIssueCharts::issue()
      $sectionUrl  fn (int $sectionId): string — the page with that Section filter
      $itemUrl     fn (string $itemName): string — the page searching that item
--}}
@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
    $statusColor = [
        'fully_issued' => 'var(--bs-success)',
        'partial' => 'var(--bs-warning)',
        'not_issued' => 'var(--bs-secondary)',
        'over_issued' => 'var(--bs-danger)',
        'not_requested' => 'var(--bs-info)',
    ];
    $totalRows = array_sum(array_column($charts['statuses'], 'count'));

    // A half-ring gauge: 0% at the left, 100% at the right. Over 100% is
    // drawn full; the figure underneath says the real value.
    $pct = $charts['pct'];
    $arc = min(100, max(0, (float) ($pct ?? 0)));
    $radius = 52;
    $length = M_PI * $radius;
@endphp

<div class="row g-3 mb-3 rvi-charts">
    @if($charts['empty'])
        <div class="col-12">
            <div class="rvi-chart-card text-center text-muted py-4">No data for these filters</div>
        </div>
    @else
        {{-- a) Issued % and the status split --}}
        <div class="col-12 col-lg-4">
            <div class="rvi-chart-card h-100">
                <div class="rvi-chart-title">Issued % (by value)</div>
                @if($pct === null)
                    <div class="text-center text-muted py-4">No data for these filters</div>
                @else
                    <div class="text-center">
                        <svg viewBox="0 0 140 80" class="rvi-gauge" role="img" aria-label="Issued {{ number_format($pct, 1) }} percent by value">
                            <path d="M 18 72 A {{ $radius }} {{ $radius }} 0 0 1 122 72" class="rvi-gauge-track" />
                            <path d="M 18 72 A {{ $radius }} {{ $radius }} 0 0 1 122 72" class="rvi-gauge-fill"
                                  stroke-dasharray="{{ round($length * $arc / 100, 2) }} {{ round($length, 2) }}" />
                        </svg>
                        <div class="rvi-gauge-value">{{ number_format($pct, 1) }}%</div>
                    </div>
                @endif

                <div class="rvi-stack mt-3" role="img" aria-label="Rows by status">
                    @foreach($charts['statuses'] as $s)
                        @if($s['count'] > 0)
                            <span class="rvi-stack-part" style="width: {{ $totalRows ? $s['count'] / $totalRows * 100 : 0 }}%; background: {{ $statusColor[$s['key']] }};"
                                  title="{{ $s['label'] }}: {{ $s['count'] }}"></span>
                        @endif
                    @endforeach
                </div>
                <ul class="rvi-legend">
                    @foreach($charts['statuses'] as $s)
                        <li><span class="rvi-dot" style="background: {{ $statusColor[$s['key']] }};"></span>{{ $s['label'] }} <strong>{{ number_format($s['count']) }}</strong></li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- b) Requested vs Issued value by section --}}
        <div class="col-12 col-lg-4">
            <div class="rvi-chart-card h-100">
                <div class="rvi-chart-title">Requested vs Issued value by Section</div>
                <div class="rvi-key"><span class="rvi-dot rvi-requested"></span>Requested <span class="rvi-dot rvi-issued ms-2"></span>Issued</div>
                @forelse($charts['sections'] as $s)
                    @php($clickable = ! empty($s['section_id']) && empty($s['others']))
                    <{{ $clickable ? 'a' : 'div' }} @if($clickable) href="{{ $sectionUrl((int) $s['section_id']) }}" title="Show only {{ $s['label'] }}" @endif class="rvi-bar-row">
                        <span class="rvi-bar-label">{{ $s['label'] }}</span>
                        <span class="rvi-bar-track">
                            <span class="rvi-bar rvi-requested" style="width: {{ $charts['section_max'] > 0 ? $s['requested'] / $charts['section_max'] * 100 : 0 }}%"></span>
                            <span class="rvi-bar rvi-issued" style="width: {{ $charts['section_max'] > 0 ? $s['issued'] / $charts['section_max'] * 100 : 0 }}%"></span>
                        </span>
                        <span class="rvi-bar-value">{{ $money($s['requested']) }}<br><small>{{ $money($s['issued']) }}</small></span>
                    </{{ $clickable ? 'a' : 'div' }}>
                @empty
                    <div class="text-center text-muted py-4">No data for these filters</div>
                @endforelse
            </div>
        </div>

        {{-- c) Top items by balance value --}}
        <div class="col-12 col-lg-4">
            <div class="rvi-chart-card h-100">
                <div class="rvi-chart-title">Top 10 items by Balance Value</div>
                @forelse($charts['items'] as $item)
                    <a href="{{ $itemUrl($item['label']) }}" class="rvi-bar-row" title="Search {{ $item['label'] }}">
                        <span class="rvi-bar-label">{{ $item['label'] }}<small class="d-block text-muted">{{ $item['section'] }}</small></span>
                        <span class="rvi-bar-track">
                            <span class="rvi-bar rvi-balance" style="width: {{ $charts['item_max'] > 0 ? $item['value'] / $charts['item_max'] * 100 : 0 }}%"></span>
                        </span>
                        <span class="rvi-bar-value">{{ $money($item['value']) }}</span>
                    </a>
                @empty
                    <div class="text-center text-muted py-4">No data for these filters</div>
                @endforelse
            </div>
        </div>
    @endif
</div>

@include('store.stock.requisitions._rvi-chart-styles')
