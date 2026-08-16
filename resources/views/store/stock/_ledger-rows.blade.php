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
    $date = fn ($v) => $v ? $v->format('d-M-y') : '—';

    $badges = [
        'out' => ['bg-danger text-white', 'bi-x-octagon'],
        'place_order' => ['bg-danger-subtle text-danger', 'bi-cart-plus'],
        'low' => ['bg-warning-subtle text-warning-emphasis', 'bi-exclamation-triangle'],
        'ok' => ['bg-success-subtle text-success', 'bi-check2'],
    ];

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
        'closing_value' => $money($summary['closing_value']),
        'month_label' => $monthLabel,
        // Anything not "ok" — the same set the purchase-action banner counts.
        'attention' => $summary['out'] + $summary['place_order'] + $summary['low'],
    ];
@endphp

<div data-ledger-meta="{{ json_encode($meta) }}" hidden></div>

{{-- 20 columns, same order as the reference sheet. The wrapper scrolls on its
     own so the page body never scrolls sideways. gx-stock-scroll caps its
     height as well, which is what lets the column headers stay pinned while a
     long month is read down. The pinning is pure CSS, so it survives this
     fragment being swapped in with no JS to re-bind. --}}
<div class="table-responsive gx-stock-scroll">
    <table class="table align-middle gx-stock-table">
        <thead>
            <tr>
                <th style="min-width:110px;">Order?</th>
                <th class="text-end">Sl</th>
                <th style="min-width:200px;">Item Name</th>
                <th style="min-width:140px;">Brand/Specification</th>
                <th>Uom</th>
                <th>Category</th>
                {{-- Opening is the counted Item Master figure and never
                     moves. Balance B/F is last month's closing — the
                     figure this month's arithmetic actually builds on. --}}
                <th class="text-end" title="Counted stock from the Item Master — never changed by a purchase or an issue">Opening</th>
                <th class="text-end" title="Balance brought forward — last month's closing stock">Balance B/F</th>
                <th class="text-end" title="Last month consumption pattern (per day)">Cons./Day</th>
                <th class="text-end" title="Safety Stock Level (7 days stock)">Safety</th>
                <th class="text-end" title="Safety stock + (consumption per day x (lead time + time to place order))">Re-order</th>
                <th class="text-end" title="Lead time in days">Lead</th>
                <th class="text-end">Addition</th>
                <th title="Date of last addition">Last Add.</th>
                <th class="text-end">Consumption</th>
                <th title="Date of last consumption">Last Cons.</th>
                <th class="text-end">Stock as on Date</th>
                <th class="text-end">Unit Price</th>
                <th class="text-end">Closing Value</th>
                <th style="min-width:120px;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pageRows as $index => $r)
                @php [$badgeClass, $badgeIcon] = $badges[$r['status']]; @endphp
                <tr @class(['table-danger' => $r['status'] === 'out'])>
                    <td>
                        <span class="badge {{ $badgeClass }}"><i class="bi {{ $badgeIcon }} me-1" aria-hidden="true"></i>{{ $statusLabels[$r['status']] }}</span>
                    </td>
                    {{-- Serial runs across the whole report, so page 2
                         starts at 101 rather than back at 1. --}}
                    <td class="text-end text-muted">{{ $pageRows->firstItem() + $index }}</td>
                    <td>
                        <div class="fw-semibold text-slate-900">{{ $r['item']->name }}</div>
                    </td>
                    <td>{{ $r['item']->brand ?: '—' }}</td>
                    <td>{{ $r['item']->uom ?: '—' }}</td>
                    <td>{{ $r['item']->category ?: '—' }}</td>
                    {{-- "—" before the item was counted: no count had
                         happened, which is not the same as zero. --}}
                    <td class="text-end">{{ $qty($r['opening']) }}</td>
                    <td class="text-end fw-semibold">{{ $qty($r['balance_bf']) }}</td>
                    <td class="text-end text-muted">{{ number_format($r['consumption_per_day'], 2) }}</td>
                    <td class="text-end">
                        {{ $qty($r['safety']) }}
                        @if($r['safety_is_manual'])<i class="bi bi-pin-angle-fill text-primary ms-1" title="Set by hand in the item master" aria-hidden="true"></i>@endif
                    </td>
                    <td class="text-end">
                        {{ $r['reorder'] === null ? '—' : $qty($r['reorder']) }}
                        @if($r['reorder_is_manual'])<i class="bi bi-pin-angle-fill text-primary ms-1" title="Set by hand in the item master" aria-hidden="true"></i>@endif
                    </td>
                    <td class="text-end text-muted">{{ $r['lead_time_days'] }}</td>
                    <td class="text-end text-success">{{ $qty($r['addition']) }}</td>
                    <td class="gx-stock-micro">{{ $date($r['last_addition_date']) }}</td>
                    <td class="text-end text-danger">{{ $qty($r['consumption']) }}</td>
                    <td class="gx-stock-micro">{{ $date($r['last_consumption_date']) }}</td>
                    <td class="text-end fw-bold text-slate-900">{{ $qty($r['stock_as_on']) }}</td>
                    <td class="text-end">{{ $money($r['unit_price']) }}</td>
                    <td class="text-end fw-semibold">{{ $money($r['closing_value']) }}</td>
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
                    {{-- 12, not 11: Balance B/F sits inside this span and
                         is deliberately not totalled — summing a
                         brought-forward balance across unrelated items
                         says nothing. --}}
                    <td colspan="12" class="text-end gx-stock-total-label">Total</td>
                    <td class="text-end text-success">{{ $qty($summary['addition']) }}</td>
                    <td></td>
                    <td class="text-end text-danger">{{ $qty($summary['consumption']) }}</td>
                    <td colspan="3"></td>
                    <td class="text-end">{{ $money($summary['closing_value']) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>

{{-- Only shown once the report is longer than one page. The count
     line stays useful either way, so it is not hidden with the
     links: it says how much of the month is on screen. --}}
@if($pageRows->total() > 0)
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
        <div class="small text-muted">
            Showing {{ number_format($pageRows->firstItem()) }}–{{ number_format($pageRows->lastItem()) }}
            of {{ number_format($pageRows->total()) }} items.
            Totals above cover the full month.
        </div>
        @if($pageRows->hasPages())
            <div>{{ $pageRows->links() }}</div>
        @endif
    </div>
@endif
