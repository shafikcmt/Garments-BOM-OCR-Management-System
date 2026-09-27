{{--
    A requisition's total in the lists. The amount is To Be Procured x rate,
    so 0 means nothing to buy (stock covers it) or no rate yet — not a free
    purchase. Printed as "—" with the reason rather than a misleading 0.00.
    Expects: $amount (the items_sum_amount aggregate).
--}}
@if((float) ($amount ?? 0) > 0)
    {{ number_format((float) $amount, 2) }}
@else
    <span class="text-muted" title="Nothing to purchase, or no rate yet">—</span>
@endif
