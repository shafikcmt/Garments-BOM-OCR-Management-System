{{--
    Area chart with a gradient fill, drawn as inline SVG.

    Props:
      series  [['label' => 'Feb', 'value' => 3], …] in chronological order
      tone    primary | success | warning | danger
      height  px height of the plot

    Hovering a point shows its value via a native <title>, which also makes it
    available to assistive tech. A data table equivalent is rendered
    visually-hidden below, so the series is readable without seeing the chart.
--}}
@props(['series' => [], 'tone' => 'primary', 'height' => 180, 'label' => 'Trend'])

@php
    $series = array_values($series);
    $count = count($series);
    $values = array_map(fn ($p) => (float) ($p['value'] ?? 0), $series);

    $w = 600;
    $h = (int) $height;
    $padY = 12;

    $max = $values ? max($values) : 0;
    $scaleMax = $max > 0 ? $max : 1;

    $pts = [];
    foreach ($values as $i => $v) {
        $x = $count > 1 ? ($i / ($count - 1)) * $w : $w / 2;
        $y = $h - $padY - ($v / $scaleMax) * ($h - ($padY * 2));
        $pts[] = ['x' => round($x, 2), 'y' => round($y, 2), 'v' => $v, 'label' => $series[$i]['label'] ?? ''];
    }

    $line = implode(' ', array_map(fn ($p) => $p['x'].','.$p['y'], $pts));
    $area = $pts ? "0,{$h} ".$line.' '.end($pts)['x'].",{$h}" : '';
    $gradId = 'gxArea'.substr(md5($label.$tone), 0, 6);
@endphp

<div {{ $attributes->merge(['class' => 'gx-area gx-tone-'.$tone]) }}>
    @if($count > 0)
        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none"
             role="img" aria-label="{{ $label }}" class="gx-area-svg">
            <defs>
                <linearGradient id="{{ $gradId }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" class="gx-area-stop-a" />
                    <stop offset="100%" class="gx-area-stop-b" />
                </linearGradient>
            </defs>

            <polygon class="gx-area-fill" points="{{ $area }}" fill="url(#{{ $gradId }})" />
            <polyline class="gx-area-line" points="{{ $line }}" />

            @foreach($pts as $p)
                <circle class="gx-area-dot" cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="4">
                    <title>{{ $p['label'] }}: {{ number_format($p['v']) }}</title>
                </circle>
            @endforeach
        </svg>

        <div class="gx-area-axis" aria-hidden="true">
            @foreach($pts as $p)
                <span>{{ $p['label'] }}</span>
            @endforeach
        </div>

        {{-- The same series as text, for anyone not reading the graphic. --}}
        <table class="visually-hidden">
            <caption>{{ $label }}</caption>
            <tbody>
                @foreach($pts as $p)
                    <tr><th scope="row">{{ $p['label'] }}</th><td>{{ number_format($p['v']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="text-muted small mb-0">No data for this period yet.</p>
    @endif
</div>
