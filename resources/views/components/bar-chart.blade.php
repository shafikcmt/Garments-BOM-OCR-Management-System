{{--
    Column chart, drawn with plain HTML and inline sizes: no charting library and
    no extra CSS.

    Props:
      series  [['label' => 'Overdue', 'value' => 1200, 'tone' => 'danger'], …]
              (tone is optional per bar: primary | success | warning | danger | secondary)
      height  px height of the plot
      label   what the chart shows (read by assistive tech)
      decimals  decimals shown in the figures

    The same numbers are written above each bar and in a visually-hidden table.
--}}
@props(['series' => [], 'height' => 170, 'label' => 'Chart', 'decimals' => 0, 'tone' => 'primary'])

@php
    $series = array_values($series);
    $max = max(array_merge([0], array_map(fn ($s) => (float) ($s['value'] ?? 0), $series)));
    $fmt = fn ($n) => number_format((float) $n, (int) $decimals);
    $total = array_sum(array_map(fn ($s) => (float) ($s['value'] ?? 0), $series));
@endphp
<div {{ $attributes }}>
    @if($total > 0)
        <div class="d-flex align-items-end gap-2" style="height: {{ (int) $height }}px" role="img" aria-label="{{ $label }}">
            @foreach($series as $s)
                @php
                    $v = (float) ($s['value'] ?? 0);
                    $pct = $max > 0 ? max($v > 0 ? 3 : 0, round($v / $max * 100)) : 0;
                    $t = $s['tone'] ?? $tone;
                @endphp
                <div class="flex-fill d-flex flex-column justify-content-end align-items-center h-100" style="min-width:0" title="{{ $s['label'] }}: {{ $fmt($v) }}">
                    <div class="small fw-semibold text-nowrap" style="font-size:.72rem">{{ $v > 0 ? $fmt($v) : '' }}</div>
                    <div class="w-100 rounded-top bg-{{ $t }}" style="height: {{ $pct }}%; min-height: {{ $v > 0 ? 3 : 0 }}px; opacity:.9"></div>
                </div>
            @endforeach
        </div>
        <div class="d-flex gap-2 mt-1" aria-hidden="true">
            @foreach($series as $s)<div class="flex-fill text-center text-muted text-truncate" style="min-width:0; font-size:.72rem">{{ $s['label'] }}</div>@endforeach
        </div>
        <table class="visually-hidden">
            <caption>{{ $label }}</caption>
            <tbody>@foreach($series as $s)<tr><th scope="row">{{ $s['label'] }}</th><td>{{ $fmt($s['value'] ?? 0) }}</td></tr>@endforeach</tbody>
        </table>
    @else
        <p class="text-muted small mb-0">Nothing to show yet.</p>
    @endif
</div>
