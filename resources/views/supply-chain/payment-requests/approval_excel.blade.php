<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 10px; width: 100%; }
        th, td { border: 1px solid #b8c2cc; padding: 4px; vertical-align: top; }
        th { background: #d9dee8; color: #111827; font-weight: bold; }
        .no-border { border: 0; }
        .title { font-size: 16px; font-weight: bold; text-align: center; }
        .right { text-align: right; }
        .center { text-align: center; }
        .filter-title { background: #e5e7eb; font-weight: bold; }
        .grand { background: #eef2f7; font-weight: bold; }
    </style>
</head>
<body>
@php
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequired = $summary['earliest_payment_required_date'] ? optional($summary['earliest_payment_required_date'])->format('jS M-Y') : '-';
    $filters = [
        'Final Status' => $list($summary['final_statuses'] ?? []),
        'Vendor Type' => $list($summary['vendor_types'] ?? []),
        'Payment Term' => $list($summary['payment_terms'] ?? []),
        'Payment Status' => $list($summary['payment_statuses'] ?? []),
        'Vendor Name' => $list($summary['suppliers'] ?? [], $paymentRequest->supplier_name ?: '-'),
    ];
@endphp
<table>
    <tr>
        <td colspan="3" class="no-border"><strong>Humana</strong></td>
        <td colspan="6" class="no-border title">Payment Request Approval<br><span style="font-size:10px;color:#1d4ed8;">{{ $paymentRequest->request_no }}</span></td>
        <td colspan="3" class="no-border right"><strong>Date:</strong> {{ optional($paymentRequest->created_at)->format('jS M-Y') }}<br><strong>Payment Require Date:</strong> {{ $paymentRequired }}</td>
    </tr>
    <tr>
        <td colspan="4" class="no-border"><strong>Buyer:</strong> {{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}</td>
        <td colspan="4" class="no-border center"><strong>Season:</strong> {{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}</td>
        <td colspan="4" class="no-border right"><strong>Total PI Amount:</strong> {{ $money($summary['total_pi_amount'] ?? 0) }}</td>
    </tr>
    <tr>
        @foreach($filters as $label => $value)
            <td colspan="2" class="filter-title">{{ $label }}</td>
        @endforeach
        <td colspan="2" class="filter-title">Request No</td>
    </tr>
    <tr>
        @foreach($filters as $label => $value)
            <td colspan="2">{{ $value ?: '-' }}</td>
        @endforeach
        <td colspan="2">{{ $paymentRequest->request_no }}</td>
    </tr>
    <tr>
        <th>Vendor Name</th>
        <th>Style</th>
        <th>PCD Required</th>
        <th>Payment Term</th>
        <th>Material PO Number</th>
        <th>Season</th>
        <th>Material PI Number</th>
        <th>Material Type</th>
        <th>Payment Status</th>
        <th>Contract Shipment</th>
        <th>Committed Ex Mill</th>
        <th>PI Amount</th>
    </tr>
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
            <td class="right">{{ $money($item->pi_amount) }}</td>
        </tr>
    @endforeach
    <tr class="grand"><td colspan="11">Grand Total</td><td class="right">{{ $money($summary['total_pi_amount'] ?? 0) }}</td></tr>
    <tr><td colspan="12" class="no-border"></td></tr>
    <tr><td colspan="4"><strong>Prepared By</strong><br>Name: {{ optional($paymentRequest->createdBy)->name ?: '' }}</td><td colspan="4"><strong>Checked By</strong><br>Name: {{ optional($paymentRequest->checkedBy)->name ?: '' }}</td><td colspan="4"><strong>Approved By</strong><br>Name: {{ optional($paymentRequest->approvedBy)->name ?: '' }}</td></tr>
    <tr><td colspan="7" class="no-border">Buyer nominated supplier.<br>No excess quantity has been booked.</td><td colspan="5" class="no-border">OCR Checked: ☐ Yes ☐ No<br>Nominated Supplier: ☐ Yes ☐ No<br>Checker Name: __________________ Date: __________________</td></tr>
</table>
</body>
</html>
