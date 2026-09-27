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
        <td colspan="4" class="no-border right"><strong>Date:</strong> {{ optional($paymentRequest->created_at)->format('jS M-Y') }}</td>
    </tr>
    <tr>
        <td colspan="6" class="no-border request">{{ $paymentRequest->request_no }}</td>
        <td colspan="4" class="no-border right"><strong>Payment Require Date:</strong></td>
    </tr>
    <tr>
        <td colspan="7" class="no-border"></td>
        <td class="box">{{ $paymentRequiredParts[0] }}</td>
        <td class="box">{{ $paymentRequiredParts[1] }}</td>
        <td class="box">{{ $paymentRequiredParts[2] }}</td>
    </tr>
    <tr>
        <td colspan="5" class="no-border"><strong>Buyer</strong> &nbsp; : &nbsp; {{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}<br><strong>Season</strong> : &nbsp; {{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}<br><br>* Buyer nominated supplier.<br>&nbsp;&nbsp;No excess quantity has been booked.</td>
        <td colspan="3" class="no-border"></td>
        @php $ocrCheck = \App\Support\PaymentRequestSettings::ocrCheckFor($paymentRequest); @endphp
        {{-- Auto-filled from the Accounts Team check (step 4) on a departmental
             PRA; the original blank form otherwise, legacy PRAs included. --}}
        <td colspan="4"><strong>OCR Checked:</strong> {{ $ocrCheck ? '☒' : '□' }} Yes &nbsp;&nbsp; □ No<br><br>Checker Name : {{ $ocrCheck ? $ocrCheck['name'] : '__________________' }}<br><br>Date : {{ $ocrCheck ? $ocrCheck['date'] : '__________________' }}</td>
    </tr>
    <tr>
        <td colspan="8" class="no-border"></td>
        <td colspan="4" class="no-border right"><strong>Total PI Amount: $ {{ $money($summary['total_pi_amount'] ?? 0) }}</strong></td>
    </tr>
    <tr>
        {{-- Same column order as the preview and the PDF. Comments has no
             counterpart on those two and stays where it was, before the
             amount. --}}
        <th>IHOD</th>
        <th>Style</th>
        <th>PCD Required</th>
        <th>Vendor Name</th>
        <th>Material PO Number</th>
        <th>Material PI Number</th>
        <th>Item Name</th>
        <th>Payment Term</th>
        <th>Committed Ex Mill</th>
        <th>Comments</th>
        <th>Budget</th>
        <th>PI Amount (USD)</th>
    </tr>
    @forelse($approvalRows as $row)
        <tr>
            {{-- Centred body, right-aligned amount, matching the preview and PDF.
                 Comments stays left-aligned: it holds free-text sentences, which
                 centring makes markedly harder to read. --}}
            <td class="center">{{ $row['contract_shipment'] ?: '-' }}</td>
            {{-- One spanning Style cell per run of consecutive rows sharing it,
                 matching the preview, the PDF and the .xlsx merge. --}}
            @if(($row['style_rowspan'] ?? 1) > 0)
                <td class="center" style="vertical-align:middle;font-weight:bold;" rowspan="{{ $row['style_rowspan'] ?? 1 }}">{{ $row['style'] ?: '-' }}</td>
            @endif
            <td class="center">{{ $row['pcd_required'] ?: '-' }}</td>
            <td class="center">{{ $row['vendor_name'] ?: '-' }}</td>
            <td class="center">{{ $row['material_po_number'] ?: '-' }}</td>
            <td class="center">{{ $row['material_pi_number'] ?: '-' }}</td>
            <td class="center">{{ $row['material_type'] ?: '-' }}</td>
            <td class="center">{{ $row['payment_term'] ?: '-' }}</td>
            <td class="center">{{ $row['committed_ex_mill'] ?: '-' }}</td>
            <td>{{ $row['comments'] ?: '(blank)' }}</td>
            {{-- null = no budget recorded for this line; shows "-", never 0.00. --}}
            <td class="right">{{ isset($row['budget']) ? $money($row['budget']) : '-' }}</td>
            <td class="right">{{ $money($row['pi_amount'] ?? 0) }}</td>
        </tr>
    @empty
        <tr><td colspan="12" class="center">No payment request item found.</td></tr>
    @endforelse
    <tr class="grand"><td colspan="11">Grand Total</td><td class="right">{{ $money($summary['total_pi_amount'] ?? 0) }}</td></tr>
    <tr><td colspan="12" class="no-border"></td></tr>
    @php $signBlocks = \App\Support\PaymentRequestSettings::signatureBlocksFor($paymentRequest, true); @endphp
    <tr>
        @foreach($signBlocks as $i => $sign)
            @if($i > 0)<td colspan="1" class="no-border"></td>@endif
            {{-- The last block runs one column wider so the three boxes still
                 span the full 12-column table after Budget was added. --}}
            <td colspan="{{ $i === count($signBlocks) - 1 ? 4 : 3 }}" class="signature">
                <strong>{{ $sign['title'] }}</strong><br>
                @if($sign['src'])
                    <img src="{{ $sign['src'] }}" alt="Signature of {{ $sign['name'] ?? 'approver' }}" style="max-height:40px;"><br>
                    {{ $sign['name'] }}{{ !empty($sign['date']) ? ' — ' . $sign['date'] : '' }}<br>
                    @if($sign['designation']){{ $sign['designation'] }}<br>@endif
                    Signature &amp; Date
                @elseif(!empty($sign['pending']))
                    {{-- Unsigned; the caption is what keeps a name from reading as a signature. --}}
                    <br>{{ $sign['name'] }}<br>
                    @if($sign['designation']){{ $sign['designation'] }}<br>@endif
                    <em>Pending approval</em>
                @elseif(!empty($sign['dynamic']) && ($sign['name'] || $sign['date']))
                    <br>{{ $sign['name'] }}{{ !empty($sign['date']) ? ' — ' . $sign['date'] : '' }}<br>
                    @if($sign['designation']){{ $sign['designation'] }}<br>@endif
                    Signature &amp; Date
                @else
                    <br><br>Signature &amp; Date
                @endif
                <br>________________________
            </td>
        @endforeach
    </tr>
</table>
</body>
</html>
