{{--
    Stock Report table — the part that changes when a filter changes.

    Rendered twice: inline by store.stock.ledger on a full page load, and on its
    own by the same controller action when the JS asks for ?partial=1. Because
    both go through the identical Blade, a live search can never format a figure
    differently from the printed page.

    The formatters are declared here rather than inherited from the parent view:
    on an AJAX render there is no parent.
--}}
@php
    // Trim trailing zeros so a whole-number qty reads "15", not "15.0000".
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    // 'd-M-Y', the format every other Store screen prints a date in — Record
    // Receiving, Issues and both of their reports. Used for the Last Addition
    // and Last Consumption columns alike, whether the date came from a real
    // transaction or from an imported reference, so the bookmark icon is the
    // only thing that tells the two apart.
    $date = fn ($v) => $v ? $v->format('d-M-Y') : '—';

    // Only the statuses that need buying get a pill. "ok" is absent on purpose:
    // it is the common case, and a badge on nine rows in ten is decoration that
    // makes the handful of rows that matter harder to find. It renders as a
    // muted dot and word instead — see .gx-ledger-ok.
    $badges = [
        'out' => ['bg-danger text-white', 'bi-x-octagon'],
        'place_order' => ['bg-danger-subtle text-danger', 'bi-cart-plus'],
        'low' => ['bg-warning-subtle text-warning-emphasis', 'bi-exclamation-triangle'],
        // Neutral on purpose: it is information, not a warning. Grouped with the
        // pills rather than the quiet "Ok" treatment because the reader does
        // need to notice that this row carries no figures.
        'not_counted' => ['bg-secondary-subtle text-secondary', 'bi-dash-circle'],
    ];

    // A row whose stock count had not happened by this period has no figures,
    // only computed zeros. Printing "—" is what stops a zero nobody measured
    // being read as an empty shelf. One helper so every numeric cell on the row
    // is blanked the same way and none can be missed.
    $cell = fn (array $r, $value) => $r['not_counted'] ? '—' : $value;

    // Figures that live outside this fragment — the card heading's count badge,
    // the Closing Stock Value beside it, and the four summary tiles. They are
    // all counted over the whole filtered month, not the page, so they have to
    // travel with the fragment or they would go stale the moment a filter
    // narrowed the report.
    $meta = [
        'items' => $summary['items'],
        'out' => $summary['out'],
        'place_order' => $summary['place_order'],
        'low' => $summary['low'],
        // Already formatted: the cards show them as text, so the server does the
        // number formatting once rather than the JS re-deriving it.
        'closing_value' => $money($summary['closing_value']),
        'stock_qty' => $qty($summary['stock_as_on']),
        'month_label' => $monthLabel,
        // Anything not "ok" — the same set the purchase-action banner counts.
        // Not-counted rows are already out of these three counts, so they
        // cannot leak into it.
        'attention' => $summary['out'] + $summary['place_order'] + $summary['low'],
        // The pre-count notice lives OUTSIDE the swapped fragment, so its
        // figures have to travel with the fragment like every other card on
        // this screen — otherwise switching July to August leaves a banner
        // claiming 1,138 uncounted items over a month where there are none.
        'not_counted' => $summary['not_counted'],
        'counted_items' => $summary['counted_items'],
    ];
@endphp

<div data-ledger-meta="{{ json_encode($meta) }}" hidden></div>

{{-- 21 columns: the reference sheet's 20, plus SKU (screen only) after Sl. The wrapper scrolls on its
     own so the page body never scrolls sideways. gx-stock-scroll caps its
     height as well, which is what lets the column headers stay pinned while a
     long month is read down. The pinning is pure CSS, so it survives this
     fragment being swapped in with no JS to re-bind. --}}
<div class="table-responsive gx-stock-scroll">
    <table class="table align-middle gx-stock-table">
        <thead>
            <tr>
                {{-- Tier classes carry the hierarchy. The column ORDER is the
                     reference sheet's and does not move. --}}
                <th class="gx-ledger-primary" style="min-width:110px;">Order?</th>
                <th class="text-end gx-ledger-quiet">Sl</th>
                <th class="gx-ledger-quiet text-nowrap">SKU</th>
                <th class="gx-ledger-primary" style="min-width:200px;">Item Name</th>
                <th style="min-width:140px;">Brand/Specification</th>
                <th class="gx-ledger-quiet">Uom</th>
                <th>Category</th>
                {{-- ONE opening column, and it is the running one — last
                     month's closing, which is what this month's arithmetic
                     builds on. The frozen Item Master count that used to sit
                     beside it never moved between months, so it could not
                     reconcile with Stock as on Date and the client read it as
                     a broken carry-forward. It now lives on the Item Master
                     list, where a counted seed belongs. --}}
                <th class="text-end" title="Last month's closing stock, carried forward">Opening Stock</th>
                <th class="text-end gx-ledger-quiet" title="Last month consumption pattern (per day)">Cons./Day</th>
                <th class="text-end" title="Safety Stock Level (7 days stock)">Safety</th>
                <th class="text-end" title="Safety stock + (consumption per day x (lead time + time to place order))">Re-order</th>
                <th class="text-end gx-ledger-quiet" title="Lead time in days">Lead</th>
                <th class="text-end">Addition</th>
                <th class="gx-ledger-quiet" title="Date of last addition">Last Add.</th>
                <th class="text-end">Consumption</th>
                <th class="gx-ledger-quiet" title="Date of last consumption">Last Cons.</th>
                <th class="text-end gx-ledger-primary">Stock as on Date</th>
                <th class="text-end">Unit Price</th>
                <th class="text-end">Closing Value</th>
                <th style="min-width:120px;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pageRows as $index => $r)
                @php
                    // Two different questions. A not-counted row gets a badge,
                    // because the reader must see why it has no figures — but it
                    // is NOT an action, so it gets no red spine down the edge and
                    // never joins the rows that need buying.
                    $needsAction = ! in_array($r['status'], ['ok', 'not_counted'], true);
                    $isBadged = $r['status'] !== 'ok';
                @endphp
                <tr @class([
                    'table-danger' => $r['status'] === 'out',
                    // Paints the left-edge spine, so the rows that need buying
                    // are findable down the side of the table rather than by
                    // reading the Order? column one row at a time.
                    'gx-ledger-flag-'.$r['status'] => $needsAction,
                ])>
                    <td>
                        @if($isBadged)
                            @php [$badgeClass, $badgeIcon] = $badges[$r['status']]; @endphp
                            <span class="badge {{ $badgeClass }}"><i class="bi {{ $badgeIcon }} me-1" aria-hidden="true"></i>{{ $statusLabels[$r['status']] }}</span>
                        @else
                            {{-- Still says "Ok" in words — the state must never be
                                 carried by colour alone. --}}
                            <span class="gx-ledger-ok">{{ $statusLabels[$r['status']] }}</span>
                        @endif
                    </td>
                    {{-- Serial runs across the whole report, so page 2
                         starts at 101 rather than back at 1. --}}
                    <td class="text-end gx-ledger-quiet">{{ $pageRows->firstItem() + $index }}</td>
                    {{-- SKU in a column of its own, screen only — the PDF and
                         Excel columns are unchanged. --}}
                    <td class="small fw-semibold text-nowrap">{{ $r['item']->sku ?: '—' }}</td>
                    <td class="gx-ledger-primary">
                        <div class="fw-semibold text-slate-900">{{ $r['item']->name }}</div>
                    </td>
                    <td>{{ $r['item']->brand ?: '—' }}</td>
                    <td class="gx-ledger-quiet">{{ $r['item']->uom ?: '—' }}</td>
                    <td>{{ $r['item']->category ?: '—' }}</td>
                    <td class="text-end fw-semibold">{{ $cell($r, $qty($r['balance_bf'])) }}</td>
                    <td class="text-end gx-ledger-quiet">{{ $cell($r, number_format($r['consumption_per_day'], 2)) }}</td>
                    <td class="text-end">
                        {{ $cell($r, \App\Support\Quantity::safety($r['safety'])) }}
                        @if(! $r['not_counted'] && $r['safety_is_manual'])<i class="bi bi-pin-angle-fill text-primary ms-1" title="Set by hand in the item master" aria-hidden="true"></i>@endif
                    </td>
                    <td class="text-end">
                        {{ $cell($r, $r['reorder'] === null ? '—' : $qty($r['reorder'])) }}
                        @if(! $r['not_counted'] && $r['reorder_is_manual'])<i class="bi bi-pin-angle-fill text-primary ms-1" title="Set by hand in the item master" aria-hidden="true"></i>@endif
                    </td>
                    <td class="text-end gx-ledger-quiet">{{ $r['lead_time_days'] }}</td>
                    <td class="text-end text-success">{{ $cell($r, $qty($r['addition'])) }}</td>
                    {{-- gx-stock-micro is already the quiet treatment; adding
                         gx-ledger-quiet on top would only fight it. --}}
                    {{-- A reference date carried over from the old records is
                         marked, the same way a standard price is below: this
                         column normally means "the day something moved", and a
                         date with no transaction behind it is not that. --}}
                    <td class="gx-stock-micro">{{ $date($r['last_addition_date']) }}@if($r['last_addition_is_reference'])<i class="bi bi-bookmark text-muted ms-1" title="Reference date carried over from the old records — no receiving has been recorded here for this item yet" aria-hidden="true"></i>@endif</td>
                    <td class="text-end text-danger">{{ $cell($r, $qty($r['consumption'])) }}</td>
                    <td class="gx-stock-micro">{{ $date($r['last_consumption_date']) }}@if($r['last_consumption_is_reference'])<i class="bi bi-bookmark text-muted ms-1" title="Reference date carried over from the old records — no issue has been recorded here for this item yet" aria-hidden="true"></i>@endif</td>
                    <td class="text-end fw-bold text-slate-900 gx-ledger-primary">{{ $cell($r, $qty($r['stock_as_on'])) }}</td>
                    {{-- Marked the same way as on the Stock Report: a standard
                         price from the item master is not the challan price
                         this column normally shows. --}}
                    <td class="text-end">{{ $cell($r, $money($r['unit_price'])) }}@if(! $r['not_counted'] && $r['unit_price_is_standard'])<i class="bi bi-tag text-muted ms-1" title="Standard price from the item master — nothing has been received against this item with a price on the challan" aria-hidden="true"></i>@endif</td>
                    <td class="text-end fw-semibold">{{ $cell($r, $money($r['closing_value'])) }}</td>
                    <td class="gx-stock-micro">{{ $r['remarks'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="20" class="gx-stock-empty">
                                <span class="gx-stock-empty-icon"><i class="bi bi-search" aria-hidden="true"></i></span>
                                <div class="gx-stock-empty-title">Nothing to show</div>
                                <div class="gx-stock-empty-hint">Try a different month, category or status.</div>
                            </td></tr>
            @endforelse
        </tbody>
        @if($rows->isNotEmpty())
            <tfoot>
                <tr>
                    {{-- 12: the span swallows SKU and Opening Stock, which is
                         deliberately not totalled — summing a
                         brought-forward balance across unrelated items
                         says nothing. Was 12 while the frozen counted
                         column sat beside it. --}}
                    <td colspan="12" class="text-end gx-stock-total-label">Total</td>
                    <td class="text-end text-success">{{ $qty($summary['addition']) }}</td>
                    <td></td>
                    <td class="text-end text-danger">{{ $qty($summary['consumption']) }}</td>
                    <td></td>
                    {{-- Total Qty, under the column it totals. Stock as on Date
                         is what Closing Value is priced from, so the quantity
                         and its money sit on the same row as a pair. --}}
                    <td class="text-end" title="Total closing stock quantity across the filtered items">
                        {{ $qty($summary['stock_as_on']) }}
                        @if(($summary['uom_count'] ?? 0) > 1)
                            {{-- Only when the figure actually spans more than one
                                 unit. Filter down to a single UoM and the caveat
                                 disappears, because then the total is sound. --}}
                            <div class="gx-ledger-total-caveat">across {{ $summary['uom_count'] }} units</div>
                        @endif
                    </td>
                    <td></td>
                    <td class="text-end">{{ $money($summary['closing_value']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

{{-- Compact pager. onEachSide(1) collapses the run of page buttons to
     "1 … 6 7 8 … 9" instead of printing every page.

     No count beside it: the .gx-pagination view already prints
     "Showing 1–100 of 480" inside the pagination area, and that is what
     every other paginated screen in the project shows. A second count here
     said the same thing in different words. --}}
@if($pageRows->hasPages())
    <div class="gx-ledger-pager">{{ $pageRows->onEachSide(1)->links() }}</div>
@endif
