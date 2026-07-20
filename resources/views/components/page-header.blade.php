{{--
    Page hero — the icon / eyebrow / title block that opens every screen.

    Replaces the app-hero-card markup repeated across the views, including the
    icon sizing that was written as an inline style 31 times.

    Usage:
        <x-page-header icon="box-arrow-in-down" eyebrow="Buyer / Style Stock"
                       title="Material Receiving" copy="Optional supporting line.">
            <x-slot:actions>
                <a href="…" class="btn btn-outline-secondary">Closing Stock</a>
            </x-slot:actions>
        </x-page-header>

    `title` and `copy` accept a plain string or a slot, so a title carrying a
    badge or other markup still works.
--}}
@props([
    'icon' => null,
    'eyebrow' => null,
    'title' => null,
    'copy' => null,
])

<div {{ $attributes->merge(['class' => 'app-hero-card p-4 mb-4']) }}>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            @if($icon)
                <span class="app-stat-icon gx-hero-icon"><i class="bi bi-{{ $icon }}"></i></span>
            @endif
            <div>
                @if($eyebrow)
                    <div class="app-hero-eyebrow">{{ $eyebrow }}</div>
                @endif
                @if($title)
                    <h3 class="app-hero-title mb-0">{{ $title }}</h3>
                @endif
                @if($copy)
                    <p class="app-hero-copy mb-0">{{ $copy }}</p>
                @endif
            </div>
        </div>

        @isset($actions)
            <div class="d-flex flex-wrap gap-2">{{ $actions }}</div>
        @endisset
    </div>
</div>
