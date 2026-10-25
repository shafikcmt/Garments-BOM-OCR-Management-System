{{--
    One Purchase Requisition as its own document — PDF, and the source view for
    the single-sheet Excel export ($forExcel).

    Same letterhead, same header block, same two-tier item table and the same
    signature footer as the monthly report, scoped to a single requisition
    instead of a whole month. The item table itself is the shared _report-rows
    partial, and the footer is the shared _signature-block partial fed from
    Admin > Settings > Requisition Signatures, so this page can never disagree
    with the monthly report or the screen.

    Expects: $requisition, $rows, $title, $signatures.
--}}
@php
    $forExcel = $forExcel ?? false;

    // Same reasoning as the monthly report: Excel gets bare numbers and blank
    // cells so a column stays numeric, the PDF keeps separators and dashes
    // because it is only ink. See report-pdf.blade.php.
    $qty = $forExcel
        ? fn ($v) => \App\Support\Quantity::plain($v)
        : fn ($v) => \App\Support\Quantity::format($v, '-');

    $money = $forExcel
        ? fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '')
        : fn ($v) => $v === null ? '-' : number_format((float) $v, 2);

    $date = $forExcel
        ? fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : ''
        : fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d-M-y') : '-';

    $typeLabels = \App\Models\PurchaseRequisitionItem::typeLabels();

    // The header values: the snapshot taken at the last submit, or — for a
    // sheet raised before snapshots — its own saved columns, exactly as
    // printed before. See RequisitionHeaderResolver.
    $header = app(\App\Services\RequisitionHeaderResolver::class)->forDocument($requisition, $departmentHeadName ?? null);

    // The letterhead always carries the company name; "Name of Unit" is the
    // header value, falling back to the company name as it always has.
    $company = config('stock.company_name');
    $unit = $header['unit_name'] ?: $company;
    // An old sheet keeps the letterhead it always printed (its own unit).
    $letterhead = $header['snapshot'] ? $company : $unit;

    $requisitionDate = optional($requisition->requisition_date)->format('d F Y') ?: '-';
    $section = $header['section'] ?: 'All';

    // Left column: what the document is. Right column: who handled it — one
    // line per configured signature box, reading name · date once the
    // system recorded the step, the configured name for a box signed on
    // paper, and Pending for a step still to come. Both columns read top to
    // bottom, as on the paper form, and both feed the PDF header block and
    // the Excel letterhead below.
    $signatures = $signatures ?? [];

    $leftRows = [
        ['Requisition No', $requisition->requisition_no],
        ['Requisition Date', $requisitionDate],
        ['Name of Unit', $unit],
        ['Section', $section],
        ['Name of User', $header['user_name'] ?: '-'],
        ['Contact No. / Email', $header['contact'] ?: '-'],
        // A department's sheet says where it is in the department's words
        // ("With Department Head"); the Store's own keeps the status name.
        ['Status', $requisition->isDepartmentLevel() ? $requisition->progressLabel() : $requisition->statusLabel()],
    ];

    $rightRows = [
        ['Type', \App\Models\PurchaseRequisition::modeLabels()[$requisition->mode] ?? $requisition->mode],
    ];
    foreach ($signatures as $box) {
        $rightRows[] = [
            $box['label'].($box['title'] !== '' ? ' ('.$box['title'].')' : ''),
            $box['signed_at']
                ? $box['name'].' · '.$box['signed_at']->format('d-M-Y')
                : ($box['stamped'] ? 'Pending' : ($box['name'] !== '' ? $box['name'] : '-')),
        ];
    }
    $rightRows[] = ['Total Amount', $money($rows->sum('amount'))];

    // Pad the shorter column so the two sides stay paired row for row.
    $lines = max(count($leftRows), count($rightRows));
    $infoRows = [];
    for ($i = 0; $i < $lines; $i++) {
        $infoRows[] = array_merge($leftRows[$i] ?? ['', ''], $rightRows[$i] ?? ['', '']);
    }
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
                <div class="company">{{ $letterhead }}</div>
                <div class="title">{{ $title }}</div>
            </td>
            <td style="width:25%;" class="date-area">
                <div>Printed On</div>
                <div>{{ \App\Support\LocalTime::now()->format('d M Y, h:i A') }}</div>
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
        <tr><td colspan="{{ $columns }}">{{ $letterhead }}</td></tr>
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
])

@unless($forExcel)
    @if($requisition->remarks)
        <div class="note"><strong>Remarks:</strong> {{ $requisition->remarks }}</div>
    @endif

    <div class="note">
        Stock, safety, consumption and last purchase are the figures as at
        {{ $requisitionDate }} — frozen when this requisition was raised.
    </div>

    {{-- The manual approval area. This sheet is printed and signed off-system —
         approval is a signature on paper, not a click — so each box carries
         who, the ruled line they sign on, and the date they signed. Which
         boxes, and whose names, come from Admin > Settings > Requisition
         Signatures; nothing here is typed in. --}}
    @include('store.stock.requisitions._signature-block', ['signatures' => $signatures, 'forExcel' => false])

    <div class="footer">
        {{ $letterhead }} &mdash; {{ $title }} &mdash; {{ $requisition->requisition_no }}
        <span class="right">Generated {{ \App\Support\LocalTime::now()->format('d M Y') }}</span>
    </div>
@else
    @include('store.stock.requisitions._signature-block', ['signatures' => $signatures, 'forExcel' => true, 'columns' => 21])
@endunless

</body>
</html>
