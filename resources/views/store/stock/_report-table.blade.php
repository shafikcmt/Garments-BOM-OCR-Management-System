{{--
    The Consumable Stock Report table, shared by the PDF and the Excel export so
    a download can never disagree with a preview.

    Column order is the reference sheet's, headers shortened to fit A4
    landscape. $forExcel drops the colour classes, which mean nothing in a
    spreadsheet, and prints the plain "Place Order" / "Ok" text the Excel
    column B carries.
--}}
@php
    $forExcel = $forExcel ?? false;
    $qty = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
    $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '');
    $date = fn ($v) => $v ? $v->format('d-M-y') : '';
    $labels = \App\Services\GeneralStockReportService::statusLabels();
@endphp

<table class="report-table">
    <thead>
        <tr>
            <th class="col-flag">Whether to place order or not</th>
            <th class="col-sl">Sl</th>
            <th class="col-name">Item Name</th>
            <th class="col-brand">Brand</th>
            <th class="col-size">Size</th>
            <th class="col-spec">Specification</th>
            <th class="col-txt">Uom</th>
            <th class="col-txt">Category</th>
            {{-- Opening Stock is the counted Item Master figure and never moves.
                 Balance B/F is the running figure this month builds on — last
                 month's closing. Kept side by side so the two are read as the
                 different things they are. --}}
            <th class="num">Opening Stock</th>
            <th class="num">Balance B/F</th>
            <th class="num">Last Month Cons. Pattern</th>
            <th class="num">Safety Stock Level</th>
            <th class="num">Re-order Level</th>
            <th class="num">Lead Time</th>
            <th class="num">Addition</th>
            <th class="col-txt">Date of Last Addition</th>
            <th class="num">Consumption</th>
            <th class="col-txt">Date of Last Cons.</th>
            <th class="num">Stock as on Date</th>
            <th class="num">Unit Price</th>
            <th class="num">Closing Stock Value</th>
            <th class="col-txt">Remarks</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $index => $r)
            <tr>
                <td @class(['flag-order' => ! $forExcel && $r['status'] !== 'ok'])>{{ $labels[$r['status']] }}</td>
                <td class="col-sl">{{ $index + 1 }}</td>
                <td>{{ $r['item']->name }}</td>
                <td>{{ $r['item']->brand }}</td>
                <td>{{ $r['item']->size }}</td>
                <td>{{ $r['item']->specification }}</td>
                <td>{{ $r['item']->uom }}</td>
                <td>{{ $r['item']->category }}</td>
                {{-- Blank before the item was counted: no count had happened,
                     which is not the same as having counted zero. --}}
                <td class="num">{{ $r['opening'] === null ? '-' : $qty($r['opening']) }}</td>
                <td class="num">{{ $qty($r['balance_bf']) }}</td>
                <td class="num">{{ number_format($r['consumption_per_day'], 4, '.', '') }}</td>
                <td class="num">{{ $qty($r['safety']) }}</td>
                <td class="num">{{ $r['reorder'] === null ? '-' : $qty($r['reorder']) }}</td>
                <td class="num">{{ $r['lead_time_days'] }}</td>
                <td class="num">{{ $qty($r['addition']) }}</td>
                <td>{{ $date($r['last_addition_date']) }}</td>
                <td class="num">{{ $qty($r['consumption']) }}</td>
                <td>{{ $date($r['last_consumption_date']) }}</td>
                <td class="num">{{ $qty($r['stock_as_on']) }}</td>
                <td class="num">{{ $money($r['unit_price']) }}</td>
                <td class="num">{{ $money($r['closing_value']) }}</td>
                <td>{{ $r['remarks'] }}</td>
            </tr>
        @empty
            <tr><td colspan="22" class="empty">No items match this filter.</td></tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            {{-- 14, not 13: Balance B/F sits inside the leading span and is
                 deliberately not totalled — summing a brought-forward balance
                 across unrelated items says nothing. --}}
            <tr class="grand">
                <td colspan="14">Sub-Total</td>
                <td class="num">{{ $qty($summary['addition']) }}</td>
                <td></td>
                <td class="num">{{ $qty($summary['consumption']) }}</td>
                <td colspan="3"></td>
                <td class="num">{{ $money($summary['closing_value']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>
