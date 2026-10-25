{{--
    Receiving report — PDF, and the source view for the Excel export
    ($forExcel).

    Same letterhead, header block, navy table and signature footer as the
    monthly Purchase Requisition report, so every Store document reads as one
    family. Styling comes from the shared _report-styles partial those reports
    already use — one stylesheet, so a width tuned on one holds on the others.

    One row per ITEM RECEIVED. The delivery's own GRN, challan, dates and
    supplier repeat on each of its item rows, so a reader can follow any single
    row across the page and, on Excel, filter and sort by item.

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
    // has to merge to sit over it. SL, GRN, Challan, Challan Date, RCV Date,
    // Supplier, Item Name, Brand/Specification, UOM, Category, Received Qty,
    // Unit Price, Received Value, Remarks.
    $columns = 14;

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
    {{-- Excel letterhead: the unit and the report title, left-aligned and NOT
         merged (a merged heading breaks sorting and filtering). One Filters line
         (date range and filters) appears only when something narrows the sheet, so a
         workbook covering one supplier or one date range says so on its face.
         Type sizes and row heights come from FormatsStoreSheet. --}}
    <table>
        <tr><td>{{ $unit }}</td></tr>
        <tr><td>{{ $title }}</td></tr>
        @php
            // One line, and only when something narrows the sheet: the date
            // range (when it is not "All dates") and any other filter.
            $narrowing = collect($applied ?: [])->map(fn ($v, $k) => $k.': '.$v);
            if ($periodLabel !== 'All dates') {
                $narrowing->prepend('Period: '.$periodLabel);
            }
        @endphp
        @if($narrowing->isNotEmpty())
            <tr><td>Filters</td><td>{{ $narrowing->implode(' · ') }}</td></tr>
        @endif
        <tr><td></td></tr>
    </table>
@endunless

<table class="report-table">
    <thead>
        <tr>
            {{-- Widths total 85%, leaving Item Name the remaining ~15% — it is
                 the longest text on the row and takes what the fixed columns do
                 not need. Brand/Specification, Category and Remarks are given
                 declared widths rather than sharing the remainder, because one
                 long brand or note would otherwise squeeze Item Name off the
                 page. Landscape A4 carries all fourteen. --}}
            <th class="w-sl">SL</th>
            <th style="width:9%;">GRN No</th>
            <th style="width:9%;">Challan / Inv No</th>
            <th style="width:7.5%;">Challan Date</th>
            <th style="width:7.5%;">RCV Date</th>
            <th style="width:14%;">Supplier</th>
            <th>Item Name</th>
            <th style="width:10%;">Brand/Specification</th>
            <th style="width:4%;">UOM</th>
            <th style="width:9%;">Category</th>
            <th class="num" style="width:6%;">Received Qty</th>
            <th class="num" style="width:6%;">Unit Price</th>
            <th class="num" style="width:7%;">Received Value</th>
            <th style="width:9%;">Remarks</th>
        </tr>
    </thead>
    <tbody>
        {{-- One row per ITEM RECEIVED, not per delivery. A challan carrying
             three items prints three rows, each repeating that delivery's GRN,
             challan, dates and supplier, so each row stands on its own — and on
             Excel every row is a real row, which can be filtered, sorted and
             pivoted. Stacking several names inside one cell could do none of
             that. $sl runs across the whole table. --}}
        @php($sl = 0)
        @forelse($rows as $row)
            @php($groupLines = $lines[$row->group_key] ?? collect())
            @forelse($groupLines as $line)
                @php($sl++)
                <tr>
                    <td class="num">{{ $sl }}</td>
                    <td>{{ $row->rv_no ?: ($forExcel ? '' : '-') }}</td>
                    <td>{{ $row->challan_no ?: ($forExcel ? '' : '-') }}</td>
                    <td class="nowrap">{{ $date($row->purchase_date) }}</td>
                    <td class="nowrap">{{ $date($row->rcv_date) }}</td>
                    <td>{{ $row->supplier_name ?: ($forExcel ? '' : '-') }}</td>
                    <td>{{ optional($line->stockItem)->name ?: ($forExcel ? '' : '-') }}</td>
                    {{-- Brand/Specification and Category off the item master,
                         the same source the Record Receiving form and Purchase
                         History read them from. --}}
                    <td>{{ optional($line->stockItem)->brand ?: ($forExcel ? '' : '-') }}</td>
                    <td>{{ optional($line->stockItem)->uom ?: ($forExcel ? '' : '-') }}</td>
                    <td>{{ optional($line->stockItem)->category ?: ($forExcel ? '' : '-') }}</td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    <td class="num">{{ $line->unit_price === null ? ($forExcel ? '' : '-') : $money($line->unit_price) }}</td>
                    <td class="num">{{ $money($line->total_value) }}</td>
                    <td>{{ $line->remarks ?: ($forExcel ? '' : '-') }}</td>
                </tr>
            @empty
                {{-- A delivery with no item lines left still belongs in the
                     table: dropping it would make the Deliveries figure in the
                     header block disagree with what is printed below it. --}}
                @php($sl++)
                <tr>
                    <td class="num">{{ $sl }}</td>
                    <td>{{ $row->rv_no ?: ($forExcel ? '' : '-') }}</td>
                    <td>{{ $row->challan_no ?: ($forExcel ? '' : '-') }}</td>
                    <td class="nowrap">{{ $date($row->purchase_date) }}</td>
                    <td class="nowrap">{{ $date($row->rcv_date) }}</td>
                    <td>{{ $row->supplier_name ?: ($forExcel ? '' : '-') }}</td>
                    <td>{{ $forExcel ? '' : '-' }}</td>
                    <td>{{ $forExcel ? '' : '-' }}</td>
                    <td>{{ $forExcel ? '' : '-' }}</td>
                    <td>{{ $forExcel ? '' : '-' }}</td>
                    <td class="num">{{ $qty($row->group_total_qty) }}</td>
                    <td class="num">{{ $forExcel ? '' : '-' }}</td>
                    <td class="num">{{ $money($row->group_total_value) }}</td>
                    <td>{{ $forExcel ? '' : '-' }}</td>
                </tr>
            @endforelse
        @empty
            <tr><td colspan="14" class="empty">No deliveries match these filters.</td></tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            <tr class="total">
                {{-- Brand/Specification and Category were added after Item
                     Name, so the label runs two columns further. Unit Price is
                     a per-line rate with no meaningful total, so its cell stays
                     empty rather than summing rates into a number that means
                     nothing. --}}
                @if($forExcel)
                    <td class="total-label">Total-</td>
                    @for($blank = 0; $blank < 9; $blank++)<td></td>@endfor
                @else
                    <td colspan="10" class="total-label">Total-</td>
                @endif
                <td class="num">{{ $qty($summary['qty']) }}</td>
                <td></td>
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
