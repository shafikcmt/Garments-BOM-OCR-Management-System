{{--
    The Internal PO as printed: A4 portrait. Table markup only, so DomPDF and the
    screen draw the same page. A draft is marked so it is never mistaken for an
    authorised order.
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
    $rows = max(0, 12 - $order->items->count());
@endphp
<style>
    .po-sheet { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #111; width: 100%; }
    .po-sheet table { border-collapse: collapse; width: 100%; }
    .po-sheet td, .po-sheet th { border: 1px solid #555; padding: 4px 6px; vertical-align: top; }
    .po-sheet th { background: #eceff3; text-align: center; font-weight: bold; vertical-align: middle; }
    .po-sheet .k { color: #555; font-size: 8px; display: block; }
    .po-sheet .v { font-weight: bold; }
    .po-sheet .num { text-align: right; white-space: nowrap; }
    .po-sheet .c { text-align: center; }
    .po-sheet h2 { margin: 0; padding: 5px; text-align: center; font-size: 13px; letter-spacing: 1px; border: 1px solid #555; border-bottom: 0; background: #eceff3; }
    .po-sheet .logo { font-size: 17px; font-weight: bold; letter-spacing: 3px; }
    .po-sheet .logo small { display: block; font-size: 6px; letter-spacing: 2px; font-weight: normal; }
    .po-sheet .items td { height: 18px; }
    .po-sheet .tot td { background: #dde3ec; font-weight: bold; }
    .po-sheet .sig td { border: 0; padding-top: 52px; width: 50%; }
    .po-sheet .sig .line { border-top: 1px solid #111; padding-top: 3px; font-weight: bold; }
    .po-sheet .sig small { font-weight: normal; color: #444; }
    .po-sheet .draft { color: #b00020; font-weight: bold; text-align: center; font-size: 11px; margin-bottom: 4px; }
</style>
<div class="po-sheet">
    @if($order->status === \App\Models\GsPurchaseOrder::STATUS_DRAFT)
        <div class="draft">DRAFT: not yet authorised</div>
    @elseif($order->status === \App\Models\GsPurchaseOrder::STATUS_CANCELLED)
        <div class="draft">CANCELLED</div>
    @endif

    <h2>PURCHASE ORDER</h2>
    <table>
        <tr>
            <td rowspan="2" style="width:56%;">
                <span class="k">Invoice To</span>
                {!! nl2br(e($order->invoice_to)) !!}
            </td>
            <td style="width:26%;"><span class="k">Purchase Order No:</span><span class="v">{{ $order->po_no }}</span></td>
            <td style="width:18%;"><span class="k">Date:</span><span class="v">{{ $order->po_date?->format('d-M-y') }}</span></td>
        </tr>
        <tr>
            <td><span class="k">Reference No. &amp; Date</span><span class="v">{{ $order->reference_no ?: 'Ref. No. #' }}</span></td>
            <td rowspan="2"><div class="logo">HUMANA<small>APPARELS PVT. LTD.</small></div></td>
        </tr>
        <tr>
            <td rowspan="2"><span class="k">Consignee (ship to)</span>{!! nl2br(e($order->consignee)) !!}</td>
            <td><span class="k">BIN Number:</span><span class="v">{{ $order->bin_number }}</span></td>
        </tr>
        <tr>
            <td><span class="k">Dispatched through</span><span class="v">{{ $order->dispatched_through }}</span></td>
            <td><span class="k">Destination</span><span class="v">{{ $order->destination }}</span></td>
        </tr>
        <tr>
            <td>
                <span class="k">Supplier (Bill from)</span>
                <span class="v">{{ $order->supplier_name }}</span><br>
                @if(filled($order->supplier_address)){!! nl2br(e($order->supplier_address)) !!}<br>@endif
                @if(filled($order->supplier_phone))Call: {{ $order->supplier_phone }}@endif
            </td>
            <td colspan="2">
                <span class="k">Mode / Terms of Payment</span>{!! nl2br(e($order->payment_terms)) !!}<br><br>
                <span class="k">Terms of Delivery</span>{!! nl2br(e($order->delivery_terms)) !!}
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top:-1px;">
        <thead>
            <tr>
                <th style="width:5%;">SL</th><th>Item Description of Goods</th><th style="width:9%;">Due on</th>
                <th style="width:10%;">Quantity</th><th style="width:9%;">Rate</th><th style="width:6%;">Per</th>
                <th style="width:12%;">Amount</th><th style="width:11%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line->item_name }}</td>
                    <td class="c">{{ $line->due_on }}</td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    <td class="num">{{ number_format((float) $line->rate, 1) }}</td>
                    <td class="c">{{ strtoupper((string) $line->uom) }}</td>
                    <td class="num">{{ $fmt($line->amount) }}</td>
                    <td>{{ $line->remarks }}</td>
                </tr>
            @endforeach
            @for($r = 0; $r < $rows; $r++)
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="num">-</td><td></td></tr>
            @endfor
            <tr class="tot">
                <td colspan="5" style="border:0;background:#fff;"></td>
                <td class="c">Total</td>
                <td class="num">{{ $fmt($order->total_amount) }}</td>
                <td style="border:0;background:#fff;"></td>
            </tr>
        </tbody>
    </table>

    <p style="margin:10px 0 2px;"><strong>Amount Chargeable (in words)</strong><br>{{ \App\Support\AmountInWords::taka($order->total_amount) }}</p>

    <p style="margin:8px 0 0;"><strong>Remarks:</strong><br>
        @foreach(preg_split('/\r\n|\r|\n/', (string) $order->remarks) as $n => $remark)
            @if(trim($remark) !== ''){{ $n + 1 }}. {{ $remark }}<br>@endif
        @endforeach
    </p>

    <table class="sig">
        <tr>
            <td>
                <div style="font-size:8px;margin-bottom:2px;">{{ $order->checkedBy?->name }} {{ $order->checked_at?->format('d-m-Y') }}</div>
                <div class="line">Checked By<br><small>For Humana Apparels Pvt Ltd.</small></div>
            </td>
            <td style="text-align:right;">
                <div style="font-size:8px;margin-bottom:2px;">{{ $order->authorisedBy?->name }} {{ $order->authorised_at?->format('d-m-Y') }}</div>
                <div class="line">Authorised Signatory<br><small>For Humana Apparels Pvt Ltd.</small></div>
            </td>
        </tr>
    </table>
</div>
