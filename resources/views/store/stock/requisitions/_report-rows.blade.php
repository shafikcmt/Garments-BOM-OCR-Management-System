{{--
    One section of the monthly Purchase Requisition report — the "ALL" list or
    a single category — column for column with the reference workbook's row
    9 / row 10 header pair.

    Shared by the PDF and the Excel export so a downloaded sheet can never
    disagree with the printed page.

    Expects: $rows, $merge, and the $qty / $money / $date formatters.
--}}
@php
    $forExcel = $forExcel ?? false;
    $sectionTotal = $rows->sum('amount');

    // Where the line came from, printed under the item name. On the monthly
    // report that is the only way to trace a figure back to its document, so it
    // is on by default; a single requisition's own document already names it in
    // the header, where repeating it on every line is just noise.
    $showSource = $showSource ?? true;
@endphp

<table class="report-table">
    <thead>
        <tr>
            <th rowspan="2" class="w-sl">SL</th>
            <th rowspan="2" class="w-item">Name of Item's</th>
            <th rowspan="2" class="w-uom">Uom</th>
            <th rowspan="2" class="w-spec">Specification / Brand &amp; Model</th>
            <th rowspan="2" class="w-type">Type (Replace/ New)</th>
            <th rowspan="2" class="w-dept">User Dept./ Section</th>
            <th colspan="4">Stock Details</th>
            <th colspan="3">Last Purchase Details</th>
            <th colspan="3">To Be Procured</th>
            <th colspan="2">Store Acknowledgement on GRN</th>
            <th colspan="2">Accounts Acknowledgement on Pmt</th>
            <th rowspan="2" class="w-remarks">Remarks</th>
        </tr>
        <tr>
            <th class="num">Qty Requested</th>
            <th class="num">Stock in Hand</th>
            <th class="num">Saftey Stock</th>
            <th class="num">Consumption (Last Month)</th>
            <th class="num">Qty</th>
            <th class="num">Approx Rate</th>
            <th class="num">Date</th>
            <th class="num">Qty</th>
            <th class="num">Rate Appx.</th>
            <th class="num">Amount</th>
            <th class="num">Pending Qty</th>
            <th>Signature</th>
            <th class="num">Pending Qty</th>
            <th>Signature</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $row)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                {{-- Where the line came from, under the item name. On a merged
                     row this lists every requisition that asked for the item.

                     Excel gets a line break rather than a <div>: the HTML
                     reader flattens block elements into the same cell with
                     nothing between them, which ran the item name and the
                     requisition number together as one unreadable string. --}}
                <td>
                    @if($forExcel)
                        {{ $row['item_name'] }}@if($showSource && $row['requisition_no'])<br>{{ $row['requisition_no'] }}@endif
                    @else
                        {{ $row['item_name'] }}
                        @if($showSource)
                            <div class="src">{{ $row['requisition_no'] ?: '—' }}</div>
                        @endif
                    @endif
                </td>
                <td>{{ $row['uom'] ?: '—' }}</td>
                <td>{{ $row['specification'] ?: '-' }}</td>
                <td>{{ $row['type'] ? ($typeLabels[$row['type']] ?? $row['type']) : '-' }}</td>
                <td>{{ $row['user_dept'] ?: '-' }}</td>

                <td class="num">{{ $qty($row['qty_requested']) }}</td>
                <td class="num">{{ $qty($row['stock_in_hand']) }}</td>
                <td class="num">{{ $qty($row['safety_stock']) }}</td>
                <td class="num">{{ $qty($row['consumption_last_month']) }}</td>

                <td class="num">{{ $qty($row['last_purchase_qty']) }}</td>
                <td class="num">{{ $money($row['last_purchase_rate']) }}</td>
                <td class="num nowrap">{{ $date($row['last_purchase_date']) }}</td>

                <td class="num">{{ $qty($row['to_be_procured']) }}</td>
                <td class="num">{{ $money($row['rate_appx']) }}</td>
                <td class="num">{{ $money($row['amount']) }}</td>

                <td class="num">{{ $qty($row['store_pending_qty']) }}</td>
                <td></td>
                <td class="num">{{ $qty($row['accounts_pending_qty']) }}</td>
                <td></td>

                <td>{{ $row['remarks'] ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="21" class="empty">{{ $emptyMessage ?? 'No requisitions raised in this month.' }}</td></tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            {{-- "Total-" with the Amount sum, exactly where the reference
                 workbook puts it (column P). --}}
            <tr class="total">
                <td colspan="15" class="total-label">Total-</td>
                <td class="num">{{ $money($sectionTotal) }}</td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    @endif
</table>
