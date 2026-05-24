<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 10px 14px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.2px; color: #111827; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .accent { height: 4px; background: #1d4ed8; margin-bottom: 8px; }
        .no-border td { border: 0; }
        .top td { vertical-align: middle; border: 0; padding: 0 4px 5px; }
        .logo { height: 48px; max-width: 180px; object-fit: contain; }
        .title { text-align: center; font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: .02em; }
        .req { text-align: center; font-size: 9px; font-weight: 700; color: #1d4ed8; padding-top: 1px; }
        .meta { text-align: right; font-size: 8.5px; line-height: 1.35; font-weight: 700; }
        .buyer-line td { border-top: 1px solid #dbe3ee; border-bottom: 1px solid #dbe3ee; background:#f8fafc; padding: 4px 5px; font-size: 9.3px; font-weight: 800; }
        .buyer-line .center { text-align: center; }
        .buyer-line .right { text-align: right; }
        .filter-table { margin: 7px 0 7px; }
        .filter-table td { border: 1px solid #b8c2cc; padding: 3px 5px; vertical-align: top; }
        .filter-label { background: #e5e7eb; color: #111827; font-weight: 800; font-size: 7.5px; text-transform: uppercase; letter-spacing: .02em; }
        .filter-value { min-height: 16px; background: #fff; font-size: 7.6px; }
        .report { table-layout: fixed; }
        .report th { background: #1f2937; color:#fff; border: 1px solid #4b5563; padding: 3px 2px; font-size: 6.8px; font-weight: 800; text-align: left; line-height:1.1; text-transform:uppercase; }
        .report td { border: 1px solid #d1d5db; padding: 3px 2px; font-size: 7px; line-height: 1.15; vertical-align: top; word-break: break-word; }
        .report tbody tr:nth-child(even) td { background: #f8fafc; }
        .right { text-align: right; }
        .center { text-align: center; }
        .grand td { background: #eef2f7 !important; border-top: 2px solid #6b7280; font-weight: 800; }
        .sign { margin-top: 30px; }
        .sign td { border: 0; width: 33.33%; padding: 0 18px 0 0; vertical-align: bottom; }
        .line { border-top: 1px solid #111827; padding-top: 4px; font-weight: 800; }
        .name { color: #4b5563; padding-top: 4px; font-size: 7.8px; }
        .bottom { margin-top: 12px; }
        .bottom td { border: 0; vertical-align: top; }
        .note { font-size: 11.5px; line-height: 1.45; padding-top: 2px; }
        .check { color: #1d4ed8; font-size: 9px; line-height: 1.55; }
        .box { display: inline-block; width: 10px; height: 10px; border: 1px solid #2563eb; margin: 0 3px 0 8px; vertical-align: middle; }
        .w-vendor { width: 11%; } .w-style { width: 8%; } .w-date { width: 8%; } .w-term { width: 10%; }
        .w-po { width: 11%; } .w-season { width: 6%; } .w-pi { width: 11%; } .w-type { width: 8%; }
        .w-status { width: 9%; } .w-amount { width: 9%; }
    </style>
</head>
<body>
@php
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequired = $summary['earliest_payment_required_date'] ? optional($summary['earliest_payment_required_date'])->format('jS M-Y') : '-';
    $logoPath = public_path('images/humana-logo.png');
    $logoData = null;
    if (file_exists($logoPath)) {
        $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        if (function_exists('imagecreatefrompng') && function_exists('imagecrop')) {
            $src = @imagecreatefrompng($logoPath);
            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $crop = @imagecrop($src, ['x' => 0, 'y' => max(0, (int) ($h * .22)), 'width' => $w, 'height' => min($h, (int) ($h * .58))]);
                if ($crop) {
                    imagesavealpha($crop, true);
                    ob_start(); imagepng($crop); $png = ob_get_clean();
                    $logoData = 'data:image/png;base64,' . base64_encode($png);
                    imagedestroy($crop);
                }
                imagedestroy($src);
            }
        }
    }
    $filters = [
        'Final Status' => $list($summary['final_statuses'] ?? []),
        'Vendor Type' => $list($summary['vendor_types'] ?? []),
        'Payment Term' => $list($summary['payment_terms'] ?? []),
        'Payment Status' => $list($summary['payment_statuses'] ?? []),
        'Vendor Name' => $list($summary['suppliers'] ?? [], $paymentRequest->supplier_name ?: '-'),
    ];
@endphp
<div class="accent"></div>
<table class="top">
    <tr>
        <td style="width:24%;">@if($logoData)<img src="{{ $logoData }}" class="logo" alt="Logo">@endif</td>
        <td style="width:46%;">
            <div class="title">Payment Request Approval</div>
            <div class="req">{{ $paymentRequest->request_no }}</div>
        </td>
        <td style="width:30%;" class="meta">
            Date: {{ optional($paymentRequest->created_at)->format('jS M-Y') }}<br>
            Payment Require Date: {{ $paymentRequired }}
        </td>
    </tr>
</table>
<table class="buyer-line">
    <tr>
        <td style="width:33%;">Buyer: {{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}</td>
        <td style="width:34%;" class="center">Season: {{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}</td>
        <td style="width:33%;" class="right">Total PI Amount: ${{ $money($summary['total_pi_amount'] ?? 0) }}</td>
    </tr>
</table>
<table class="filter-table">
    <tr>
        @foreach($filters as $label => $value)
            <td class="filter-label">{{ $label }}</td>
        @endforeach
    </tr>
    <tr>
        @foreach($filters as $label => $value)
            <td class="filter-value">{{ $value ?: '-' }}</td>
        @endforeach
    </tr>
</table>
<table class="report">
    <thead>
        <tr>
            <th class="w-vendor">Vendor Name</th>
            <th class="w-style">Style</th>
            <th class="w-date">PCD Required</th>
            <th class="w-term">Payment Term</th>
            <th class="w-po">Material PO Number</th>
            <th class="w-season">Season</th>
            <th class="w-pi">Material PI Number</th>
            <th class="w-type">Material Type</th>
            <th class="w-status">Payment Status</th>
            <th class="w-date">Contract Shipment</th>
            <th class="w-date">Committed Ex Mill</th>
            <th class="w-amount right">PI Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($paymentRequest->items as $item)
            <tr>
                <td>{{ $item->supplier_name ?: '-' }}</td>
                <td>{{ $item->style_name ?: '-' }}</td>
                <td class="center">{{ data_get($item->data, 'pcd_required') ?: '-' }}</td>
                <td>{{ $item->payment_term ?: '-' }}</td>
                <td>{{ $item->po_no ?: '-' }}</td>
                <td>{{ $item->season_name ?: '-' }}</td>
                <td>{{ $item->pi_number ?: '-' }}</td>
                <td>{{ data_get($item->data, 'material_type') ?: '-' }}</td>
                <td>{{ $item->payment_status ?: '-' }}</td>
                <td class="center">{{ data_get($item->data, 'contract_shipment') ?: '-' }}</td>
                <td class="center">{{ data_get($item->data, 'committed_ex_mill') ?: '-' }}</td>
                <td class="right">${{ $money($item->pi_amount) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td colspan="11">Grand Total</td>
            <td class="right">${{ $money($summary['total_pi_amount'] ?? 0) }}</td>
        </tr>
    </tbody>
</table>
<table class="sign">
    <tr>
        <td><div class="line">Prepared By</div><div class="name">Name: {{ optional($paymentRequest->createdBy)->name ?: '' }}</div></td>
        <td><div class="line">Checked By</div><div class="name">Name: {{ optional($paymentRequest->checkedBy)->name ?: '' }}</div></td>
        <td style="padding-right:0;"><div class="line">Approved By</div><div class="name">Name: {{ optional($paymentRequest->approvedBy)->name ?: '' }}</div></td>
    </tr>
</table>
<table class="bottom">
    <tr>
        <td style="width:58%;" class="note">Buyer nominated supplier.<br>No excess quantity has been booked.</td>
        <td style="width:42%;" class="check">
            OCR Checked <span class="box"></span>Yes <span class="box"></span>No<br>
            Nominated Supplier <span class="box"></span>Yes <span class="box"></span>No<br>
            Checker Name : ___________________________<br>
            Date&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: ___________________________
        </td>
    </tr>
</table>
</body>
</html>
