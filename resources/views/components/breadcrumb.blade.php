{{--
    Breadcrumb trail.

    Usage:
        x-breadcrumb :items="[['label' => 'Buyer / Style Stock'], ['label' => 'Receiving']]"
        x-breadcrumb :items="[['label' => 'Store', 'url' => route('store.dashboard')], ['label' => 'Receiving']]"

    The last item is marked aria-current="page" and rendered as plain text, so
    screen readers announce the current location. Items without a url render as
    text too, which suits section labels that have no page of their own.
--}}
@props(['items' => []])

@if(count($items) > 0)
    <nav class="gx-breadcrumb" aria-label="Breadcrumb">
        <ol>
            @foreach($items as $i => $item)
                @php
                    $isLast = $i === count($items) - 1;
                    $label = is_array($item) ? ($item['label'] ?? '') : $item;
                    $url = is_array($item) ? ($item['url'] ?? null) : null;
                @endphp

                @if($i > 0)
                    <li class="gx-breadcrumb-sep" aria-hidden="true">/</li>
                @endif

                <li @if($isLast) aria-current="page" @endif>
                    @if($url && ! $isLast)
                        <a href="{{ $url }}">{{ $label }}</a>
                    @else
                        {{ $label }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
