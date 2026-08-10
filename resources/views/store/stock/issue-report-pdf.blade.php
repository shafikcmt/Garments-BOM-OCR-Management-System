{{--
    Issues (Consumption) report — PDF, and the source view for the Excel export
    ($forExcel).

    Same letterhead, header block, navy table and signature footer as the
    monthly Purchase Requisition report and its Receiving twin. Styling comes
    from the shared _report-styles partial all three use.

    One row per issue LINE, column for column with Issue History.

    Expects: $rows, $summary, $byCategory, $filters, $periodLabel, $title.
--}}
@php
    $forExcel = $forExcel ?? false;

    // See the note in receiving-report-pdf: Excel needs bare numbers so a
    // column stays numeric, the PDF keeps separators because it is only ink.
    $qty = $forExcel
        ? fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.')
        : fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

    $date = $forExcel
        ? fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : ''
        : fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '-';

    $blank = $forExcel ? '' : '-';

    $unit = config('stock.company_name');

    // Width of the letterhead block above the table, in columns. Must match the
    // data table below (SL, Date, Section, Person, Approved By, Req No, Type,
    // Item, Category, Issued Qty, Remarks) or the merged title lines stop short
    // of the table's right edge on the Excel copy.
    $columns = 11;

    $applied = array_filter([
        'Req No' => $filters['requisition_no'] ?? null,
        'Type' => $filters['type'] ?? null,
        'Search' => $filters['search'] ?? null,
        'From' => $filters['from'] ?? null,
        'To' => $filters['to'] ?? null,
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
            <td class="label">Issue Lines</td><td class="value">{{ number_format($summary['lines']) }}</td>
        </tr>
        <tr>
            <td class="label">Name of Unit</td><td class="value">{{ $unit }}</td>
            <td class="label">Distinct Items</td><td class="value">{{ number_format($summary['items']) }}</td>
        </tr>
        <tr>
            <td class="label">Sections</td><td class="value">{{ number_format($summary['sections']) }}</td>
            <td class="label">Requisitions</td><td class="value">{{ number_format($summary['requisitions']) }}</td>
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
            <td class="label">Total Qty Issued</td><td class="value">{{ $qty($summary['qty']) }}</td>
        </tr>
    </table>
@else
    <table>
        <tr><td colspan="{{ $columns }}">{{ $unit }}</td></tr>
        <tr><td colspan="{{ $columns }}">{{ $title }}</td></tr>
        <tr><td colspan="2">Period</td><td colspan="{{ $columns - 2 }}">{{ $periodLabel }}</td></tr>
        <tr><td colspan="2">Issue Lines</td><td colspan="{{ $columns - 2 }}">{{ $summary['lines'] }}</td></tr>
        <tr><td colspan="2">Distinct Items</td><td colspan="{{ $columns - 2 }}">{{ $summary['items'] }}</td></tr>
        <tr><td colspan="2">Filters</td>
            <td colspan="{{ $columns - 2 }}">{{ $applied ? collect($applied)->map(fn ($v, $k) => $k.': '.$v)->implode(' · ') : 'None' }}</td></tr>
        <tr><td colspan="{{ $columns }}"></td></tr>
    </table>
@endunless

<table class="report-table">
    <thead>
        <tr>
            <th class="w-sl">SL</th>
            <th style="width:8%;">Date</th>
            <th style="width:10%;">Section</th>
            <th style="width:10%;">Person</th>
            <th style="width:10%;">Approved By</th>
            <th style="width:9%;">Req No</th>
            <th style="width:6%;">Type</th>
            <th>Item</th>
            <th style="width:10%;">Category</th>
            <th class="num" style="width:8%;">Issued Qty</th>
            <th style="width:13%;">Remarks</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $row)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td class="nowrap">{{ $date($row->issue_date) }}</td>
                <td>{{ optional($row->indentSection)->name ?: $blank }}</td>
                <td>{{ optional($row->indentPerson)->name ?: $blank }}</td>
                <td>{{ optional($row->approver)->name ?: $blank }}</td>
                <td>{{ $row->requisition_no ?: $blank }}</td>
                <td>{{ $row->requisition_type ?: $blank }}</td>
                <td>{{ optional($row->stockItem)->name ?: $blank }}</td>
                <td>{{ optional($row->itemCategory)->name ?: $blank }}</td>
                <td class="num">{{ $qty($row->qty) }}</td>
                <td>{{ $row->remarks ?: $blank }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="empty">No issues match these filters.</td></tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            <tr class="total">
                <td colspan="9" class="total-label">Total-</td>
                <td class="num">{{ $qty($summary['qty']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>

{{-- Consumption per category — what the store actually spent the month on,
     which is the figure a purchase plan is built from. --}}
@if($byCategory->isNotEmpty())
    <div class="section-title">Consumption by Category</div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="w-sl">SL</th>
                <th>Category</th>
                <th class="num" style="width:14%;">Distinct Items</th>
                <th class="num" style="width:14%;">Issue Lines</th>
                <th class="num" style="width:14%;">Qty Issued</th>
            </tr>
        </thead>
        <tbody>
            @foreach($byCategory as $name => $group)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>{{ $name }}</td>
                    <td class="num">{{ number_format($group['items']) }}</td>
                    <td class="num">{{ number_format($group['lines']) }}</td>
                    <td class="num">{{ $qty($group['qty']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="2" class="total-label">Total-</td>
                <td class="num">{{ number_format($summary['items']) }}</td>
                <td class="num">{{ number_format($summary['lines']) }}</td>
                <td class="num">{{ $qty($summary['qty']) }}</td>
            </tr>
        </tfoot>
    </table>
@endif

{{-- No Prepared By / Checked By / Approved By block here — see the note in
     receiving-report-pdf.blade.php. The monthly Purchase Requisition report
     keeps its own, and that markup lives in its own blade, so nothing here
     affects it. --}}
@unless($forExcel)
    <div class="footer">
        {{ $unit }} &mdash; {{ $title }} &mdash; {{ $periodLabel }}
        <span class="right">Generated {{ now()->format('d M Y') }}</span>
    </div>
@endunless

</body>
</html>
