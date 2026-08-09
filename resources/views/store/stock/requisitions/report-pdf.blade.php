{{--
    Monthly Purchase Requisition report — PDF, and the source view for every
    sheet of the Excel export ($forExcel).

    Reproduces the "Month_Of_<Month>.xlsx" workbook: the company heading, the
    six-line header block, the two-tier column header, a Total- row and the
    Prepared by / Checked by / Approved by footer.

    The header block's "Requisition No" reads "Monthly — <Month Year>" because
    this page spans many requisitions rather than being one of them.

    $sections is a map of section name => rows. The PDF is handed every section
    (ALL first, then each category); one Excel sheet is handed exactly one.
--}}
@php
    $forExcel = $forExcel ?? false;

    $sections = $sections ?? array_merge(['ALL' => $rows], $byCategory->all());

    // Excel gets bare numbers and blank cells: a "1,175" carrying a thousands
    // separator arrives as TEXT, which cannot be summed, sorted or number
    // formatted, and a "-" placeholder would make a numeric column read as
    // text. The separators and dashes are Excel's job, applied by
    // FormatsStoreSheet. The PDF, which is just ink, keeps them here.
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

    $unit = config('stock.company_name');
    $headingNo = 'Monthly — '.$monthLabel;
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include("store.stock.requisitions._report-styles")
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
            <td class="label">Requisition No</td><td class="value">{{ $headingNo }}</td>
            <td class="label">Store Personnel</td><td class="value">Store &amp; Warehouse</td>
        </tr>
        <tr>
            <td class="label">Requisition Date</td><td class="value">{{ $monthLabel }}</td>
            <td class="label">Prepared By</td><td class="value">{{ optional(auth()->user())->name ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Name of Unit</td><td class="value">{{ $unit }}</td>
            <td class="label">Requisitions</td><td class="value">{{ $summary['requisitions'] }}</td>
        </tr>
        <tr>
            <td class="label">Section</td><td class="value">{{ $filters['category'] ?? 'ALL' }}</td>
            <td class="label">Item Lines</td><td class="value">{{ $summary['lines'] }}</td>
        </tr>
        <tr>
            <td class="label">Name of User</td><td class="value">ALL</td>
            <td class="label">Distinct Items</td><td class="value">{{ $summary['items'] }}</td>
        </tr>
        <tr>
            <td class="label">Listing</td>
            <td class="value">{{ $merge ? 'Merged — one line per item' : 'One line per requisition' }}</td>
            <td class="label">Grand Total</td><td class="value">{{ $money($summary['amount']) }}</td>
        </tr>
    </table>
@else
    {{-- Excel letterhead. The colspans ARE the merges: the HTML reader turns
         them into merged cells, giving the reference workbook's layout —
         company across the full width, title across the full width, then each
         information line as label (A:D) + value (E:M) with the store personnel
         block running down the right (N:U).

         Type sizes, row heights and alignment come from FormatsStoreSheet;
         HTML cannot carry them into a spreadsheet. --}}
    @php
        $signer = auth()->user();

        // Guarded rather than optional()-chained: optional() protects the first
        // call, but getRoleNames() would then return null and ->first() on null
        // is fatal. The export also has to survive being generated without a
        // session — a queued or scheduled run has no auth()->user().
        $signerRole = $signer && $signer->getRoleNames()->isNotEmpty()
            ? $signer->getRoleNames()->first()
            : 'Store';

        $columns = 21;
        $labelSpan = 4;
        $valueSpan = 9;
        $rightSpan = $columns - $labelSpan - $valueSpan;

        // Left column: what this document is. Right column: who in the store
        // stands behind it. Both read top to bottom, as on the paper form.
        $infoRows = [
            ['Requisition No', $headingNo, 'Store Personnel'],
            ['Requisition Date', $monthLabel, optional($signer)->name ?: '—'],
            ['Name of Unit', $unit, $signerRole],
            ['Section', array_key_first($sections), 'Store & Warehouse'],
            ['Name of User', 'ALL', optional($signer)->email ?: '—'],
            ['Listing', $merge ? 'Merged — one line per item' : 'One line per requisition', ''],
        ];
    @endphp
    <table>
        <tr><td colspan="{{ $columns }}">{{ $unit }}</td></tr>
        <tr><td colspan="{{ $columns }}">{{ $title }}</td></tr>
        @foreach($infoRows as [$label, $value, $right])
            <tr>
                <td colspan="{{ $labelSpan }}">{{ $label }}</td>
                <td colspan="{{ $valueSpan }}">{{ $value }}</td>
                <td colspan="{{ $rightSpan }}">{{ $right }}</td>
            </tr>
        @endforeach
        <tr><td colspan="{{ $columns }}"></td></tr>
    </table>
@endunless

@foreach($sections as $section => $sectionRows)
    @unless($forExcel)
        <div class="section-title {{ $loop->first ? '' : 'page-break' }}">
            {{ $section }} — {{ $sectionRows->count() }} line(s)
        </div>
    @endunless

    @include('store.stock.requisitions._report-rows', ['rows' => $sectionRows])
@endforeach

@unless($forExcel)
    <table class="sign">
        <tr>
            <td>
                <div class="sign-title">Prepared By</div>
                <div class="sign-line"></div>
                <div class="sign-meta">Store</div>
            </td>
            <td>
                <div class="sign-title">Checked By</div>
                <div class="sign-line"></div>
                <div class="sign-meta">Store In-charge</div>
            </td>
            <td>
                <div class="sign-title">Approved By</div>
                <div class="sign-line"></div>
                <div class="sign-meta">Management</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $unit }} &mdash; {{ $title }} &mdash; {{ $monthLabel }}
        <span class="right">Generated {{ now()->format('d M Y') }}</span>
    </div>
@else
    {{-- Excel signature block. Three equal thirds of the table width, so each
         has room to actually be signed; the rule above the labels is the
         signature line and the blank rows above it are the signing space.
         Bold, borders and heights are applied by FormatsStoreSheet. --}}
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
            <td colspan="{{ $third }}">Store</td>
            <td colspan="{{ $third }}">Store In-charge</td>
            <td colspan="{{ 21 - 2 * $third }}">Management</td>
        </tr>
    </table>
@endunless

</body>
</html>
