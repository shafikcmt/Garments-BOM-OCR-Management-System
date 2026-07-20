{{--
    Standard content card.

    Replaces the `card border-0 shadow-sm` + `border-radius` + `card-body p-4`
    combination repeated ~84 times, and moves the radius off an inline style
    onto the .gx-card class so it follows the --gx-radius token.

    Usage:
        x-card title="Record Receiving"           - simple titled card
        x-card class="mb-4" body-class="p-0"      - flush card, e.g. a table
        x-slot:title / x-slot:actions             - rich title, header buttons

    Extra classes (mb-4, h-100, flex-fill) pass straight through via the usual
    attribute merge. `title` takes a string or a slot, so a title carrying a
    badge still renders.

    Note: tag syntax is written plainly above on purpose — a nested Blade
    comment would close this one early and the example would compile as real
    markup.
--}}
@props([
    'title' => null,
    'bodyClass' => 'p-4',
])

<div {{ $attributes->merge(['class' => 'card gx-card border-0 shadow-sm']) }}>
    <div class="card-body {{ $bodyClass }}">
        @if($title || isset($actions))
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                @if($title)
                    <h5 class="mb-0">{{ $title }}</h5>
                @endif
                @isset($actions)
                    <div class="d-flex flex-wrap gap-2">{{ $actions }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </div>
</div>
