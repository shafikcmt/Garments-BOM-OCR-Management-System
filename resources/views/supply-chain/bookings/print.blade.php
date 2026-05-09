@php
    $items = $bookingData['items'] ?? [];
    $notes = $bookingData['notes'] ?? [];
    $qtyNumber = function ($value) { return (float) str_replace([',', ' '], '', (string) ($value ?? 0)); };
    $lineTotalQty = function ($item) use ($qtyNumber) { return $qtyNumber($item['booking_qty'] ?? 0) + $qtyNumber($item['pp_qty'] ?? 0); };
    $totalQty = collect($items)->sum(fn ($item) => $lineTotalQty($item));
    $revisionNo = max(0, (int) ($bookingData['revision_no'] ?? 0));
    $revisionLabel = $revisionNo > 0 ? 'R-' . $revisionNo : '';
    $logoSrc = ($isPdf ?? false) ? public_path('images/humana-logo.png') : asset('images/humana-logo.png');
    $deliveryDestinationName = trim((string) ($bookingData['delivery_destination_name'] ?? ''));
    $deliveryDestinationDetails = trim((string) ($bookingData['delivery_destination_details'] ?? ''));
    $hasDeliveryDestination = $deliveryDestinationName !== '' || $deliveryDestinationDetails !== '';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PO/WO/ Booking - {{ $bookingPo->po_no }}</title>
    <style>
        @page { size: A4 portrait; margin: 6mm; }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 7.2px;
            line-height: 1.2;
        }
        body { background: #e5e7eb; }
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            text-align: center;
            padding: 10px;
            background: #0f172a;
        }
        .print-toolbar button,
        .print-toolbar a {
            display: inline-block;
            margin: 0 4px;
            border: 0;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
            background: #006C9D;
            text-decoration: none;
            cursor: pointer;
        }
        .print-toolbar a { background: #475569; }
        .sheet {
            width: 198mm;
            min-height: 285mm;
            margin: 10px auto;
            background: #fff;
            border: 1px solid #d1d5db;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .16);
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .top-table { margin-bottom: 3mm; border-bottom: 1.4px solid #006C9D; }
        .top-table td { border: 0; padding: 2.6mm 1.5mm 3mm; vertical-align: middle; }
        .logo { width: 34mm; height: auto; display: block; }
        .company-title {
            margin: 0;
            color: #004b70;
            font-size: 13.5px;
            line-height: 1.05;
            letter-spacing: 1.1px;
            font-weight: 900;
            text-transform: uppercase;
        }
        .company-small { margin: 1.2px 0 0; color: #0f172a; font-size: 6.5px; line-height: 1.18; }
        .format-badge {
            border: 1.5px solid #006C9D;
            border-radius: 7px;
            color: #006C9D;
            font-size: 10.5px;
            letter-spacing: .6px;
            font-weight: 900;
            text-align: center;
            padding: 4mm 2mm;
            text-transform: uppercase;
        }
        .sub-title {
            margin: 0 0 1.5mm;
            color: #0f172a;
            font-size: 7px;
            font-weight: 800;
            text-align: center;
        }
        .info-table { border: 1px solid #94a3b8; margin-bottom: 2mm; }
        .info-table th,
        .info-table td {
            border: 1px solid #d4dce7;
            padding: 1mm 1.1mm;
            height: 4.8mm;
            vertical-align: middle;
            overflow-wrap: anywhere;
        }
        .info-table th {
            width: 20mm;
            background: #e0f2fe;
            color: #004b70;
            font-size: 7px;
            font-weight: 900;
            text-align: left;
            text-transform: uppercase;
        }
        .info-table td { font-size: 7px; }
        .style-value { font-size: 8px; font-weight: 900; color: #004b70; letter-spacing: .2px; }
        .revision-pill { display: inline-block; margin-left: 3mm; padding: .7mm 1.8mm; border-radius: 999px; background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; font-weight: 900; font-size: 6px; line-height: 1; white-space: nowrap; text-transform: uppercase; }
        .line-style { font-weight: 900; color: #004b70; }
        .consignee-table { border: 1px solid #94a3b8; margin-bottom: 2mm; }
        .consignee-table th,
        .consignee-table td { border: 1px solid #94a3b8; padding: 1.4mm; vertical-align: middle; }
        .consignee-table th {
            width: 32mm;
            background: #006C9D;
            color: #fff;
            font-size: 7px;
            line-height: 1.15;
            text-align: left;
        }
        .consignee-table td { white-space: pre-line; font-size: 7px; font-weight: 700; }
        .booking-table { margin-top: 0; table-layout: fixed; width: 100%; max-width: 100%; }
        .booking-table th,
        .booking-table td {
            box-sizing: border-box;
            border: 1px solid #8aa2b8;
            padding: .75mm .45mm;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .booking-table th {
            background: #006C9D;
            color: #fff;
            border-color: #004b70;
            font-size: 5.7px;
            line-height: 1.08;
            font-weight: 900;
            text-align: center;
        }
        .booking-table td { font-size: 5.8px; line-height: 1.12; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .grand-row td { background: #e0f2fe; color: #003f5f; font-weight: 900; }
        .notes-box { border: 1px solid #94a3b8; margin-top: 2mm; }
        .notes-box h3 {
            margin: 0;
            padding: 1.2mm 1.6mm;
            background: #006C9D;
            color: #fff;
            font-size: 8px;
            line-height: 1;
        }
        .notes-box ol {
            margin: 1.5mm 3.5mm 2mm 5.5mm;
            padding: 0;
        }
        .notes-box li { margin: 0 0 .7mm; font-size: 6.8px; }
        .sign-table { width: 100%; margin-top: 8mm; table-layout: fixed; }
        .sign-table td { border: 0; padding: 0 6mm; vertical-align: bottom; text-align: center; }
        .sign-box { width: 100%; font-size: 7.2px; color: #111827; font-weight: 900; }
        .sign-line { width: 46mm; margin: 9mm auto 0; border-top: 1px solid #111827; height: 1mm; }
        .footer-note { margin-top: 2mm; font-size: 5.7px; color: #64748b; text-align: center; }
        .page-pad { padding: 0; }
        @media print {
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet {
                width: 198mm;
                min-height: auto;
                margin: 0;
                border: 0;
                box-shadow: none;
            }
            .page-pad { padding: 0; }
        }
    </style>
</head>
<body>
@if(!($isPdf ?? false))
    <div class="print-toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
        <a href="{{ route('supply_chain.bookings.download', $bookingPo) }}">Download PDF</a>
        <a href="{{ route('supply_chain.bookings.download_excel', $bookingPo) }}">Download Excel</a>
        <a href="{{ route('supply_chain.bookings.index') }}">Back to Booking List</a>
    </div>
@endif

<div class="sheet">
    <div class="page-pad">
        <table class="top-table">
            <tr>
                <td style="width:38mm;"><img class="logo" src="{{ $logoSrc }}" alt="Humana Apparels Logo"></td>
                <td>
                    <h1 class="company-title">HUMANA APPARELS PVT. LTD.</h1>
                    <p class="company-small">Bill &amp; Ship To - Humana Apparels Private Limited</p>
                    <p class="company-small">Momin Nagar, Gorai, Mirzapur, Tangail - 1941, Bangladesh</p>
                </td>
                <td style="width:44mm;"><div class="format-badge">PO/WO/ Booking</div></td>
            </tr>
        </table>

        <table class="info-table">
            <tr><th>TO</th><td>{{ $bookingData['to'] ?? '' }}</td><th>PO/WO/ NUMBER</th><td>{{ $bookingData['po_number'] ?? $bookingPo->po_no }} @if($revisionLabel)<span class="revision-pill">{{ $revisionLabel }}</span>@endif</td></tr>
            <tr><th>ATTN</th><td>{{ $bookingData['attn'] ?? '' }}</td><th>DATE</th><td>{{ $bookingData['date'] ?? '' }}</td></tr>
            <tr><th>E-MAIL</th><td>{{ $bookingData['email'] ?? '' }}</td><th>BUYER</th><td>{{ $bookingData['buyer'] ?? '' }}</td></tr>
            <tr><th>ADDRESS</th><td>{{ $bookingData['address'] ?? '' }}</td><th>SEASON</th><td>{{ $bookingData['season'] ?? '' }}</td></tr>
            <tr><th>FROM</th><td>{{ $bookingData['from'] ?? '' }}</td><th>STYLE NO</th><td class="style-value">{{ $bookingData['order_style_no'] ?? '' }}</td></tr>
            <tr><th>INCOTERM</th><td>{{ $bookingData['incoterm'] ?? '' }}</td><th>ITEM TYPE</th><td>{{ $bookingData['item_type'] ?? '' }}</td></tr>
            <tr><th>SHIP MODE</th><td>{{ $bookingData['ship_mode'] ?? '' }}</td><th>TOLERANCE %</th><td>{{ $bookingData['tolerance'] ?? '' }}</td></tr>
        </table>

        <table class="consignee-table">
            <tr>
                <th>Consignee / Bill To</th>
                @if($hasDeliveryDestination)
                    <td>{{ $bookingData['consignee'] ?? '' }}</td>
                    <th>Delivery / Ship To</th>
                    <td>@if($deliveryDestinationName)<strong>{{ $deliveryDestinationName }}</strong>
@endif{{ $deliveryDestinationDetails }}</td>
                @else
                    <td colspan="3">{{ $bookingData['consignee'] ?? '' }}</td>
                @endif
            </tr>
        </table>

        <table class="booking-table">
            <thead>
                <tr>
                    <th style="width:5mm;">SL</th>
                    <th style="width:19mm;">Style No</th>
                    <th style="width:17mm;">Item</th>
                    <th style="width:38mm;">Description</th>
                    <th style="width:12mm;">Color</th>
                    <th style="width:9mm;">Size</th>
                    <th style="width:10mm;">Width</th>
                    <th style="width:19mm;">Supplier / Article</th>
                    <th style="width:13mm;">Booking Qty</th>
                    <th style="width:9mm;">PP Qty</th>
                    <th style="width:12mm;">Total Qty</th>
                    <th style="width:7mm;">UOM</th>
                    <th style="width:20mm;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $index => $item)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td class="line-style">{{ $item['style_order'] ?? '' }}</td>
                        <td>{{ $item['item_name'] ?? '' }}</td>
                        <td>{{ $item['description'] ?? '' }}</td>
                        <td>{{ $item['color'] ?? '' }}</td>
                        <td>{{ $item['size'] ?? 'N/A' }}</td>
                        <td>{{ $item['width'] ?? 'N/A' }}</td>
                        <td>{{ $item['supplier_article'] ?? '' }}</td>
                        <td class="text-right">{{ $item['booking_qty'] ?? '' }}</td>
                        <td class="text-right">{{ $item['pp_qty'] ?? '' }}</td>
                        <td class="text-right">{{ number_format($lineTotalQty($item), 2) }}</td>
                        <td class="text-center">{{ $item['uom'] ?? '' }}</td>
                        <td>{{ $item['remarks'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="text-center" colspan="13">No item found.</td>
                    </tr>
                @endforelse
                <tr class="grand-row">
                    <td colspan="10" class="text-right">Grand Total</td>
                    <td class="text-right">{{ $totalQty ? number_format($totalQty, 2) : '' }}</td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>

        <div class="notes-box">
            <h3>Notes / Instructions</h3>
            <ol>
                @foreach($notes as $note)
                    @if(trim((string) $note) !== '')
                        <li>{{ $note }}</li>
                    @endif
                @endforeach
            </ol>
        </div>

        <table class="sign-table">
            <tr>
                <td>
                    <div class="sign-box">
                        <div>Prepared By</div>
                        <div class="sign-line"></div>
                    </div>
                </td>
                <td>
                    <div class="sign-box">
                        <div>Checked By</div>
                        <div class="sign-line"></div>
                    </div>
                </td>
                <td>
                    <div class="sign-box">
                        <div>Approved By</div>
                        <div class="sign-line"></div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="footer-note">Generated from HAPL OCR Supply Chain Booking Generate module - PO {{ $bookingPo->po_no }}</div>
    </div>
</div>

@if(($autoPrint ?? false) && !($isPdf ?? false))
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
@endif
</body>
</html>
