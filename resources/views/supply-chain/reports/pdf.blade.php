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

        /* Shared report table, PDF skin — the same one the Store and
           Merchandising reports print. */
        .report-table { table-layout: fixed; color: #111827; }
        .report-table th {
            background: #000b6f; color: #fff; border: 1px solid #33439e; padding: 5px 3px;
            font-size: 7px; line-height: 1.2; font-weight: 800; text-align: left; vertical-align: middle;
        }
        .report-table th.num { text-align: right; }
        .report-table th.col-sl { text-align: center; }
        .report-table td {
            border: 1px solid #e2e6ef; padding: 4px 4px; font-size: 7.5px; line-height: 1.25;
            vertical-align: middle; word-wrap: break-word; word-break: break-word;
        }
        .report-table td.num { text-align: right; }
        .report-table td.col-sl { text-align: center; }
        .report-table td.empty { text-align: center; padding: 18px 4px; color: #4a5cb2; }

        .sign { margin-top: 30px; color: #000b6f; }
        .sign td { border: 0; width: 33.33%; padding: 0 22px; vertical-align: bottom; }
        .sign td + td { border-left: 1px solid #8d9bd3; }
        .sign-title { font-size: 8.5px; font-weight: 800; margin-bottom: 26px; }
        .sign-line { border-bottom: 1px solid #000b6f; height: 1px; }
        .sign-meta { font-size: 8px; margin-top: 4px; text-align: center; }
        .footer { position: fixed; bottom: 6px; left: 0; right: 0; font-size: 7px; color: #4a5cb2; }
        .footer .right { float: right; }

        /* Fixed widths, tuned so long buyer / style / material names wrap inside
           their cell instead of pushing the table off the page. Keys not listed
           fall back to an even share of what is left. */
        @php
            $widths = [
                'buyer_name' => 8,
                'style_name' => 8,
                'description' => 19,
                'material_po_number' => 9,
                'po_date' => 7,
                'materials_ordered' => 8,
                'material_order_status' => 10,
                'pi_status' => 8,
                'pi_amount' => 9,
                'arrival_status' => 8,
            ];
            $known = $columns->filter(fn ($c) => isset($widths[$c->header_key]));
            $used = 3 + $known->sum(fn ($c) => $widths[$c->header_key]);
            $unknownCount = $columns->count() - $known->count();
            $fallback = $unknownCount > 0 ? max(4, (100 - $used) / $unknownCount) : 0;
        @endphp
        .report-table .col-sl { width: 3%; }
        @foreach($columns as $column)
        .report-table .col-{{ $column->header_key }} { width: {{ round($widths[$column->header_key] ?? $fallback, 2) }}%; }
        @endforeach
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
            <div class="subtitle">Supply Chain &mdash; Ordering, PI &amp; Shipment Summary</div>
        </td>
        <td style="width:25%;" class="date-area">
            <div>Printed On</div>
            <div>{{ now()->format('d M Y, h:i A') }}</div>
        </td>
    </tr>
</table>

<div class="filters">@include('supply-chain.reports._filter-summary')</div>
<div class="legend">
    Summary columns only &mdash; {{ $rows->count() }} {{ Str::plural('BOM line', $rows->count()) }}.
    Buyer, Style and Description are Merchandising data, shown for reference.
    Download the Excel report for every Supply Chain column.
</div>

@include('supply-chain.reports._table')

<table class="sign">
    <tr>
        <td>
            <div class="sign-title">Prepared By</div>
            <div class="sign-line"></div>
            <div class="sign-meta">Supply Chain</div>
        </td>
        <td>
            <div class="sign-title">Checked By</div>
            <div class="sign-line"></div>
            <div class="sign-meta">Supply Chain Manager</div>
        </td>
        <td>
            <div class="sign-title">Approved By</div>
            <div class="sign-line"></div>
            <div class="sign-meta">Management</div>
        </td>
    </tr>
</table>

<div class="footer">
    Humana Apparels Pvt. Ltd. &mdash; Supply Chain Report
    <span class="right">Generated {{ now()->format('d M Y') }}</span>
</div>
</body>
</html>
