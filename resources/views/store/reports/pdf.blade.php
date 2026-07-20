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

        /* Shared report table, PDF skin. */
        .report-table { table-layout: fixed; color: #111827; }
        .report-table th {
            background: #000b6f; color: #fff; border: 1px solid #33439e; padding: 5px 3px;
            font-size: 7.5px; line-height: 1.2; font-weight: 800; text-align: right; vertical-align: middle;
        }
        .report-table th.col-sl, .report-table th.col-group { text-align: center; }
        .report-table th .sub { display: block; font-size: 6px; font-weight: 600; color: #c7d0f0; margin-top: 1px; }
        .report-table td {
            border: 1px solid #e2e6ef; padding: 4px 4px; font-size: 8px; line-height: 1.25;
            vertical-align: middle; word-wrap: break-word; word-break: break-word;
        }
        .report-table td.num { text-align: right; }
        .report-table td.col-sl { text-align: center; }
        .report-table td.empty { text-align: center; padding: 18px 4px; color: #4a5cb2; }
        .report-table tfoot .grand td {
            background: #eaf0fb; color: #000b6f; font-size: 8.5px; font-weight: 800; padding: 6px 4px;
        }
        /* Column widths tuned so long buyer / style / material names wrap instead of overflowing. */
        .report-table .col-sl { width: 4%; }
        .report-table .col-group { width: 22%; text-align: left; }
        .report-table th.num, .report-table td.num { width: 8.2%; }

        .sign { margin-top: 30px; color: #000b6f; }
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
            <div class="subtitle">Store &mdash; Material Receive &amp; Issue Summary</div>
        </td>
        <td style="width:25%;" class="date-area">
            <div>Printed On</div>
            <div>{{ now()->format('d M Y, h:i A') }}</div>
        </td>
    </tr>
</table>

<div class="filters">@include('store.reports._filter-summary')</div>
<div class="legend">
    Period Movement = Receive &minus; Issue for the selected date range.
    Current Stock Balance is the lifetime ledger closing quantity and is not affected by the date filter.
</div>

@include('store.reports._table')

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
    Humana Apparels Pvt. Ltd. &mdash; Store Stock Report
    <span class="right">Generated {{ now()->format('d M Y') }}</span>
</div>
</body>
</html>
