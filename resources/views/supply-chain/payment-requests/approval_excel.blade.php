<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 10px; width: 100%; color:#000b6f; }
        th, td { border: 1px solid #e2e6ef; padding: 6px; vertical-align: middle; }
        th { background: #000b6f; color: #ffffff; font-weight: bold; text-align: center; }
        .no-border { border: 0; }
        .title { font-size: 20px; font-weight: bold; text-align: center; }
        .request { font-size: 10px; text-align: center; }
        .right { text-align: right; }
        .center { text-align: center; }
        .grand { background: #eaf0fb; font-weight: bold; font-size: 12px; }
        .box { border: 1px solid #8da0d8; text-align: center; font-weight: bold; }
        .signature { height: 72px; vertical-align: bottom; color:#000b6f; }
    </style>
</head>
<body>
@php
    $approvalRows = collect($approvalRows ?? []);
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
    $paymentRequiredParts = $paymentRequiredDate
        ? [optional($paymentRequiredDate)->format('d'), optional($paymentRequiredDate)->format('m'), optional($paymentRequiredDate)->format('Y')]
        : ['--', '--', '----'];
@endphp
<table>
    <tr>
        <td colspan="2" rowspan="3" class="no-border"><strong style="font-size:18px;letter-spacing:2px;">HUMANA</strong><br><small>APPARELS PVT. LTD.</small><br><br><strong>HUMANA APPARELS PVT. LTD.</strong></td>
        <td colspan="6" class="no-border title">Payment Request Approval</td>
        <td colspan="3" class="no-border right"><strong>Date:</strong> {{ optional($paymentRequest->created_at)->format('jS M-Y') }}</td>
    </tr>
    <tr>
        <td colspan="6" class="no-border request">{{ $paymentRequest->request_no }}</td>
        <td colspan="3" class="no-border right"><strong>Payment Require Date:</strong></td>
    </tr>
    <tr>
        <td colspan="6" class="no-border"></td>
        <td class="box">{{ $paymentRequiredParts[0] }}</td>
        <td class="box">{{ $paymentRequiredParts[1] }}</td>
        <td class="box">{{ $paymentRequiredParts[2] }}</td>
    </tr>
    <tr>
        <td colspan="5" class="no-border"><strong>Buyer</strong> &nbsp; : &nbsp; {{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}<br><strong>Season</strong> : &nbsp; {{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}<br><br>* Buyer nominated supplier.<br>&nbsp;&nbsp;No excess quantity has been booked.</td>
        <td colspan="3" class="no-border"></td>
        <td colspan="3"><strong>OCR Checked:</strong> □ Yes &nbsp;&nbsp; □ No<br><br>Checker Name : __________________<br><br>Date : __________________</td>
    </tr>
    <tr>
        <td colspan="8" class="no-border"></td>
        <td colspan="3" class="no-border right"><strong>Total PI Amount: $ {{ $money($summary['total_pi_amount'] ?? 0) }}</strong></td>
    </tr>
    <tr>
        <th>Vendor Name</th>
        <th>Style</th>
        <th>PCD Required</th>
        <th>Payment Term</th>
        <th>Material PO Number</th>
        <th>Material PI Number</th>
        <th>Material Type</th>
        <th>Contract Shipment</th>
        <th>Committed Ex Mill</th>
        <th>Comments</th>
        <th>PI Amount (USD)</th>
    </tr>
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
            <td>{{ $row['comments'] ?: '(blank)' }}</td>
            <td class="right">{{ $money($row['pi_amount'] ?? 0) }}</td>
        </tr>
    @empty
        <tr><td colspan="11" class="center">No payment request item found.</td></tr>
    @endforelse
    <tr class="grand"><td colspan="10">Grand Total</td><td class="right">{{ $money($summary['total_pi_amount'] ?? 0) }}</td></tr>
    <tr><td colspan="11" class="no-border"></td></tr>
    <tr>
        <td colspan="3" class="signature"><strong>Prepared By</strong><br><br>Signature &amp; Date<br>________________________</td>
        <td colspan="1" class="no-border"></td>
        <td colspan="3" class="signature"><strong>Checked By</strong><br><br>Signature &amp; Date<br>________________________</td>
        <td colspan="1" class="no-border"></td>
        <td colspan="3" class="signature"><strong>Approved By</strong><br><br>Signature &amp; Date<br>________________________</td>
    </tr>
</table>
</body>
</html>
