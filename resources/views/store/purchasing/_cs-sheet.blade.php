{{--
    The Comparative Statement as printed: control box top right, one column group
    per supplier, the chosen supplier highlighted, terms under each supplier,
    recommendation, and the three sign-off boxes. Table markup only so DomPDF
    (landscape A4) and the screen draw the same sheet.
--}}
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
    $suppliers = $cs->suppliers;
    $categories = collect($cs->items)->pluck('category')->filter(fn ($c) => filled($c))->unique()->values();
@endphp
<style>
    .cs-sheet { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #111; width: 100%; }
    .cs-sheet table { border-collapse: collapse; width: 100%; }
    .cs-sheet td, .cs-sheet th { border: 1px solid #555; padding: 3px 4px; vertical-align: middle; }
    .cs-sheet th { background: #eceff3; font-weight: bold; text-align: center; }
    .cs-sheet .nb, .cs-sheet .nb td { border: 0; }
    .cs-sheet .num { text-align: right; white-space: nowrap; }
    .cs-sheet .c { text-align: center; }
    .cs-sheet .chosen { background: #d9f0df; font-weight: bold; }
    .cs-sheet .tot td { background: #dde3ec; font-weight: bold; }
    .cs-sheet .terms { vertical-align: top; font-size: 8px; line-height: 1.45; }
    .cs-sheet .co { font-size: 12px; font-weight: bold; }
    .cs-sheet .form { font-size: 10px; font-weight: bold; }
    .cs-sheet .logo { font-size: 20px; font-weight: bold; letter-spacing: 3px; }
    .cs-sheet .logo small { display: block; font-size: 7px; letter-spacing: 2px; font-weight: normal; }
    .cs-sheet .ctrl td { font-size: 8px; padding: 2px 5px; white-space: nowrap; }
    .cs-sheet .box { border: 1px solid #555; padding: 6px 8px; min-height: 46px; }
    .cs-sheet .sig td { border: 0; text-align: center; vertical-align: bottom; width: 33%; padding-top: 22px; }
    .cs-sheet .sig .line { display: inline-block; border-top: 1px solid #111; padding: 3px 2px 0; font-weight: bold; }
    .cs-sheet .sig .who { font-size: 8px; min-height: 22px; }
    .cs-sheet .muted { color: #666; }
</style>
<div class="cs-sheet">
    <table class="nb" style="margin-bottom:6px;">
        <tr>
            <td style="width:24%;">@include('store.purchasing._logo', ['width' => 120])</td>
            <td class="c">
                <div class="co">Humana Apparels Pvt. Ltd.</div>
                <div>Gorai, Mirzapur, Tangail, Bangladesh</div>
                <div class="form">Comparative Statement &amp; PO Approval Form</div>
            </td>
            <td style="width:24%;vertical-align:top;text-align:right;">
                <table class="ctrl" align="right" style="width:auto;margin-left:auto;">
                    <tr><td>Code: {{ $cs->form_code }}</td></tr>
                    <tr><td>Issue Date: {{ $cs->form_issue_date }}</td></tr>
                    <tr><td>Version No: {{ $cs->form_version_no }}</td></tr>
                    <tr><td>Revision No: {{ $cs->form_revision_no }}</td></tr>
                    <tr><td>Date: {{ $cs->cs_date?->format('d-m-Y') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-bottom:4px;" class="nb">
        <tr>
            <td style="width:34%;">CS No: <strong>{{ $cs->cs_no }}</strong></td>
            <td class="c" style="width:33%;">Status: <strong>{{ $cs->statusLabel() }}</strong></td>
            <td class="num">Prepared from approved purchase requisition lines</td>
        </tr>
        @if($categories->isNotEmpty())
            {{-- Material category sits here, under CS No and outside the item
                 table, instead of as a banner row inside it. --}}
            <tr>
                <td colspan="3" style="padding-top:2px;">Category: <strong>{{ $categories->implode(', ') }}</strong></td>
            </tr>
        @endif
    </table>

    <table>
        <thead>
            <tr>
                <th rowspan="2" style="width:3%;">Sl. No.</th>
                <th rowspan="2" style="width:14%;">Item Name</th>
                <th rowspan="2" style="width:4%;">UoM</th>
                <th rowspan="2" style="width:4%;">Qty</th>
                <th rowspan="2" style="width:9%;">Specification / Brand &amp; Model</th>
                <th rowspan="2" style="width:8%;">Current Supplier Details</th>
                @foreach($suppliers as $s)
                    <th colspan="3">{{ $s->name }}@if($s->is_recommended) *@endif</th>
                @endforeach
                <th rowspan="2" style="width:8%;">Remarks</th>
            </tr>
            <tr>
                @foreach($suppliers as $s)
                    <th>Specification / Model</th><th>Unit Price</th><th>Total Amount</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($cs->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->item_name }}</td>
                    <td class="c">{{ $item->uom }}</td>
                    <td class="num">{{ $qty($item->qty) }}</td>
                    <td>{{ $item->specification }}</td>
                    <td>{{ $item->current_supplier }}</td>
                    @foreach($suppliers as $s)
                        @php
                            $quote = $item->quoteFor($s->id);
                            $chosen = (int) $item->selected_supplier_id === (int) $s->id;
                        @endphp
                        <td class="{{ $chosen ? 'chosen' : '' }}">{{ $quote?->specification }}</td>
                        <td class="num {{ $chosen ? 'chosen' : '' }}">{{ $quote?->unit_price !== null ? $fmt($quote->unit_price) : '' }}</td>
                        <td class="num {{ $chosen ? 'chosen' : '' }}">{{ $quote?->unit_price !== null ? $fmt($item->qty * $quote->unit_price) : '' }}</td>
                    @endforeach
                    <td>{{ $item->remarks }}</td>
                </tr>
            @endforeach
            <tr class="tot">
                <td colspan="6" style="background:#fff;border:0;"></td>
                @foreach($suppliers as $s)
                    <td colspan="2" class="c">Total</td>
                    <td class="num">{{ $fmt($cs->totalFor($s)) }}</td>
                @endforeach
                <td style="background:#fff;border:0;"></td>
            </tr>
            <tr>
                <td colspan="6"></td>
                @foreach($suppliers as $s)
                    <td colspan="3" class="terms">
                        @if(filled($s->terms)){!! nl2br(e($s->terms)) !!}<br>@endif
                        <strong>{{ $s->name }}</strong><br>
                        @if(filled($s->address)){!! nl2br(e($s->address)) !!}<br>@endif
                        @if(filled($s->phone))Call: {{ $s->phone }}@endif
                    </td>
                @endforeach
                <td></td>
            </tr>
        </tbody>
    </table>

    <table class="nb" style="margin-top:3px;">
        <tr>
            <td class="num">Total of the suppliers chosen: <strong>{{ $fmt($cs->chosenTotal()) }}</strong>
                <span class="muted">(highlighted; * = recommended supplier)</span></td>
        </tr>
    </table>

    <div class="box" style="margin-top:6px;">
        <strong><em>Recommendation From Committee Members:</em></strong><br>
        {!! nl2br(e($cs->recommendation)) !!}
    </div>

    <table class="sig">
        <tr>
            <td>
                <div class="who">@if($cs->preparedBy){{ $cs->preparedBy->name }}<br>{{ $cs->prepared_at?->format('d-m-Y') }}@endif</div>
                <div class="line">Jr. Executive - SCM</div>
            </td>
            <td>
                <div class="who">@if($cs->checkedBy){{ $cs->checkedBy->name }}<br>{{ $cs->checked_at?->format('d-m-Y') }}@endif</div>
                <div class="line">Manager - SCM</div>
            </td>
            <td>
                <div class="who">@if($cs->approvedBy){{ $cs->approvedBy->name }}<br>{{ $cs->approved_at?->format('d-m-Y') }}@endif</div>
                <div class="line">Approved By</div>
            </td>
        </tr>
    </table>

    @if(filled($cs->approver_note))
        <div class="box" style="margin-top:8px;"><strong>Approver note:</strong> {!! nl2br(e($cs->approver_note)) !!}</div>
    @endif
</div>
