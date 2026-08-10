{{--
    Receiving report — PDF, and the source view for the Excel export
    ($forExcel).

    Same letterhead, header block, navy table and signature footer as the
    monthly Purchase Requisition report, so every Store document reads as one
    family. Styling comes from the shared _report-styles partial those reports
    already use — one stylesheet, so a width tuned on one holds on the others.

    One row per DELIVERY, with its item lines indented underneath, because that
    is how Purchase History presents it and how the paper challan reads.

    Expects: $rows, $lines, $summary, $filters, $periodLabel, $title.
--}}
@php
    $forExcel = $forExcel ?? false;

    // Excel gets bare numbers and blank cells: a "1,175" carrying a thousands
    // separator arrives as TEXT, which cannot be summed, sorted or number
    // formatted. The separators are Excel's job, applied by FormatsStoreSheet.
    // The PDF, which is just ink, keeps them here.
    $qty = $forExcel
        ? fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.')
        : fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

    $money = $forExcel
        ? fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '')
        : fn ($v) => $v === null ? '-' : number_format((float) $v, 2);

    $date = $forExcel
        ? fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : ''
        : fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '-';

    $unit = config('stock.company_name');

    // Columns in the table below, and therefore how wide the Excel letterhead
    // has to merge to sit over it.
    $columns = 11;

    // What the reader is looking at. Only the filters actually applied are
    // listed — a report showing "Supplier: All" for six blank filters buries
    // the one that matters.
    $applied = array_filter([
        'Challan No' => $filters['challan_no'] ?? null,
        'GRN No' => $filters['rv_no'] ?? null,
        'Supplier' => $filters['supplier'] ?? null,
        'Search' => $filters['search'] ?? null,
        'RCV From' => $filters['rcv_from'] ?? null,
        'RCV To' => $filters['rcv_to'] ?? null,
    ]);
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include('store.stock.requisitions._report-styles')
</head>
<body>

@unless($forExcel)
    <table class="top">
        <tr>
            <td style="width:25%;">&nbsp;</td>
            <td style="width:50%;">
                <div class="company">{{ $unit }}</div>
                <div class="title">{{ $title }}</div>
            </td>
            <td style="width:25%;" class="date-area">
                <div>Printed On</div>
                <div>{{ now()->format('d M Y, h:i A') }}</div>
            </td>
        </tr>
    </table>

    <table class="head-block">
        <tr>
            <td class="label">Period</td><td class="value">{{ $periodLabel }}</td>
            <td class="label">Deliveries</td><td class="value">{{ number_format($summary['deliveries']) }}</td>
        </tr>
        <tr>
            <td class="label">Name of Unit</td><td class="value">{{ $unit }}</td>
            <td class="label">Item Lines</td><td class="value">{{ number_format($summary['lines']) }}</td>
        </tr>
        <tr>
            <td class="label">Suppliers</td><td class="value">{{ number_format($summary['suppliers']) }}</td>
            <td class="label">Total Qty</td><td class="value">{{ $qty($summary['qty']) }}</td>
        </tr>
        <tr>
            <td class="label">Filters</td>
            <td class="value">
                @if($applied)
                    @foreach($applied as $label => $value){{ $label }}: {{ $value }}@if(! $loop->last) · @endif @endforeach
                @else
                    None
                @endif
            </td>
            <td class="label">Total Value</td><td class="value">{{ $money($summary['value']) }}</td>
        </tr>
    </table>
@else
    {{-- Excel letterhead. The colspans ARE the merges: the HTML reader turns
         them into merged cells. Type sizes, row heights and alignment come from
         FormatsStoreSheet; HTML cannot carry them into a spreadsheet. --}}
    <table>
        <tr><td colspan="{{ $columns }}">{{ $unit }}</td></tr>
        <tr><td colspan="{{ $columns }}">{{ $title }}</td></tr>
        <tr><td colspan="2">Period</td><td colspan="{{ $columns - 2 }}">{{ $periodLabel }}</td></tr>
        <tr><td colspan="2">Deliveries</td><td colspan="{{ $columns - 2 }}">{{ $summary['deliveries'] }}</td></tr>
        <tr><td colspan="2">Item Lines</td><td colspan="{{ $columns - 2 }}">{{ $summary['lines'] }}</td></tr>
        <tr><td colspan="2">Filters</td>
            <td colspan="{{ $columns - 2 }}">{{ $applied ? collect($applied)->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') : 'None' }}</td></tr>
        <tr><td colspan="{{ $columns }}"></td></tr>
    </table>
@endunless

<table class="report-table">
    <thead>
        <tr>
            {{-- Widths total 81.2%, leaving Item Name the remaining ~19%.
                 Item Name and Remarks both hold several values per row, so
                 they get the width the fixed columns do not need; Remarks is
                 given a declared 12% rather than sharing the remainder, because
                 a long note would otherwise squeeze Item Name. Remarks sits
                 last, after the figures. --}}
            <th class="w-sl">SL</th>
            <th style="width:9%;">GRN No</th>
            <th style="width:9%;">Challan / Inv No</th>
            <th style="width:7.5%;">Challan Date</th>
            <th style="width:7.5%;">RCV Date</th>
            <th style="width:14%;">Supplier</th>
            <th>Item Name</th>
            <th class="num" style="width:5%;">Items</th>
            <th class="num" style="width:7%;">Total Qty</th>
            <th class="num" style="width:8%;">Total Value</th>
            <th style="width:12%;">Remarks</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $row)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>{{ $row->rv_no ?: ($forExcel ? '' : '-') }}</td>
                <td>{{ $row->challan_no ?: ($forExcel ? '' : '-') }}</td>
                <td class="nowrap">{{ $date($row->purchase_date) }}</td>
                <td class="nowrap">{{ $date($row->rcv_date) }}</td>
                <td>{{ $row->supplier_name ?: ($forExcel ? '' : '-') }}</td>
                {{-- The delivery's items, one per line with the quantity that
                     was received of each.

                     Excel gets a <br> rather than a <div>: the HTML reader
                     flattens block elements into the same cell with nothing
                     between them, which would run the names together into one
                     unreadable string. A <br> becomes a real line break inside
                     the cell, so the names stay on separate lines there too. --}}
                @php($groupLines = $lines[$row->group_key] ?? collect())
                <td>
                    @if($groupLines->isEmpty())
                        {{ $forExcel ? '' : '-' }}
                    @elseif($forExcel)
                        @foreach($groupLines as $line){{ optional($line->stockItem)->name }} ({{ $qty($line->qty) }})@if(! $loop->last)<br>@endif @endforeach
                    @else
                        @foreach($groupLines as $line)
                            <div>{{ optional($line->stockItem)->name ?: '-' }} ({{ $qty($line->qty) }})</div>
                        @endforeach
                    @endif
                </td>
                <td class="num">{{ $row->line_count }}</td>
                <td class="num">{{ $qty($row->group_total_qty) }}</td>
                <td class="num">{{ $money($row->group_total_value) }}</td>
                {{-- Last column, after the figures. One remark per item line and
                     in the same order as Item Name, so a note still sits level
                     with the item it belongs to. Same <br>-for-Excel treatment
                     as the names: the HTML reader flattens block elements into
                     one cell with nothing between them. --}}
                <td>
                    @if($groupLines->isEmpty())
                        {{ $forExcel ? '' : '-' }}
                    @elseif($forExcel)
                        @foreach($groupLines as $line){{ $line->remarks }}@if(! $loop->last)<br>@endif @endforeach
                    @else
                        @foreach($groupLines as $line)
                            <div>{{ $line->remarks ?: '-' }}</div>
                        @endforeach
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="11" class="empty">No deliveries match these filters.</td></tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            <tr class="total">
                <td colspan="7" class="total-label">Total-</td>
                <td class="num">{{ number_format($summary['lines']) }}</td>
                <td class="num">{{ $qty($summary['qty']) }}</td>
                <td class="num">{{ $money($summary['value']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>

{{-- No Prepared By / Checked By / Approved By block here, unlike the monthly
     Purchase Requisition report and the single-requisition document. Those two
     are documents that get signed and filed; this is a management summary that
     is read and discarded, so signature lines would only invite someone to
     treat a filtered listing as an approved record. --}}
@unless($forExcel)
    <div class="footer">
        {{ $unit }} &mdash; {{ $title }} &mdash; {{ $periodLabel }}
        <span class="right">Generated {{ now()->format('d M Y') }}</span>
    </div>
@endunless

</body>
</html>
