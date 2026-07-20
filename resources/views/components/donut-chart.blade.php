{{--
    Donut chart, drawn as inline SVG — no charting library needed.

    Props:
      segments  [['label' => 'Approved', 'value' => 2, 'tone' => 'success'], …]
      total     centre figure; defaults to the sum of the segments
      caption   small text under the centre figure

    The chart itself is aria-hidden and the same numbers are listed in the
    legend beside it, so a screen reader gets the data as text rather than as
    an unreadable graphic.
--}}
@props(['segments' => [], 'total' => null, 'caption' => 'Total'])

@php
    $segments = array_values(array_filter($segments, fn ($s) => (float) ($s['value'] ?? 0) > 0));
    $sum = array_sum(array_map(fn ($s) => (float) $s['value'], $segments));
    $total = $total ?? $sum;

    // Circumference of r=54 — segment lengths are expressed against this.
    $r = 54;
    $circumference = 2 * M_PI * $r;
    $offset = 0;

    $slices = [];
    foreach ($segments as $s) {
        $value = (float) $s['value'];
        $share = $sum > 0 ? $value / $sum : 0;
        $slices[] = [
            'label' => $s['label'] ?? '',
            'value' => $value,
            'tone' => $s['tone'] ?? 'primary',
            'pct' => $sum > 0 ? round($share * 100, 1) : 0,
            'dash' => round($share * $circumference, 3),
            'gap' => round($circumference - ($share * $circumference), 3),
            'offset' => round(-$offset, 3),
        ];
        $offset += $share * $circumference;
    }
@endphp

<div {{ $attributes->merge(['class' => 'gx-donut-wrap']) }}>
    <div class="gx-donut">
        @if($sum > 0)
            <svg viewBox="0 0 140 140" aria-hidden="true" focusable="false">
                <circle class="gx-donut-track" cx="70" cy="70" r="{{ $r }}" />
                @foreach($slices as $i => $slice)
                    <circle class="gx-donut-slice gx-tone-{{ $slice['tone'] }}"
                            cx="70" cy="70" r="{{ $r }}"
                            stroke-dasharray="{{ $slice['dash'] }} {{ $slice['gap'] }}"
                            stroke-dashoffset="{{ $slice['offset'] }}"
                            style="--gx-delay: {{ $i * 120 }}ms" />
                @endforeach
            </svg>
        @else
            <svg viewBox="0 0 140 140" aria-hidden="true" focusable="false">
                <circle class="gx-donut-track" cx="70" cy="70" r="{{ $r }}" />
            </svg>
        @endif

        <div class="gx-donut-centre">
            <span class="gx-donut-total" @if(is_numeric($total)) data-count-to="{{ $total }}" @endif>{{ number_format((float) $total) }}</span>
            <span class="gx-donut-caption">{{ $caption }}</span>
        </div>
    </div>

    <ul class="gx-legend">
        @forelse($slices as $slice)
            <li>
                <span class="gx-legend-dot gx-tone-{{ $slice['tone'] }}" aria-hidden="true"></span>
                <span class="gx-legend-label">{{ $slice['label'] }}</span>
                <span class="gx-legend-value">{{ number_format($slice['value']) }}</span>
                <span class="gx-legend-pct">{{ $slice['pct'] }}%</span>
            </li>
        @empty
            <li class="text-muted">No data yet.</li>
        @endforelse
    </ul>
</div>
