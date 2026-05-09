@php
    $items = $bookingData['items'] ?? [];
    $notes = $bookingData['notes'] ?? [];
    $qtyNumber = function ($value) { return (float) str_replace([',', ' '], '', (string) ($value ?? 0)); };
    $lineTotalQty = function ($item) use ($qtyNumber) { return $qtyNumber($item['booking_qty'] ?? 0) + $qtyNumber($item['pp_qty'] ?? 0); };
    $totalQty = collect($items)->sum(fn ($item) => $lineTotalQty($item));
    $revisionNo = max(0, (int) ($bookingData['revision_no'] ?? 0));
    $revisionLabel = $revisionNo > 0 ? 'R-' . $revisionNo : '';
    $deliveryDestinationName = trim((string) ($bookingData['delivery_destination_name'] ?? ''));
    $deliveryDestinationDetails = trim((string) ($bookingData['delivery_destination_details'] ?? ''));
    $hasDeliveryDestination = $deliveryDestinationName !== '' || $deliveryDestinationDetails !== '';
@endphp
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 10pt; }
        th, td { border: 1px solid #4b5563; padding: 5px; vertical-align: top; }
        th { background: #006C9D; color: #ffffff; font-weight: bold; }
        .remarks-col { min-width: 90px; width: 11%; }
        .title { font-size: 18pt; font-weight: bold; color: #004b70; }
        .sub { font-size: 12pt; font-weight: bold; color: #006C9D; }
        .label { background: #e0f2fe; font-weight: bold; color: #004b70; }
        .total { background: #e0f2fe; font-weight: bold; }
    </style>
</head>
<body>
<table>
    <colgroup>
        <col style="width:3%;">
        <col style="width:10%;">
        <col style="width:9%;">
        <col style="width:20%;">
        <col style="width:6%;">
        <col style="width:5%;">
        <col style="width:5%;">
        <col style="width:10%;">
        <col style="width:7%;">
        <col style="width:5%;">
        <col style="width:6%;">
        <col style="width:4%;">
        <col class="remarks-col" style="width:10%;">
    </colgroup>
    <tr><td colspan="13" class="title">HUMANA APPARELS PVT. LTD.</td></tr>
    <tr><td colspan="13" class="sub">PO/WO/ BOOKING - {{ $bookingPo->po_no }} @if($revisionLabel)({{ $revisionLabel }})@endif</td></tr>
    <tr>
        <td class="label">TO</td><td colspan="5">{{ $bookingData['to'] ?? '' }}</td>
        <td class="label">PO/WO/ NUMBER</td><td colspan="6">{{ $bookingData['po_number'] ?? $bookingPo->po_no }} @if($revisionLabel){{ $revisionLabel }}@endif</td>
    </tr>
    <tr>
        <td class="label">ATTN</td><td colspan="5">{{ $bookingData['attn'] ?? '' }}</td>
        <td class="label">DATE</td><td colspan="6">{{ $bookingData['date'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">E-MAIL</td><td colspan="5">{{ $bookingData['email'] ?? '' }}</td>
        <td class="label">BUYER</td><td colspan="6">{{ $bookingData['buyer'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">ADDRESS</td><td colspan="5">{!! nl2br(e($bookingData['address'] ?? '')) !!}</td>
        <td class="label">SEASON</td><td colspan="6">{{ $bookingData['season'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">FROM</td><td colspan="5">{{ $bookingData['from'] ?? '' }}</td>
        <td class="label">STYLE NO</td><td colspan="6"><strong>{{ $bookingData['order_style_no'] ?? '' }}</strong></td>
    </tr>
    <tr>
        <td class="label">INCOTERM</td><td colspan="5">{{ $bookingData['incoterm'] ?? '' }}</td>
        <td class="label">ITEM TYPE</td><td colspan="6">{{ $bookingData['item_type'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">SHIP MODE</td><td colspan="5">{{ $bookingData['ship_mode'] ?? '' }}</td>
        <td class="label">TOLERANCE %</td><td colspan="6">{{ $bookingData['tolerance'] ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">Consignee / Bill To</td>
        @if($hasDeliveryDestination)
            <td colspan="5">{!! nl2br(e($bookingData['consignee'] ?? '')) !!}</td>
            <td class="label">Delivery / Ship To</td>
            <td colspan="6">@if($deliveryDestinationName)<strong>{{ $deliveryDestinationName }}</strong><br>@endif{!! nl2br(e($deliveryDestinationDetails)) !!}</td>
        @else
            <td colspan="12">{!! nl2br(e($bookingData['consignee'] ?? '')) !!}</td>
        @endif
    </tr>
    <tr>
        <th>SL</th>
        <th>Style No</th>
        <th>Item</th>
        <th>Description</th>
        <th>Color</th>
        <th>Size</th>
        <th>Width</th>
        <th>Supplier / Article</th>
        <th>Booking Qty</th>
        <th>PP Qty</th>
        <th>Total Qty</th>
        <th>UOM</th>
        <th class="remarks-col">Remarks</th>
    </tr>
    @foreach($items as $index => $item)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $item['style_order'] ?? '' }}</td>
            <td>{{ $item['item_name'] ?? '' }}</td>
            <td>{{ $item['description'] ?? '' }}</td>
            <td>{{ $item['color'] ?? '' }}</td>
            <td>{{ $item['size'] ?? 'N/A' }}</td>
            <td>{{ $item['width'] ?? 'N/A' }}</td>
            <td>{{ $item['supplier_article'] ?? '' }}</td>
            <td>{{ $item['booking_qty'] ?? '' }}</td>
            <td>{{ $item['pp_qty'] ?? '' }}</td>
            <td>{{ number_format($lineTotalQty($item), 2) }}</td>
            <td>{{ $item['uom'] ?? '' }}</td>
            <td class="remarks-col">{{ $item['remarks'] ?? '' }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="10">Grand Total</td>
        <td>{{ number_format($totalQty, 2) }}</td>
        <td colspan="2"></td>
    </tr>
    <tr><td class="label" colspan="13">Notes / Instructions</td></tr>
    @foreach($notes as $index => $note)
        @if(trim((string) $note) !== '')
            <tr><td>{{ $index + 1 }}</td><td colspan="12">{{ $note }}</td></tr>
        @endif
    @endforeach
    <tr><td colspan="4" class="label">Prepared By</td><td colspan="5" class="label">Checked By</td><td colspan="4" class="label">Approved By</td></tr>
</table>
</body>
</html>
