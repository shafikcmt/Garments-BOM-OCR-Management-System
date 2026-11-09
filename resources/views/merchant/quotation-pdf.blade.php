@php
    $fmt = fn ($n, $d = 2) => number_format((float) $n, $d);
    $cons = fn ($n) => rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
    $basis = \App\Models\Inquiry::BASES[$inquiry->price_basis] ?? 'piece';
    $perPiece = $inquiry->pricePerPiece();
    $value = $inquiry->est_qty && $perPiece !== null ? $inquiry->est_qty * $perPiece : null;
    $valid = $date->copy()->addDays($validDays);
    $buyer = $inquiry->buyer;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quotation {{ $inquiry->reference }}</title>
    <style>
        @page { margin: 30px 34px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #555; padding: 5px 7px; vertical-align: top; }
        th { background: #eceff3; text-align: center; vertical-align: middle; }
        .k { color: #555; font-size: 8px; display: block; }
        .v { font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .c { text-align: center; }
        .head td { border: 0; padding: 0 0 6px 0; }
        .company { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
        .addr { font-size: 8px; color: #444; }
        h2 { margin: 8px 0 0; padding: 6px; text-align: center; font-size: 14px; letter-spacing: 3px; border: 1px solid #555; border-bottom: 0; background: #eceff3; }
        .sec { margin-top: 10px; font-weight: bold; font-size: 9px; letter-spacing: 1px; }
        .tot td { background: #dde3ec; font-weight: bold; }
        .sig td { border: 0; padding-top: 54px; width: 33%; }
        .sig .line { border-top: 1px solid #111; padding-top: 3px; font-weight: bold; }
        .sig small { font-weight: normal; color: #444; }
        .foot { margin-top: 14px; font-size: 8px; color: #555; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:70%;">
                @if($logo)<img src="{{ $logo }}" style="height:44px; margin-bottom:4px;" alt=""><br>@endif
                <span class="company">{{ $company }}</span>
                @foreach($address as $line)<div class="addr">{{ $line }}</div>@endforeach
            </td>
            <td style="text-align:right; vertical-align:bottom;">
                <span class="k">Quotation No.</span><span class="v" style="font-size:12px;">{{ $inquiry->reference }}</span>
            </td>
        </tr>
    </table>

    <h2>QUOTATION</h2>
    <table>
        <tr>
            <td style="width:34%;"><span class="k">To</span><span class="v">{{ $buyer?->buyer_name }}</span>
                @if($buyer?->contact_person)<div>Attn: {{ $buyer->contact_person }}</div>@endif
                @if($buyer?->email)<div>{{ $buyer->email }}</div>@endif
                @if($buyer?->phone)<div>{{ $buyer->phone }}</div>@endif
            </td>
            <td style="width:22%;"><span class="k">Date</span><span class="v">{{ $date->format('d M Y') }}</span></td>
            <td style="width:22%;"><span class="k">Valid until</span><span class="v">{{ $valid->format('d M Y') }}</span><span class="k">{{ $validDays }} days</span></td>
            <td><span class="k">Your inquiry received</span><span class="v">{{ $inquiry->received_date?->format('d M Y') }}</span></td>
        </tr>
    </table>

    <div class="sec">ITEM AND PRICE</div>
    <table>
        <thead>
            <tr><th>Style / Design</th><th>Description</th><th>Product group</th><th>Est. qty (pcs)</th><th>Unit price<br>({{ $inquiry->currency }} per {{ $basis }})</th><th>Est. value<br>({{ $inquiry->currency }})</th></tr>
        </thead>
        <tbody>
            <tr>
                <td class="v">{{ $inquiry->style_ref }}</td>
                <td>{{ $inquiry->description ?: '—' }}</td>
                <td>{{ $inquiry->product_group ?: '—' }}</td>
                <td class="num">{{ $inquiry->est_qty ? number_format($inquiry->est_qty) : '—' }}</td>
                <td class="num v">{{ $cons($inquiry->quoted_price) }}</td>
                <td class="num">{{ $value !== null ? $fmt($value) : '—' }}</td>
            </tr>
            @if($inquiry->price_basis === 'dz' && $perPiece !== null)
                <tr><td colspan="6" style="border-top:0; font-size:8px; color:#444;">Price per dozen. Equal to {{ $inquiry->currency }} {{ $cons(round($perPiece, 4)) }} per piece.</td></tr>
            @endif
            @if($value !== null)
                <tr class="tot"><td colspan="5" class="num">Estimated total ({{ $inquiry->currency }})</td><td class="num">{{ $fmt($value) }}</td></tr>
            @endif
        </tbody>
    </table>

    <div class="sec">TERMS</div>
    <table>
        <tr><td style="width:24%;"><span class="k">Payment terms</span></td><td>{{ $paymentTerms }}</td></tr>
        <tr><td><span class="k">Delivery terms</span></td><td>{{ $deliveryTerms }}</td></tr>
        <tr><td><span class="k">Validity</span></td><td>{{ $validDays }} days from the date of this quotation, until {{ $valid->format('d M Y') }}.</td></tr>
        @if($inquiry->quote_remark)<tr><td><span class="k">Remarks</span></td><td>{!! nl2br(e($inquiry->quote_remark)) !!}</td></tr>@endif
    </table>

    <table class="sig" style="margin-top:6px;">
        <tr>
            <td><div class="line">{{ $inquiry->creator?->name }}<br><small>Prepared by</small></div></td>
            <td><div class="line">&nbsp;<br><small>Authorised by</small></div></td>
            <td><div class="line">&nbsp;<br><small>Accepted by buyer (signature and date)</small></div></td>
        </tr>
    </table>
    <div class="foot">{{ $inquiry->reference }} · {{ $company }}</div>
</body>
</html>
