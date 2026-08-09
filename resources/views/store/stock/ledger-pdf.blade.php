{{--
    Consumable Stock Report — PDF, and the source view for the Excel export
    ($forExcel). Header block, paper size, margins and the signature area follow
    the existing Store report PDF so the two print alike.
--}}
@php
    $forExcel = $forExcel ?? false;
    $activeFilters = collect([
        'Search' => $filters['search'] ?? null,
        'Category' => $filters['category'] ?? null,
        'Status' => ($filters['status'] ?? null) === 'attention'
            ? 'Needs Attention'
            : (($filters['status'] ?? null) ? \App\Services\GeneralStockReportService::statusLabels()[$filters['status']] : null),
    ])->filter()->map(fn ($v, $k) => $k.': '.$v)->implode('  |  ');
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 14px 16px 26px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #000b6f; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .top td { border: 0; vertical-align: top; padding: 0; }
        .logo-text { font-size: 25px; letter-spacing: .09em; font-weight: 600; line-height: .95; }
        .logo-small { font-size: 7px; letter-spacing: .06em; font-weight: 700; padding-left: 48px; }
        .company { font-size: 8.5px; letter-spacing: .08em; font-weight: 700; margin-top: 10px; }
        .logo-img { height: 50px; max-width: 160px; object-fit: contain; }
        .title { text-align: center; font-size: 21px; font-weight: 800; line-height: 1; letter-spacing: .02em; }
        .subtitle { text-align: center; font-size: 9px; margin-top: 6px; letter-spacing: .03em; }
        .date-area { text-align: right; font-size: 8.5px; font-weight: 700; line-height: 1.7; }
        .filters { margin: 10px 0 6px; font-size: 8.5px; font-weight: 700; letter-spacing: .02em; }
        .legend { font-size: 7.5px; font-weight: 600; color: #33439e; margin-bottom: 7px; line-height: 1.4; }

        /* 19 columns on A4 landscape: fixed layout so long consumable names
           wrap inside their cell instead of pushing columns off the page. */
        .report-table { table-layout: fixed; color: #111827; }
        .report-table th {
            background: #000b6f; color: #fff; border: 1px solid #33439e; padding: 4px 2px;
            font-size: 5.5px; line-height: 1.15; font-weight: 800; text-align: right; vertical-align: middle;
        }
        .report-table th.col-sl, .report-table th.col-flag { text-align: center; }
        .report-table th.col-name, .report-table th.col-txt,
        .report-table th.col-brand, .report-table th.col-size, .report-table th.col-spec { text-align: left; }
        .report-table td {
            border: 1px solid #e2e6ef; padding: 3px 2px; font-size: 6px; line-height: 1.2;
            vertical-align: middle; word-wrap: break-word; word-break: break-word;
        }
        .report-table td.num { text-align: right; }
        .report-table td.col-sl { text-align: center; }
        .report-table td.flag-order { background: #fde8e8; color: #9b1c1c; font-weight: 800; }
        .report-table td.empty { text-align: center; padding: 18px 4px; color: #4a5cb2; }
        .report-table tfoot .grand td {
            background: #eaf0fb; color: #000b6f; font-size: 7px; font-weight: 800; padding: 5px 3px;
        }
        /* 21 columns on A4 landscape. Widths are budgeted to total under 100%
           with table-layout:fixed, so a long item name or specification wraps
           inside its own cell — the row grows taller rather than pushing a
           column off the page. */
        /* 22 columns: 1 flag + 1 sl + name + brand + size + spec + 5 text + 11
           numeric. Balance B/F made an eleventh numeric column, so the budget
           was rebalanced: the two widest text columns gave up 1.5% between them
           rather than shrinking every figure column, because a wrapped item
           name is readable and a wrapped quantity is not.
           5 + 2 + 9.5 + 4.5 + 3 + 7 + (5 x 4) + (11 x 4.4) = 99.4%. */
        .report-table .col-flag { width: 5%; }
        .report-table .col-sl { width: 2%; }
        .report-table .col-name { width: 9.5%; }
        .report-table .col-brand { width: 4.5%; }
        .report-table .col-size { width: 3%; }
        .report-table .col-spec { width: 7%; }
        .report-table .col-txt { width: 4%; }
        .report-table th.num, .report-table td.num { width: 4.4%; }

        .sign { margin-top: 26px; color: #000b6f; }
        .sign td { border: 0; width: 33.33%; padding: 0 22px; vertical-align: bottom; }
        .sign td + td { border-left: 1px solid #8d9bd3; }
        .sign-title { font-size: 8.5px; font-weight: 800; margin-bottom: 26px; }
        .sign-line { border-bottom: 1px solid #000b6f; height: 1px; }
        .sign-meta { font-size: 8px; margin-top: 4px; text-align: center; }
        .footer { position: fixed; bottom: 6px; left: 0; right: 0; font-size: 7px; color: #4a5cb2; }
        .footer .right { float: right; }
    </style>
</head>
<body>
@unless($forExcel)
    @php
        $logoPath = public_path('images/humana-logo.png');
        $logoData = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;
    @endphp
    <table class="top">
        <tr>
            <td style="width:25%;">
                @if($logoData)
                    <img src="{{ $logoData }}" class="logo-img" alt="Humana">
                @else
                    <div class="logo-text">HUMANA</div>
                    <div class="logo-small">APPARELS PVT. LTD.</div>
                @endif
                <div class="company">HUMANA APPARELS PVT. LTD.</div>
            </td>
            <td style="width:50%; padding-top:4px;">
                <div class="title">{{ $title }}</div>
                <div class="subtitle">Department of Store &mdash; {{ $monthLabel }}</div>
            </td>
            <td style="width:25%;" class="date-area">
                <div>Printed On</div>
                <div>{{ now()->format('d M Y, h:i A') }}</div>
            </td>
        </tr>
    </table>

    <div class="filters">Month: {{ $monthLabel }}@if($activeFilters)  |  {{ $activeFilters }}@endif</div>
    <div class="legend">
        Stock as on Date = Opening Stock + Addition &minus; Consumption.
        "Place Order" is raised when Stock as on Date falls below the Safety Stock Level;
        "Low Stock" is the earlier warning at the Re-order Level.
    </div>
@else
    {{-- Excel letterhead, laid out the same way as the Purchase Requisition
         workbook so the two downloads look like one system. The colspans ARE
         the merges: the HTML reader turns them into merged cells. Type sizes,
         row heights and alignment are applied by FormatsStoreSheet.

         Same four heading lines the reference workbook prints above its Stock
         sheet — company, address, department, then the report and its month. --}}
    @php
        $columns = 22;
        $labelSpan = 5;
        $valueSpan = $columns - $labelSpan;
    @endphp
    <table>
        <tr><td colspan="{{ $columns }}">Humana Apparels (Pvt.) Ltd.</td></tr>
        <tr><td colspan="{{ $columns }}">{{ $title }}</td></tr>
        <tr>
            <td colspan="{{ $labelSpan }}">Address</td>
            <td colspan="{{ $valueSpan }}">Gorai, Mirzapur, Tangail.</td>
        </tr>
        <tr>
            <td colspan="{{ $labelSpan }}">Department</td>
            <td colspan="{{ $valueSpan }}">Department of Store.</td>
        </tr>
        <tr>
            <td colspan="{{ $labelSpan }}">Month</td>
            <td colspan="{{ $valueSpan }}">{{ $monthLabel }}</td>
        </tr>
        <tr><td colspan="{{ $columns }}"></td></tr>
    </table>
@endunless

@include('store.stock._report-table')

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
        Humana Apparels Pvt. Ltd. &mdash; {{ $title }}
        <span class="right">Generated {{ now()->format('d M Y') }}</span>
    </div>
@else
    {{-- Excel signature block, matching the Purchase Requisition workbook:
         three equal thirds of the table width with room to sign, the rule
         above the labels acting as the signature line, and the designation
         underneath. Styled by FormatsStoreSheet. --}}
    @php($third = (int) floor(22 / 3))
    <table>
        <tr><td colspan="22"></td></tr>
        <tr><td colspan="22"></td></tr>
        <tr><td colspan="22"></td></tr>
        <tr>
            <td colspan="{{ $third }}">Prepared by</td>
            <td colspan="{{ $third }}">Checked by</td>
            <td colspan="{{ 22 - 2 * $third }}">Approved by</td>
        </tr>
        <tr>
            <td colspan="{{ $third }}">Store</td>
            <td colspan="{{ $third }}">Store In-charge</td>
            <td colspan="{{ 22 - 2 * $third }}">Management</td>
        </tr>
    </table>
@endunless
</body>
</html>
