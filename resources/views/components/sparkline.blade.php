{{--
    Tiny trend line, drawn as inline SVG so it needs no charting library.
    Decorative: aria-hidden, because the number it accompanies already carries
    the meaning for a screen reader.
--}}
@props(['points' => [], 'tone' => 'primary'])

@php
    $values = array_values(array_map('floatval', $points));
    $count = count($values);
    $w = 100;
    $h = 28;

    $min = $count ? min($values) : 0;
    $max = $count ? max($values) : 0;
    $range = ($max - $min) ?: 1;

    $coords = [];
    foreach ($values as $i => $v) {
        $x = $count > 1 ? ($i / ($count - 1)) * $w : 0;
        $y = $h - (($v - $min) / $range) * ($h - 4) - 2;
        $coords[] = round($x, 2).','.round($y, 2);
    }

    $line = implode(' ', $coords);
    $area = $line !== '' ? "0,{$h} ".$line." {$w},{$h}" : '';
@endphp

@if($count > 1)
    <svg class="gx-spark gx-spark--{{ $tone }}" viewBox="0 0 {{ $w }} {{ $h }}"
         preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <polygon class="gx-spark-fill" points="{{ $area }}" />
        <polyline class="gx-spark-line" points="{{ $line }}" />
    </svg>
@endif
