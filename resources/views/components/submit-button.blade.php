{{--
    Submit button that shows a spinner and disables itself once clicked.

    Guards against the double-submit that costs real money here: pressing
    "Save" twice on a receiving or a payment request creates two records.
    The disable happens on submit rather than on click, so browser validation
    still gets to block an incomplete form first.

    Usage:  x-submit-button>Save Receivings</x-submit-button
            x-submit-button variant="danger" busy="Deleting…">Delete</x-submit-button
--}}
@props([
    'variant' => 'primary',
    'size' => null,
    'icon' => null,
    'busy' => 'Working…',
])

<button type="submit"
    data-submit-button
    data-busy-label="{{ $busy }}"
    {{ $attributes->merge([
        'class' => 'btn btn-'.$variant.($size ? ' btn-'.$size : ''),
    ]) }}>
    <span data-submit-label>
        @if($icon)<i class="bi bi-{{ $icon }} me-1" aria-hidden="true"></i>@endif
        {{ $slot }}
    </span>
</button>
