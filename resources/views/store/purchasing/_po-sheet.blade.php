{{--
    The Internal PO as printed: A4 portrait. Table markup only, so DomPDF and the
    screen draw the same page. A draft is marked so it is never mistaken for an
    authorised order. The BIN is the company's fixed number (config), printed
    whatever an older PO has stored.
--}}
@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
    // The items table always shows at least 10 rows: the items, then blank rows.
    $rows = max(0, 10 - $order->items->count());
    $bin = config('purchasing.po_defaults.bin_number');
    $status = $order->status;
@endphp
<style>
    .po-sheet { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #1f2937; }
    /* The whole order inside one frame, square, drawn tight on the body:
       no space between the frame and the content. */
    .po-sheet { border: 1px solid #6b7280; padding: 0; }
    .po-sheet table { border-collapse: collapse; width: 100%; }
    .po-sheet td, .po-sheet th { vertical-align: top; }
    .po-sheet .k { color: #6b7280; font-size: 7.5px; text-transform: uppercase; letter-spacing: .5px; display: block; margin-bottom: 2px; }
    .po-sheet .v { font-weight: bold; color: #111827; }
    .po-sheet .banner { text-align: center; font-weight: bold; font-size: 9px; letter-spacing: .5px; padding: 3px 0; margin-bottom: 8px; border: 1px solid; }
    .po-sheet .banner.draft { color: #b45309; background: #fffbeb; border-color: #fcd34d; }
    .po-sheet .banner.checked { color: #1d4ed8; background: #eff6ff; border-color: #93c5fd; }
    .po-sheet .banner.cancelled { color: #b91c1c; background: #fef2f2; border-color: #fca5a5; }
    .po-sheet .title { font-size: 20px; font-weight: bold; letter-spacing: 3px; color: #374151; text-align: right; }
    .po-sheet .company { font-size: 12px; font-weight: bold; color: #111827; }
    .po-sheet .rule { border-top: 1px solid #9ca3af; margin: 8px 0 10px; }
    .po-sheet .meta td { padding: 3px 6px; border: 1px solid #d1d5db; width: 50%; }
    .po-sheet .meta .k { margin-bottom: 1px; }
    .po-sheet .box { border: 1px solid #d1d5db; padding: 6px 8px; }
    .po-sheet .box-h { background: #f3f4f6; border: 1px solid #d1d5db; border-bottom: 0; padding: 3px 8px; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #374151; }
    .po-sheet .items { margin-top: 10px; }
    .po-sheet .items th { background: #e5e7eb; color: #111827; border: 1px solid #d1d5db; padding: 5px 5px; font-size: 8.5px; text-align: center; vertical-align: middle; }
    .po-sheet .items td { border: 1px solid #d1d5db; padding: 3px 5px; height: 14px; vertical-align: middle; }
    .po-sheet .items tr.alt td { background: #f9fafb; }
    .po-sheet .num { text-align: right; white-space: nowrap; }
    .po-sheet .c { text-align: center; }
    .po-sheet .totals td { padding: 4px 6px; }
    .po-sheet .grand { background: #e5e7eb; color: #111827; border: 1px solid #d1d5db; font-weight: bold; font-size: 10.5px; }
    .po-sheet .words { margin-top: 8px; padding: 6px 8px; background: #f3f4f6; border-left: 3px solid #9ca3af; }
    .po-sheet .sig td { width: 50%; padding-top: 40px; }
    /* The signing line is only as long as its caption. */
    .po-sheet .sig .line { display: inline-block; border-top: 1px solid #111827; padding: 3px 2px 0; font-weight: bold; }
    .po-sheet .sig small { display: block; font-weight: normal; color: #6b7280; font-size: 8px; }
    .po-sheet .logo-box { border: 1px solid #d1d5db; }
    .po-sheet .logo-box td { padding: 5px 8px; vertical-align: middle; }
    .po-sheet .pair td.ph { width: 50%; background: #f3f4f6; border: 1px solid #d1d5db; padding: 3px 8px; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #374151; }
    .po-sheet .pair td.pc { border: 1px solid #d1d5db; padding: 5px 8px; line-height: 1.45; }
    .po-sheet .pair td.gap { width: 10px; border: 0; padding: 0; }
    .po-sheet .ship-box { margin-top: 6px; border: 1px solid #d1d5db; padding: 3px 8px; line-height: 1.4; }
    .po-sheet .ship-box .k { margin-bottom: 1px; }
    /* Delivery: one full-width label / value table. */
    .po-sheet .terms-pair td.cell { border: 1px solid #d1d5db; padding: 3px 8px; line-height: 1.4; vertical-align: top; }
    .po-sheet .terms-pair td.head { width: 24%; background: #f3f4f6; font-weight: bold; font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #374151; vertical-align: middle; }
</style>
<div class="po-sheet">
    @if($order->isPending())
        <div class="banner {{ $status === \App\Models\GsPurchaseOrder::STATUS_CHECKED ? 'checked' : 'draft' }}">{{ $status === \App\Models\GsPurchaseOrder::STATUS_CHECKED ? 'CHECKED: NOT YET AUTHORISED' : 'DRAFT: NOT YET CHECKED OR AUTHORISED' }}</div>
    @elseif($status === \App\Models\GsPurchaseOrder::STATUS_CANCELLED)
        <div class="banner cancelled">CANCELLED</div>
    @endif

    {{-- Letterhead: logo and company on the left, title and control numbers on the right. --}}
    <table>
        <tr>
            <td style="width:52%;vertical-align:bottom;padding-right:8px;">
                {{-- Logo and company in one bordered box, lined up with the PO
                     table on the right; the Consignee under it. --}}
                <table class="logo-box">
                    <tr>
                        <td style="width:118px;">@include('store.purchasing._logo', ['width' => 110])</td>
                        <td style="border-left:1px solid #d1d5db;line-height:1.45;">
                            <div class="company">Humana Apparels Pvt. Ltd</div>
                            <div>{!! nl2br(e($order->invoice_to)) !!}</div>
                        </td>
                    </tr>
                </table>
                <div class="ship-box">
                    <span class="k">Consignee (Ship to)</span>
                    <div>{!! nl2br(e($order->consignee)) !!}</div>
                </div>
            </td>
            <td style="width:48%;">
                <div class="title">PURCHASE ORDER</div>
                <table class="meta" style="margin-top:6px;">
                    <tr>
                        <td><span class="k">PO No.</span><span class="v">{{ $order->po_no }}</span></td>
                        <td><span class="k">Date</span><span class="v">{{ $order->po_date?->format('d-M-Y') }}</span></td>
                    </tr>
                    <tr>
                        <td><span class="k">Reference No.</span><span class="v">{{ $order->reference_no ?: '-' }}</span></td>
                        <td><span class="k">BIN</span><span class="v">{{ $bin }}</span></td>
                    </tr>
                    <tr>
                        <td><span class="k">Dispatched through</span><span class="v">{{ $order->dispatched_through ?: '-' }}</span></td>
                        <td><span class="k">Destination</span><span class="v">{{ $order->destination ?: '-' }}</span></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    {{-- Who we buy from, and on what payment terms, side by side and always
         the same height (the Consignee is in the letterhead, under the logo). --}}
    <table class="pair">
        <tr>
            <td class="ph">Supplier (Bill from)</td><td class="gap"></td><td class="ph">Mode / Terms of Payment</td>
        </tr>
        <tr>
            <td class="pc">
                <span class="v">{{ $order->supplier_name }}</span>
                @if(filled($order->supplier_address))<br>{!! nl2br(e($order->supplier_address)) !!}@endif
                @if(filled($order->supplier_phone))<br>Call: {{ $order->supplier_phone }}@endif
            </td>
            <td class="gap"></td>
            <td class="pc">{!! nl2br(e($order->payment_terms)) !!}</td>
        </tr>
    </table>

    {{-- Terms of Delivery, full width, label on the left: a five-line
         Terms of Delivery leaves no empty box. --}}
    <table class="terms-pair" style="margin-top:8px;">
        <tr>
            <td class="cell head">Terms of Delivery</td>
            <td class="cell">
                @foreach(array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $order->delivery_terms), fn ($l) => trim($l) !== '')) as $line)
                    {{ $line }}<br>
                @endforeach
            </td>
        </tr>
    </table>

    {{-- Items, as approved on the Comparative Statement. --}}
    <table class="items">
        <thead>
            <tr>
                <th style="width:5%;">SL</th><th>Item Description of Goods</th><th style="width:9%;">Due on</th>
                <th style="width:9%;">Qty</th><th style="width:10%;">Rate</th><th style="width:7%;">Per</th>
                <th style="width:12%;">Amount</th><th style="width:12%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $i => $line)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line->item_name }}</td>
                    <td class="c">{{ $line->due_on }}</td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    <td class="num">{{ $fmt($line->rate) }}</td>
                    <td class="c">{{ strtoupper((string) $line->uom) }}</td>
                    <td class="num">{{ $fmt($line->amount) }}</td>
                    <td>{{ $line->remarks }}</td>
                </tr>
            @endforeach
            @for($r = 0; $r < $rows; $r++)
                <tr class="{{ ($order->items->count() + $r) % 2 ? 'alt' : '' }}"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <table class="totals" style="margin-top:-1px;">
        <tr>
            <td style="width:62%;"></td>
            <td class="grand" style="width:16%;text-align:right;">TOTAL (Tk)</td>
            <td class="grand num" style="width:22%;">{{ $fmt($order->total_amount) }}</td>
        </tr>
    </table>

    <div class="words"><span class="k">Amount Chargeable (in words)</span><span class="v">{{ \App\Support\AmountInWords::taka($order->total_amount) }}</span></div>

    {{-- Remarks in a box of their own, numbered; an empty box still prints so
         a remark can be written in by hand. --}}
    @php($remarks = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $order->remarks), fn ($l) => trim($l) !== '')))
    <div style="margin-top:8px;">
        <div class="box-h">Remarks</div>
        <div class="box" style="min-height:34px;line-height:1.5;">
            @foreach($remarks as $n => $remark){{ $n + 1 }}. {{ $remark }}<br>@endforeach
        </div>
    </div>

    <table class="sig">
        <tr>
            <td>
                <div style="font-size:8px;margin-bottom:2px;">{{ $order->checkedBy?->name }} {{ $order->checked_at?->format('d-m-Y') }}</div>
                <div class="line">Checked By</div>
                <small>For Humana Apparels Pvt Ltd.</small>
            </td>
            <td style="text-align:right;">
                <div style="font-size:8px;margin-bottom:2px;">{{ $order->authorisedBy?->name }} {{ $order->authorised_at?->format('d-m-Y') }}</div>
                <div class="line">Authorised Signatory</div>
                <small>For Humana Apparels Pvt Ltd.</small>
            </td>
        </tr>
    </table>
</div>
