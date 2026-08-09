{{--
    One Purchase Requisition as its own document — PDF, and the source view for
    the single-sheet Excel export ($forExcel).

    Same letterhead, same header block, same two-tier item table and the same
    Prepared by / Checked by / Approved by footer as the monthly report, scoped
    to a single requisition instead of a whole month. The item table itself is
    the shared _report-rows partial, so this page can never disagree with the
    monthly report or the screen.

    Expects: $requisition, $rows, $title.
--}}
@php
    $forExcel = $forExcel ?? false;

    // Same reasoning as the monthly report: Excel gets bare numbers and blank
    // cells so a column stays numeric, the PDF keeps separators and dashes
    // because it is only ink. See report-pdf.blade.php.
    $qty = $forExcel
        ? fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.')
        : fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

    $money = $forExcel
        ? fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '')
        : fn ($v) => $v === null ? '-' : number_format((float) $v, 2);

    $date = $forExcel
        ? fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : ''
        : fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '-';

    $typeLabels = \App\Models\PurchaseRequisitionItem::typeLabels();

    // The requisition's own unit, falling back to the configured company name —
    // an older record may have been saved before the field was filled.
    $unit = $requisition->unit_name ?: config('stock.company_name');

    $stamp = fn ($name, $at) => $name
        ? $name.($at ? ' · '.$at->format('d-M-Y') : '')
        : 'Pending';

    $requisitionDate = optional($requisition->requisition_date)->format('d F Y') ?: '-';
    $section = optional($requisition->indentSection)->name ?: 'All';

    // Left column: what the document is. Right column: who handled it. Both
    // read top to bottom, as on the paper form, and both are used by the PDF
    // header block and the Excel letterhead below.
    $infoRows = [
        ['Requisition No', $requisition->requisition_no, 'Type', \App\Models\PurchaseRequisition::modeLabels()[$requisition->mode] ?? $requisition->mode],
        ['Requisition Date', $requisitionDate, 'Prepared By', optional($requisition->createdBy)->name ?: '-'],
        ['Name of Unit', $unit, 'Submitted', $requisition->submitted_at?->format('d-M-Y H:i') ?: '-'],
        ['Section', $section, 'Store Acknowledgement', $stamp(optional($requisition->storeAckBy)->name, $requisition->store_ack_at)],
        ['Name of User', optional($requisition->indentPerson)->name ?: '-', 'Accounts Acknowledgement', $stamp(optional($requisition->accountsAckBy)->name, $requisition->accounts_ack_at)],
        ['Contact No. / Email', $requisition->contact ?: '-', 'Approved By', $stamp(optional($requisition->approvedBy)->name, $requisition->approved_at)],
        ['Status', $requisition->statusLabel(), 'Total Amount', $money($rows->sum('amount'))],
    ];
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
        @foreach($infoRows as [$label, $value, $rightLabel, $rightValue])
            <tr>
                <td class="label">{{ $label }}</td><td class="value">{{ $value }}</td>
                <td class="label">{{ $rightLabel }}</td><td class="value">{{ $rightValue }}</td>
            </tr>
        @endforeach
    </table>
@else
    {{-- Excel letterhead. The colspans ARE the merges: the HTML reader turns
         them into merged cells, so the sheet opens with the company across the
         full width, the title across the full width, then one row per
         information pair. Type sizes and alignment come from FormatsStoreSheet;
         HTML cannot carry them into a spreadsheet. --}}
    @php
        $columns = 21;
        $labelSpan = 4;
        $valueSpan = 6;
        $rightLabelSpan = 4;
        $rightValueSpan = $columns - $labelSpan - $valueSpan - $rightLabelSpan;
    @endphp
    <table>
        <tr><td colspan="{{ $columns }}">{{ $unit }}</td></tr>
        <tr><td colspan="{{ $columns }}">{{ $title }}</td></tr>
        @foreach($infoRows as [$label, $value, $rightLabel, $rightValue])
            <tr>
                <td colspan="{{ $labelSpan }}">{{ $label }}</td>
                <td colspan="{{ $valueSpan }}">{{ $value }}</td>
                <td colspan="{{ $rightLabelSpan }}">{{ $rightLabel }}</td>
                <td colspan="{{ $rightValueSpan }}">{{ $rightValue }}</td>
            </tr>
        @endforeach
        <tr><td colspan="{{ $columns }}"></td></tr>
    </table>
@endunless

@include('store.stock.requisitions._report-rows', [
    'rows' => $rows,
    'emptyMessage' => 'No items on this requisition.',
    // The header block already names the requisition; repeating it on every
    // line would just be the same string four times over.
    'showSource' => false,
])

@unless($forExcel)
    @if($requisition->remarks)
        <div class="note"><strong>Remarks:</strong> {{ $requisition->remarks }}</div>
    @endif

    <div class="note">
        Stock, safety, consumption and last purchase are the figures as at
        {{ $requisitionDate }} — frozen when this requisition was raised.
    </div>

    <table class="sign">
        <tr>
            <td>
                <div class="sign-title">Prepared By</div>
                <div class="sign-line"></div>
                <div class="sign-meta">{{ optional($requisition->createdBy)->name ?: 'Store' }}</div>
            </td>
            <td>
                <div class="sign-title">Checked By</div>
                <div class="sign-line"></div>
                <div class="sign-meta">{{ optional($requisition->checkedBy)->name ?: 'Store In-charge' }}</div>
            </td>
            <td>
                <div class="sign-title">Approved By</div>
                <div class="sign-line"></div>
                <div class="sign-meta">{{ optional($requisition->approvedBy)->name ?: 'Management' }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $unit }} &mdash; {{ $title }} &mdash; {{ $requisition->requisition_no }}
        <span class="right">Generated {{ now()->format('d M Y') }}</span>
    </div>
@else
    {{-- Excel signature block — the blank rows above the labels are the
         signing space; bold, borders and heights come from FormatsStoreSheet. --}}
    @php($third = (int) floor(21 / 3))
    <table>
        <tr><td colspan="21"></td></tr>
        <tr><td colspan="21"></td></tr>
        <tr><td colspan="21"></td></tr>
        <tr>
            <td colspan="{{ $third }}">Prepared by</td>
            <td colspan="{{ $third }}">Checked by</td>
            <td colspan="{{ 21 - 2 * $third }}">Approved by</td>
        </tr>
        <tr>
            <td colspan="{{ $third }}">{{ optional($requisition->createdBy)->name ?: 'Store' }}</td>
            <td colspan="{{ $third }}">{{ optional($requisition->checkedBy)->name ?: 'Store In-charge' }}</td>
            <td colspan="{{ 21 - 2 * $third }}">{{ optional($requisition->approvedBy)->name ?: 'Management' }}</td>
        </tr>
    </table>
@endunless

</body>
</html>
