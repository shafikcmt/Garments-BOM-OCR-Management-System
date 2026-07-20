{{--
    KPI tile: icon, value, label, and an optional trend and sparkline.

    Props:
      icon      bootstrap-icon name, without the "bi-" prefix
      label     what the number means
      value     the number itself
      tone      primary | success | warning | danger  (drives icon + trend colour)
      delta     signed percentage change, e.g. 12 or -4. Omit when there is
                nothing real to compare against — an invented trend on a
                management screen is worse than no trend.
      deltaLabel  what the change is measured against ("vs last month")
      spark     array of numbers for the sparkline; omit to hide it
      href      makes the whole tile a link
--}}
@props([
    'icon' => 'graph-up',
    'label' => '',
    'value' => 0,
    'tone' => 'primary',
    'delta' => null,
    'deltaLabel' => null,
    'spark' => [],
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';
    $isUp = $delta !== null && (float) $delta >= 0;
    $numeric = is_numeric($value);
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'gx-stat gx-stat--'.$tone]) }}>

    <div class="gx-stat-head">
        <span class="gx-stat-icon" aria-hidden="true"><i class="bi bi-{{ $icon }}"></i></span>

        @if($delta !== null)
            <span class="gx-stat-delta {{ $isUp ? 'is-up' : 'is-down' }}">
                <i class="bi bi-arrow-{{ $isUp ? 'up' : 'down' }}-short" aria-hidden="true"></i>{{ abs((float) $delta) }}%
            </span>
        @endif
    </div>

    {{-- data-count-to drives the count-up; the text is already the final value
         so it stays correct with JS off or reduced motion on. --}}
    <div class="gx-stat-value" @if($numeric) data-count-to="{{ $value }}" @endif>
        {{ $numeric ? number_format((float) $value) : $value }}
    </div>

    <div class="gx-stat-label">{{ $label }}</div>

    @if($delta !== null && $deltaLabel)
        <div class="gx-stat-sub">{{ $deltaLabel }}</div>
    @endif

    @if(count($spark) > 1)
        <x-sparkline :points="$spark" :tone="$tone" />
    @endif
</{{ $tag }}>
