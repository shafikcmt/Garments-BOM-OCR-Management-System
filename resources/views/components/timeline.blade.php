{{--
    Vertical activity timeline. Pass items or compose <x-timeline-item> in the
    slot. Rendered as an <ol> because the order carries meaning.

    items: [['icon' =>, 'tone' =>, 'title' =>, 'description' =>, 'meta' =>], …]
--}}
@props(['items' => []])

<ol {{ $attributes->merge(['class' => 'gx-timeline']) }}>
    @forelse($items as $item)
        <li class="gx-timeline-item">
            <span class="gx-timeline-marker gx-tone-{{ $item['tone'] ?? 'primary' }}" aria-hidden="true">
                <i class="bi bi-{{ $item['icon'] ?? 'dot' }}"></i>
            </span>
            <div class="gx-timeline-body">
                <div class="gx-timeline-title">{{ $item['title'] ?? '' }}</div>
                @if(! empty($item['description']))
                    <div class="gx-timeline-desc">{{ $item['description'] }}</div>
                @endif
                @if(! empty($item['meta']))
                    <div class="gx-timeline-meta">{{ $item['meta'] }}</div>
                @endif
            </div>
        </li>
    @empty
        <li class="text-muted small">No activity recorded yet.</li>
    @endforelse

    {{ $slot }}
</ol>
