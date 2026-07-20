{{--
    Large action tile for the dashboard's shortcut grid.
    Renders as a link, so it is keyboard-reachable and gets the shared focus
    ring for free.
--}}
@props(['icon' => 'arrow-right', 'title' => '', 'description' => null, 'tone' => 'primary', 'href' => '#'])

<a href="{{ $href }}" {{ $attributes->merge(['class' => 'gx-action gx-tone-'.$tone]) }}>
    <span class="gx-action-icon" aria-hidden="true"><i class="bi bi-{{ $icon }}"></i></span>
    <span class="gx-action-body">
        <span class="gx-action-title">{{ $title }}</span>
        @if($description)
            <span class="gx-action-desc">{{ $description }}</span>
        @endif
    </span>
    <i class="bi bi-arrow-right gx-action-arrow" aria-hidden="true"></i>
</a>
