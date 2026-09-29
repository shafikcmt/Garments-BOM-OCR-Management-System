{{--
    One section of the monthly Purchase Requisition report — the "ALL" list or
    a single category — column for column with the reference workbook's row
    9 / row 10 header pair.

    Shared by the PDF and the Excel export so a downloaded sheet can never
    disagree with the printed page.

    Expects: $rows, and the $qty / $money / $date formatters.
--}}
@php
    $forExcel = $forExcel ?? false;
    $sectionTotal = $rows->sum('amount');
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
            {{-- "Stock Allocated" on the Section-wise report: the part of the
                 item's stock given to that section. --}}
            <th class="num">{{ $stockLabel ?? 'Stock in Hand' }}</th>
            <th class="num">Safety Stock</th>
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
                {{-- The item name only; the sheets a row came from are listed
                     in the header's Requisition No. A line naming something
                     not in the Item Master says so next to the name, on paper
                     and in the spreadsheet both — it tells Store the item has
                     still to be added before it can ever be received against. --}}
                @php($notInMaster = ($row['is_custom_item'] ?? false)
                    ? ' ('.\App\Models\PurchaseRequisitionItem::NOT_IN_MASTER_NOTE.')'
                    : '')
                <td>{{ $row['item_name'] }}{{ $notInMaster }}</td>
                <td>{{ $row['uom'] ?: '—' }}</td>
                <td>{{ $row['specification'] ?: '-' }}</td>
                <td>{{ $row['type'] ? ($typeLabels[$row['type']] ?? $row['type']) : '-' }}</td>
                <td>{{ $row['user_dept'] ?: '-' }}</td>

                <td class="num">{{ $qty($row['qty_requested']) }}</td>
                <td class="num">{{ $qty($row['stock_in_hand']) }}</td>
                {{-- Safety Stock: at most 2 decimals on paper; Excel keeps the real figure. --}}
                <td class="num">{{ ($forExcel ?? false) ? $qty($row['safety_stock']) : \App\Support\Quantity::safety($row['safety_stock'], '-') }}</td>
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
