<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 12px 14px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #000b6f; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .top td { border: 0; vertical-align: top; padding: 0; }
        .logo-text { font-size: 25px; letter-spacing: .09em; font-weight: 600; line-height: .95; }
        .logo-small { font-size: 7px; letter-spacing: .06em; font-weight: 700; padding-left: 48px; }
        .company { font-size: 8.5px; letter-spacing: .08em; font-weight: 700; margin-top: 10px; }
        .logo-img { height: 50px; max-width: 160px; object-fit: contain; }
        .title { text-align: center; font-size: 25px; font-weight: 800; line-height: 1; letter-spacing: .02em; }
        .request-no { text-align: center; font-size: 10.5px; margin-top: 6px; letter-spacing: .04em; }
        .date-area { text-align: right; font-size: 9px; font-weight: 700; line-height: 1.6; }
        .date-area .date-value { font-weight: 800; white-space: nowrap; }
        {{-- VERTICAL SPACING IS A ONE-PAGE BUDGET. A4 landscape leaves roughly
             577pt of height, and the header/OCR/total/signature chrome around
             the table used to consume about 60% of it — so a PRA fitted only 13
             rows, or 8 once a long vendor name wrapped. Splitting a PO into one
             line per item (the correct behaviour) routinely produces 12-15 rows,
             which pushed a normal PRA onto a second page.

             The padding and line-heights below were therefore tightened, and the
             chrome tightened harder than the table, since that is where the space
             actually was. FONT SIZES ARE DELIBERATELY UNCHANGED — this is a
             document signed by non-technical staff, and shrinking the text to win
             rows is the wrong trade. Measured after this change: 20 rows on one
             page, or 13 with long wrapping vendor/item values.

             Keep this file and show.blade.php's @media print block in step; they
             render the same document and are compared side by side. --}}
        .info td { border: 0; vertical-align: top; padding: 7px 0 5px; }
        .buyer { font-size: 9px; line-height: 1.7; font-weight: 700; }
        .note { font-size: 8.5px; line-height: 1.4; font-weight: 700; padding-top: 5px; }
        .check { min-height: 60px; padding: 8px 10px; font-size: 8.5px; line-height: 1.5; }
        .mini-box { display: inline-block; width: 11px; height: 11px; border: 1px solid #91a1d0; vertical-align: middle; margin: 0 5px 0 13px; }
        .line { display: inline-block; border-bottom: 1px solid #4a5cb2; width: 132px; height: 10px; }
        .total { text-align: right; font-size: 12px; font-weight: 800; margin: 3px 0 5px; }
        .report { table-layout: fixed; color: #111827; }
        .report th { background:#000b6f; color:#fff; border:1px solid #33439e; padding: 4px 3px; font-size: 9px; line-height:1.15; font-weight:800; text-align:center; vertical-align:middle; }
        .report td { border:1px solid #e2e6ef; padding: 3px 4px; font-size: 9px; line-height:1.15; vertical-align:middle; word-break: break-word; }
        .center { text-align:center; } .right { text-align:right; }
        .style-cell { vertical-align:middle; font-weight:700; }
        .grand td { background:#eaf0fb !important; color:#000b6f; font-size: 10px; font-weight:800; padding: 5px 5px; }
        .sign { margin-top: 18px; color:#000b6f; }
        .sign td { border:0; width:33.33%; padding:0 22px; vertical-align:bottom; }
        /* Departmental PRAs print FOUR blocks instead of three. Narrower cells
           and tighter gutters keep the row on the same single page — the widths
           are a height budget, exactly as for the report table above. */
        .sign.cols-4 td { width:25%; padding:0 12px; }
        .sign-pending { font-size:8.5px; font-style:italic; color:#5b6ab0; }
        /* An auto-filled OCR Checked value. Same width as .line so the box keeps
           its height whether the value is printed or left blank for signing. */
        .filled { display:inline-block; width:132px; border-bottom:1px solid #4a5cb2; font-weight:700; }
        .completed-line { margin-top:10px; font-size:9px; color:#000b6f; }
        .completed-line strong { font-weight:800; }
        .sign td + td { border-left:1px solid #8d9bd3; }
        .sign-title { font-size:9px; font-weight:800; margin-bottom:6px; }
        .sign-text { font-size:9px; margin-bottom:9px; }
        .sign-line { border-bottom:1px solid #000b6f; height:1px; }
        .sign-img { max-height:42px; max-width:100%; margin-bottom:4px; }
        .sign-meta { font-size:8.5px; line-height:1.4; margin-top:4px; }
        .sign-meta .sign-name { font-weight:800; }
        {{-- 11 columns summing to 100, mirrored by .c-* in show.blade.php.

             WIDTH IS A ROW-HEIGHT BUDGET HERE, not just a look. Every column is
             word-break:break-word, so a column too narrow for its content wraps
             to a second or third line and makes the WHOLE ROW taller - which is
             what decides how many rows fit on the page. Taking a flat 1% off
             every column to make room for Budget cost three rows on a PRA with
             long vendor and payment-term values, even though the table was no
             taller on paper.

             So the space came from the three DATE columns instead: IHOD, PCD and
             Ex Mill hold exactly "01-Aug-26" and can never wrap, so 7% is enough
             for them, while Vendor, Pay Term and Item Name hold free text and got
             their width back. Measured: 20 rows on one page for normal values,
             12 with long wrapping ones - both unchanged from before Budget was
             added. Re-measure if you move these. --}}
        .w-vendor { width: 14%; } .w-style { width: 8%; } .w-pcd { width: 7%; } .w-term { width: 10%; }
        .w-po { width: 10%; } .w-pi { width: 10%; } .w-type { width: 9%; } .w-cship { width: 7%; } .w-exmill { width: 7%; }
        .w-budget { width: 8%; } .w-amount { width: 10%; }
    </style>
</head>
<body>
@php
    $approvalRows = collect($approvalRows ?? []);
    $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($v) => trim((string) $v))->filter()->take(5)->implode(', ') ?: $fallback;
    $money = fn ($value) => number_format((float) $value, 2);
    $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
    $logoPath = public_path('images/humana-logo.png');
    $logoData = file_exists($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;
    $signatureBlocks = \App\Support\PaymentRequestSettings::signatureBlocksFor($paymentRequest, true);
    // Null for legacy PRAs and for any departmental one not yet completed.
    $completionLine = \App\Support\PaymentRequestSettings::completionLineFor($paymentRequest);
    // Null until the Accounts Team check (step 4) — an unanswered box, not a "No".
    $ocrCheck = \App\Support\PaymentRequestSettings::ocrCheckFor($paymentRequest);
@endphp
<table class="top">
    <tr>
        <td style="width:25%;">
            @if($logoData)
                <img src="{{ $logoData }}" class="logo-img" alt="Humana">
            @else
                <div class="logo-text">HUMANA</div>
                <div class="logo-small">APPARELS PVT. LTD.</div>
            @endif
            <div class="company">HUMANA APPARELS PVT. LTD.</div>
        </td>
        <td style="width:50%; padding-top:2px;">
            <div class="title">Payment Request Approval</div>
            <div class="request-no">{{ $paymentRequest->request_no }}</div>
        </td>
        <td style="width:25%;" class="date-area">
            Date:&nbsp;&nbsp; <span class="date-value">{{ optional($paymentRequest->created_at)->format('jS M-Y') }}</span><br><br>
            Payment Require Date:&nbsp;&nbsp; <span class="date-value">{{ $paymentRequiredDate ? optional($paymentRequiredDate)->format('jS M-Y') : '-' }}</span>
        </td>
    </tr>
</table>

<table class="info">
    <tr>
        <td style="width:65%;">
            <div class="buyer">
                Buyer&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: &nbsp;{{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}<br>
                Season&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: &nbsp;{{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}
            </div>
            <div class="note">* Buyer nominated supplier.<br>&nbsp;&nbsp;No excess quantity has been booked.</div>
        </td>
        <td style="width:35%;">
            {{-- Filled from the Accounts Team check (step 4) on a departmental
                 PRA. Until that step is done — and always, on a legacy PRA —
                 this prints the original blank form for signing by hand. --}}
            <div class="check">
                <strong>OCR Checked:</strong>
                <span class="mini-box">{{ $ocrCheck ? '×' : '' }}</span> Yes
                <span class="mini-box"></span> No<br>
                Checker Name&nbsp;&nbsp;&nbsp;&nbsp;:
                @if($ocrCheck)<span class="filled">{{ $ocrCheck['name'] }}</span>@else<span class="line"></span>@endif<br>
                Date&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;:
                @if($ocrCheck)<span class="filled">{{ $ocrCheck['date'] }}</span>@else<span class="line"></span>@endif
            </div>
        </td>
    </tr>
</table>

<div class="total">Total PI Amount: $ {{ $money($summary['total_pi_amount'] ?? 0) }}</div>

<table class="report">
    <thead>
        <tr>
            {{-- Same order as the on-screen preview, so the PDF cannot drift
                 from what was approved. Width classes are semantic and travel
                 with their column. --}}
            <th class="w-cship">IHOD</th>
            <th class="w-style">Style</th>
            <th class="w-pcd">PCD Date</th>
            <th class="w-vendor">Vendor</th>
            <th class="w-po">PO No.</th>
            <th class="w-pi">PI No.</th>
            {{-- Renamed from "Type"; same material_type value. --}}
            <th class="w-type">Item Name</th>
            <th class="w-term">Pay Term</th>
            <th class="w-exmill">Ex Mill</th>
            <th class="w-budget right">Budget</th>
            <th class="w-amount right">PI Amt (USD)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($approvalRows as $row)
            <tr>
                {{-- Centred body, right-aligned amount — identical to the
                     on-screen preview, so the PDF matches what was approved. --}}
                <td class="center">{{ $row['contract_shipment'] ?: '-' }}</td>
                {{-- One spanning Style cell per run of consecutive rows sharing
                     it, matching the preview. A covered row emits no cell -
                     an empty one would shift its remaining columns right. --}}
                @if(($row['style_rowspan'] ?? 1) > 0)
                    <td class="center style-cell" rowspan="{{ $row['style_rowspan'] ?? 1 }}">{{ $row['style'] ?: '-' }}</td>
                @endif
                <td class="center">{{ $row['pcd_required'] ?: '-' }}</td>
                <td class="center">{{ $row['vendor_name'] ?: '-' }}</td>
                <td class="center">{{ $row['material_po_number'] ?: '-' }}</td>
                <td class="center">{{ $row['material_pi_number'] ?: '-' }}</td>
                <td class="center">{{ $row['material_type'] ?: '-' }}</td>
                <td class="center">{{ $row['payment_term'] ?: '-' }}</td>
                <td class="center">{{ $row['committed_ex_mill'] ?: '-' }}</td>
                <td class="right">{{ isset($row['budget']) ? '$' . $money($row['budget']) : '-' }}</td>
                <td class="right">${{ $money($row['pi_amount'] ?? 0) }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="center">No payment request item found.</td></tr>
        @endforelse
        <tr class="grand">
            <td colspan="10">Grand Total</td>
            <td class="right">${{ $money($summary['total_pi_amount'] ?? 0) }}</td>
        </tr>
    </tbody>
</table>

<table class="sign {{ count($signatureBlocks) === 4 ? 'cols-4' : '' }}">
    <tr>
        @foreach($signatureBlocks as $i => $sign)
            {{-- $loop->last, not a hardcoded index 2: the block count is 3 on a
                 legacy PRA and 4 on a departmental one. --}}
            <td style="{{ $i === 0 ? 'padding-left:0;' : ($loop->last ? 'padding-right:0;' : '') }}">
                <div class="sign-title">{{ $sign['title'] }}</div>
                @if($sign['src'])
                    <img src="{{ $sign['src'] }}" alt="signature" class="sign-img">
                    <div class="sign-line"></div>
                    <div class="sign-meta">
                        @if($sign['designation'])<div>{{ $sign['designation'] }}</div>@endif
                        <div>Signature &amp; Date</div>
                    </div>
                @elseif(!empty($sign['pending']))
                    {{-- Not yet signed. The name (when one person is expected)
                         is shown to help whoever is chasing the paperwork, and
                         the caption is what stops it reading as an endorsement.
                         No image, ever, on a pending block. --}}
                    <div class="sign-text">&nbsp;</div>
                    <div class="sign-line"></div>
                    <div class="sign-meta">
                        @if($sign['designation'])<div>{{ $sign['designation'] }}</div>@endif
                        <div>Signature &amp; Date</div>
                    </div>
                @elseif(!empty($sign['dynamic']) && ($sign['name'] || $sign['date']))
                    {{-- Actor known but no signature image uploaded: typed name + date --}}
                    <div class="sign-text">&nbsp;</div>
                    <div class="sign-line"></div>
                    <div class="sign-meta">
                        @if($sign['designation'])<div>{{ $sign['designation'] }}</div>@endif
                        <div>Signature &amp; Date</div>
                    </div>
                @else
                    <div class="sign-text">Signature &amp; Date</div>
                    <div class="sign-line"></div>
                @endif
            </td>
        @endforeach
    </tr>
</table>

{{-- Step 6 is a payment, not an endorsement, so it gets a line rather than a
     signature block. Present only once the Commercial Head has completed. --}}
@if($completionLine)
    <div class="completed-line">
        <strong>Paid / Completed by:</strong> {{ $completionLine['name'] ?: '—' }}
        @if($completionLine['date']) on {{ $completionLine['date'] }}@endif
    </div>
@endif
</body>
</html>
