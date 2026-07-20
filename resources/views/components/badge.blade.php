{{--
    Status pill.

    Tones map onto the palette tokens, so a badge stays in step with the theme
    rather than carrying its own colour.

    Props:
      tone       primary | success | warning | danger | neutral
      size       sm | md
      icon       bootstrap-icon name without the "bi-" prefix
      solid      filled instead of the softer subtle background
      removable  renders an X; the caller listens for the click

    Usage:  x-badge tone="success" icon="check2">Approved</x-badge
--}}
@props([
    'tone' => 'neutral',
    'size' => 'md',
    'icon' => null,
    'solid' => false,
    'removable' => false,
])

@php
    $tones = [
        'primary' => ['subtle' => 'bg-primary-subtle text-primary', 'solid' => 'text-bg-primary'],
        'success' => ['subtle' => 'bg-success-subtle text-success', 'solid' => 'text-bg-success'],
        'warning' => ['subtle' => 'bg-warning-subtle text-warning-emphasis', 'solid' => 'text-bg-warning'],
        'danger' => ['subtle' => 'bg-danger-subtle text-danger', 'solid' => 'text-bg-danger'],
        'info' => ['subtle' => 'bg-info-subtle text-info', 'solid' => 'text-bg-info'],
        'neutral' => ['subtle' => 'bg-secondary-subtle text-secondary', 'solid' => 'text-bg-secondary'],
    ];

    $classes = ($tones[$tone] ?? $tones['neutral'])[$solid ? 'solid' : 'subtle'];
@endphp

<span {{ $attributes->merge([
    'class' => 'badge gx-badge gx-badge--'.$size.' '.$classes,
]) }}>
    @if($icon)
        <i class="bi bi-{{ $icon }}" aria-hidden="true"></i>
    @endif

    {{ $slot }}

    @if($removable)
        <button type="button" class="gx-badge-remove" aria-label="Remove">&times;</button>
    @endif
</span>
