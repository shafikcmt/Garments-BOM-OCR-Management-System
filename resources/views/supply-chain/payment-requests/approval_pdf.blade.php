<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 12px 14px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #000b6f; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .top td { border: 0; vertical-align: top; padding: 0; }
        .logo-text { font-size: 25px; letter-spacing: .09em; font-weight: 600; line-height: .95; }
        .logo-small { font-size: 7px; letter-spacing: .06em; font-weight: 700; padding-left: 48px; }
        .company { font-size: 8.5px; letter-spacing: .08em; font-weight: 700; margin-top: 10px; }
        .logo-img { height: 50px; max-width: 160px; object-fit: contain; }
        .title { text-align: center; font-size: 25px; font-weight: 800; line-height: 1; letter-spacing: .02em; }
        .request-no { text-align: center; font-size: 10.5px; margin-top: 6px; letter-spacing: .04em; }
        .date-area { text-align: right; font-size: 9px; font-weight: 700; line-height: 1.6; }
        .date-area .date-value { font-weight: 800; white-space: nowrap; }
        .info td { border: 0; vertical-align: top; padding: 12px 0 8px; }
        .buyer { font-size: 9px; line-height: 2.2; font-weight: 700; }
        .note { font-size: 8.5px; line-height: 1.45; font-weight: 700; padding-top: 8px; }
        .check { min-height: 78px; padding: 11px 12px; font-size: 8.5px; line-height: 1.75; }
        .mini-box { display: inline-block; width: 11px; height: 11px; border: 1px solid #91a1d0; vertical-align: middle; margin: 0 5px 0 13px; }
        .line { display: inline-block; border-bottom: 1px solid #4a5cb2; width: 132px; height: 10px; }
        .total { text-align: right; font-size: 12px; font-weight: 800; margin: 4px 0 7px; }
        .report { table-layout: fixed; color: #111827; }
        .report th { background:#000b6f; color:#fff; border:1px solid #33439e; padding: 6px 3px; font-size: 9px; line-height:1.2; font-weight:800; text-align:center; vertical-align:middle; }
        .report td { border:1px solid #e2e6ef; padding: 5px 4px; font-size: 9px; line-height:1.2; vertical-align:middle; word-break: break-word; }
        .center { text-align:center; } .right { text-align:right; }
        .grand td { background:#eaf0fb !important; color:#000b6f; font-size: 10px; font-weight:800; padding: 8px 5px; }
        .sign { margin-top: 34px; color:#000b6f; }
        .sign td { border:0; width:33.33%; padding:0 22px; vertical-align:bottom; }
        .sign td + td { border-left:1px solid #8d9bd3; }
        .sign-title { font-size:9px; font-weight:800; margin-bottom:25px; }
        .sign-text { font-size:9px; margin-bottom:25px; }
        .sign-line { border-bottom:1px solid #000b6f; height:1px; }
        .w-vendor { width: 13%; } .w-style { width: 9%; } .w-pcd { width: 9%; } .w-term { width: 9%; }
        .w-po { width: 11%; } .w-pi { width: 11%; } .w-type { width: 8%; } .w-cship { width: 9%; } .w-exmill { width: 9%; }
        .w-amount { width: 12%; }
    </style>
</head>
<body>
@php
    $approvalRows = collect($approvalRows ?? []);
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
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
        <td style="width:50%; padding-top:2px;">
            <div class="title">Payment Request Approval</div>
            <div class="request-no">{{ $paymentRequest->request_no }}</div>
        </td>
        <td style="width:25%;" class="date-area">
            Date:&nbsp;&nbsp; <span class="date-value">{{ optional($paymentRequest->created_at)->format('jS M-Y') }}</span><br><br>
            Payment Require Date:&nbsp;&nbsp; <span class="date-value">{{ $paymentRequiredDate ? optional($paymentRequiredDate)->format('jS M-Y') : '-' }}</span>
        </td>
    </tr>
</table>

<table class="info">
    <tr>
        <td style="width:65%;">
            <div class="buyer">
                Buyer&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: &nbsp;{{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}<br>
                Season&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: &nbsp;{{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}
            </div>
            <div class="note">* Buyer nominated supplier.<br>&nbsp;&nbsp;No excess quantity has been booked.</div>
        </td>
        <td style="width:35%;">
            <div class="check">
                <strong>OCR Checked:</strong> <span class="mini-box"></span> Yes <span class="mini-box"></span> No<br>
                Checker Name&nbsp;&nbsp;&nbsp;&nbsp;: <span class="line"></span><br>
                Date&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: <span class="line"></span>
            </div>
        </td>
    </tr>
</table>

<div class="total">Total PI Amount: $ {{ $money($summary['total_pi_amount'] ?? 0) }}</div>

<table class="report">
    <thead>
        <tr>
            <th class="w-vendor">Vendor</th>
            <th class="w-style">Style</th>
            <th class="w-pcd">PCD Date</th>
            <th class="w-term">Pay Term</th>
            <th class="w-po">PO No.</th>
            <th class="w-pi">PI No.</th>
            <th class="w-type">Type</th>
            <th class="w-cship">C. Shipment</th>
            <th class="w-exmill">Ex Mill</th>
            <th class="w-amount right">PI Amt (USD)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($approvalRows as $row)
            <tr>
                <td>{{ $row['vendor_name'] ?: '-' }}</td>
                <td>{{ $row['style'] ?: '-' }}</td>
                <td class="center">{{ $row['pcd_required'] ?: '-' }}</td>
                <td>{{ $row['payment_term'] ?: '-' }}</td>
                <td>{{ $row['material_po_number'] ?: '-' }}</td>
                <td>{{ $row['material_pi_number'] ?: '-' }}</td>
                <td>{{ $row['material_type'] ?: '-' }}</td>
                <td class="center">{{ $row['contract_shipment'] ?: '-' }}</td>
                <td class="center">{{ $row['committed_ex_mill'] ?: '-' }}</td>
                <td class="right">${{ $money($row['pi_amount'] ?? 0) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="center">No payment request item found.</td></tr>
        @endforelse
        <tr class="grand">
            <td colspan="9">Grand Total</td>
            <td class="right">${{ $money($summary['total_pi_amount'] ?? 0) }}</td>
        </tr>
    </tbody>
</table>

<table class="sign">
    <tr>
        <td style="padding-left:0;">
            <div class="sign-title">Prepared By</div>
            <div class="sign-text">Signature &amp; Date</div>
            <div class="sign-line"></div>
        </td>
        <td>
            <div class="sign-title">Checked By</div>
            <div class="sign-text">Signature &amp; Date</div>
            <div class="sign-line"></div>
        </td>
        <td style="padding-right:0;">
            <div class="sign-title">Approved By</div>
            <div class="sign-text">Signature &amp; Date</div>
            <div class="sign-line"></div>
        </td>
    </tr>
</table>
</body>
</html>
