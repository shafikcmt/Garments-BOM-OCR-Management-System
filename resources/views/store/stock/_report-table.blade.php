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

    // A row whose stock count had not happened by this period carries computed
    // zeros, not measured figures. It prints "-" so a printed sheet can never be
    // read as "this item was empty", and summary() leaves it out of the totals
    // below. One helper so no numeric cell on the row is missed.
    $cell = fn (array $r, $value) => $r['not_counted'] ? '-' : $value;
@endphp

<table class="report-table">
    <thead>
        <tr>
            <th class="col-flag">Whether to place order or not</th>
            <th class="col-sl">Sl</th>
            <th class="col-sku">SKU</th>
            <th class="col-name">Item Name</th>
            <th class="col-brand">Brand/Specification</th>
            <th class="col-txt">Uom</th>
            <th class="col-txt">Category</th>
            {{-- ONE opening column, and it is the running one: the seed count
                 plus everything received minus everything issued before this
                 month, which is last month's Stock as on Date.

                 This column used to be called Balance B/F and sat beside a
                 second column, "Opening Stock", carrying the frozen Item Master
                 count. That frozen figure reads the same in every month, so
                 from the second month onward the report printed an "Opening"
                 that did not reconcile with its own Stock as on Date — 221 of
                 1,241 rows in September against local data. The reference
                 workbook has one opening column (Stock <Month> column H, keyed
                 "Opening Stock as on <Month>"), and it is this one. The counted
                 seed is a property of the item, not a monthly movement, and now
                 lives on the Item Master list where it belongs. --}}
            <th class="num">Opening Stock</th>
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
                {{-- Not Counted Yet is stated but never highlighted: it is not a
                     buying instruction, and the red flag is reserved for rows
                     that are. --}}
                <td @class(['flag-order' => ! $forExcel && ! in_array($r['status'], ['ok', 'not_counted'], true)])>{{ $labels[$r['status']] }}</td>
                <td class="col-sl">{{ $index + 1 }}</td>
                <td>{{ $r['item']->sku }}</td>
                <td>{{ $r['item']->name }}</td>
                <td>{{ $r['item']->brand }}</td>
                <td>{{ $r['item']->uom }}</td>
                <td>{{ $r['item']->category }}</td>
                <td class="num">{{ $cell($r, $qty($r['balance_bf'])) }}</td>
                <td class="num">{{ $cell($r, number_format($r['consumption_per_day'], 4, '.', '')) }}</td>
                {{-- Safety Stock: at most 2 decimals on paper; Excel keeps the real figure
                     and shows it to 2 decimals (FormatsStoreSheet). --}}
                <td class="num">{{ $cell($r, $forExcel ? $qty($r['safety']) : \App\Support\Quantity::safety($r['safety'], '')) }}</td>
                <td class="num">{{ $cell($r, $r['reorder'] === null ? '-' : $qty($r['reorder'])) }}</td>
                <td class="num">{{ $r['lead_time_days'] }}</td>
                <td class="num">{{ $cell($r, $qty($r['addition'])) }}</td>
                {{-- A reference date is marked, for the same reason a standard
                     price is: this column normally means "the day something was
                     received", and a date carried over from the old sheets is
                     not that. Dropped from the Excel export, where a marker
                     would only be noise in a cell. --}}
                <td>{{ $date($r['last_addition_date']) }}@if(! $forExcel && $r['last_addition_is_reference'])<i class="bi bi-bookmark text-muted ms-1" title="Reference date carried over from the old records — no receiving has been recorded here for this item yet" aria-hidden="true"></i>@endif</td>
                <td class="num">{{ $cell($r, $qty($r['consumption'])) }}</td>
                <td>{{ $date($r['last_consumption_date']) }}@if(! $forExcel && $r['last_consumption_is_reference'])<i class="bi bi-bookmark text-muted ms-1" title="Reference date carried over from the old records — no issue has been recorded here for this item yet" aria-hidden="true"></i>@endif</td>
                <td class="num">{{ $cell($r, $qty($r['stock_as_on'])) }}</td>
                {{-- A standard price off the item master is marked, because it
                     is not what this report's price column has always meant.
                     The figure here is normally the last challan price — money
                     somebody actually paid — and presenting a standard rate the
                     same way would quietly change what the column asserts. --}}
                <td class="num">{{ $cell($r, $money($r['unit_price'])) }}@if(! $r['not_counted'] && $r['unit_price_is_standard'])<i class="bi bi-tag text-muted ms-1" title="Standard price from the item master — nothing has been received against this item with a price on the challan" aria-hidden="true"></i>@endif</td>
                <td class="num">{{ $cell($r, $money($r['closing_value'])) }}</td>
                <td>{{ $r['remarks'] }}</td>
            </tr>
        @empty
            <tr><td colspan="20" class="empty">No items match this filter.</td></tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            {{-- 12 (SKU included): the leading span swallows Opening Stock, which is
                 deliberately not totalled — summing a brought-forward balance
                 across unrelated items says nothing. Was 12 while the frozen
                 counted column sat beside it. --}}
            <tr class="grand">
                @if($forExcel)
                    {{-- The workbook gets the same row with no merged cells, so
                         the label sits in column A and every figure keeps its
                         own column. --}}
                    <td>Sub-Total</td>
                    @for($blank = 0; $blank < 11; $blank++)<td></td>@endfor
                @else
                    <td colspan="12">Sub-Total</td>
                @endif
                <td class="num">{{ $qty($summary['addition']) }}</td>
                <td></td>
                <td class="num">{{ $qty($summary['consumption']) }}</td>
                @if($forExcel)
                    <td></td><td></td><td></td>
                @else
                    <td colspan="3"></td>
                @endif
                <td class="num">{{ $money($summary['closing_value']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>
