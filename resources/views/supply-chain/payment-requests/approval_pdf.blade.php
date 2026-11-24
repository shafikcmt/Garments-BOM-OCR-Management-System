<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 12px 14px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #000b6f; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        /* HEADER BOXES: logo | title | dates, each outlined, same design as the
           Excel export. */
        .head { table-layout: fixed; border: 1px solid #4b5fae; }
        .head td { border: 1px solid #4b5fae; vertical-align: middle; padding: 6px 10px; }
        .head .logo-cell { vertical-align: bottom; }
        .head .title-cell { text-align: center; }
        .head .dates-cell { padding: 0; }
        .dates-top { text-align: center; font-size: 9px; font-weight: 700; padding: 9px 6px 7px; }
        .dates-label { text-align: center; font-size: 8px; font-weight: 700; padding: 0 6px 4px; }
        .date-parts { table-layout: fixed; }
        .date-parts td { border: 0; border-top: 1px solid #8ea0d4; border-left: 1px solid #8ea0d4; text-align: center; font-size: 9px; font-weight: 700; padding: 5px 2px; }
        .date-parts td:first-child { border-left: 0; }
        .logo-text { font-size: 25px; letter-spacing: .09em; font-weight: 600; line-height: .95; }
        .logo-small { font-size: 7px; letter-spacing: .06em; font-weight: 700; padding-left: 48px; }
        .company { font-size: 8.5px; letter-spacing: .08em; font-weight: 700; margin-top: 10px; }
        .logo-img { height: 50px; max-width: 160px; object-fit: contain; }
        .title { text-align: center; font-size: 25px; font-weight: 700; line-height: 1; letter-spacing: .02em; }
        .request-no { text-align: center; font-size: 10.5px; margin-top: 6px; letter-spacing: .04em; }
        .date-area { text-align: right; font-size: 9px; font-weight: 700; line-height: 1.6; }
        .date-area .date-value { font-weight: 700; white-space: nowrap; }
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
        .check { min-height: 60px; padding: 8px 10px; font-size: 8.5px; line-height: 1.5; border: 1px solid #4a5cb2; }
        .mini-box { display: inline-block; width: 11px; height: 11px; border: 1px solid #91a1d0; vertical-align: middle; margin: 0 5px 0 13px; }
        .line { display: inline-block; border-bottom: 1px solid #4a5cb2; width: 132px; height: 10px; }
        .total { text-align: right; font-size: 12px; font-weight: 700; margin: 3px 0 5px; }
        .report { table-layout: fixed; color: #111827; }
        .report th { background:#e3ebf8; color:#000b6f; border:1px solid #8ea0d4; padding: 4px 3px; font-size: 9px; line-height:1.15; font-weight:700; text-align:center; vertical-align:middle; }
        .report td { border:1px solid #e2e6ef; padding: 3px 4px; font-size: 9px; line-height:1.15; vertical-align:middle; word-break: break-word; }
        .center { text-align:center; } .right { text-align:right; }
        .style-cell { vertical-align:middle; font-weight:700; }
        .grand td { background:#eaf0fb !important; color:#000b6f; font-size: 10px; font-weight:700; padding: 5px 5px; }
        .sign { margin-top: 18px; color:#000b6f; }
        .sign-box { height: 40px; }
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
        .completed-line strong { font-weight:700; }
        .sign td + td { border-left:1px solid #8d9bd3; }
        .sign-title { font-size:9px; font-weight:700; margin-bottom:6px; }
        .sign-text { font-size:9px; margin-bottom:9px; }
        .sign-line { border-bottom:1px solid #000b6f; height:1px; }
        .sign-img { max-height:42px; max-width:100%; margin-bottom:4px; }
        .sign-meta { font-size:8.5px; line-height:1.4; margin-top:4px; }
        .sign-meta .sign-name { font-weight:700; }
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
        .w-vendor { width: 12%; } .w-style { width: 8%; } .w-pcd { width: 7%; } .w-term { width: 9%; }
        .w-po { width: 9%; } .w-pi { width: 9%; } .w-type { width: 8%; } .w-cship { width: 7%; } .w-exmill { width: 7%; }
        .w-budget { width: 7%; } .w-amount { width: 9%; } .w-saving { width: 8%; }
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
<table class="head">
    <tr>
        <td style="width:25%;" class="logo-cell">
            @if($logoData)
                <img src="{{ $logoData }}" class="logo-img" alt="Humana">
            @else
                <div class="logo-text">HUMANA</div>
                <div class="logo-small">APPARELS PVT. LTD.</div>
            @endif
            <div class="company">HUMANA APPARELS PVT. LTD.</div>
        </td>
        <td style="width:50%;" class="title-cell">
            <div class="title">Payment Request Approval</div>
            <div class="request-no">{{ $paymentRequest->request_no }}</div>
        </td>
        <td style="width:25%;" class="dates-cell">
            <div class="dates-top">Date:&nbsp; {{ optional($paymentRequest->created_at)->format('jS M-Y') }}</div>
            <div class="dates-label">Payment Require Date:</div>
            <table class="date-parts">
                <tr>
                    <td>{{ $paymentRequiredDate ? optional($paymentRequiredDate)->format('d') : '-' }}</td>
                    <td>{{ $paymentRequiredDate ? strtoupper(optional($paymentRequiredDate)->format('M')) : '-' }}</td>
                    <td>{{ $paymentRequiredDate ? optional($paymentRequiredDate)->format('Y') : '-' }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="info">
    <tr>
        <td style="width:65%;">
            <div class="buyer">
                Buyer&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: &nbsp;{{ $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-') }}<br>
                Season&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: &nbsp;{{ $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-') }}<br>
                Sales Contract&nbsp;: &nbsp;{{ $list($summary['sales_contracts'] ?? [], '-') }}
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
            <th class="w-amount right">PI Amt (USD)</th>
            <th class="w-budget right">Budget</th>
            <th class="w-saving right">Saving</th>
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
                @if(($row['item_rowspan'] ?? 1) > 0)
                    <td class="center" style="vertical-align:middle;" rowspan="{{ $row['item_rowspan'] ?? 1 }}">{{ $row['material_type'] ?: '-' }}</td>
                @endif
                <td class="center">{{ $row['payment_term'] ?: '-' }}</td>
                <td class="center">{{ $row['committed_ex_mill'] ?: '-' }}</td>
                <td class="right">${{ $money($row['pi_amount'] ?? 0) }}</td>
                <td class="right">{{ isset($row['budget']) ? '$' . $money($row['budget']) : '-' }}</td>
                @php $rowSaving = isset($row['budget']) ? (float) $row['budget'] - (float) ($row['pi_amount'] ?? 0) : null; @endphp
                <td class="right" @if($rowSaving !== null && $rowSaving < 0) style="color:#b42318;" @endif>{{ $rowSaving === null ? '-' : ($rowSaving < 0 ? '-$' . $money(abs($rowSaving)) : '$' . $money($rowSaving)) }}</td>
            </tr>
        @empty
            <tr><td colspan="12" class="center">No payment request item found.</td></tr>
        @endforelse
        @php $budgetDone = (bool) ($summary['budget_complete'] ?? false); $savingSum = (float) ($summary['saving_sum'] ?? 0); @endphp
        <tr class="grand">
            <td colspan="9">Grand Total</td>
            <td class="right">${{ $money($summary['total_pi_amount'] ?? 0) }}</td>
            <td class="right">{{ $budgetDone ? '$' . $money($summary['budget_sum'] ?? 0) : '' }}</td>
            <td class="right">{{ $budgetDone ? ($savingSum < 0 ? '-$' . $money(abs($savingSum)) : '$' . $money($savingSum)) : '' }}</td>
        </tr>
    </tbody>
</table>

@php
    // THE SIGNATURE BLOCK MOVES WITH THE TABLE. It always follows the table, but a
    // PRA with few rows is given a gap so the block sits about 60% of the way down
    // the page (a short table does not leave it stranded right under two rows, and
    // it is not pinned to the very bottom either). The more rows there are, the
    // smaller the gap, until a long table simply pushes the block further down.
    // Heights are estimates in points: about 190 for the header, OCR box and
    // heading, then about 15 a row.
    $signGap = max(16, 340 - (190 + 15 * $approvalRows->count()));
@endphp
<div style="height: {{ $signGap }}pt;"></div>
<table class="sign">
    <tr>
        @foreach($signatureBlocks as $i => $sign)
            {{-- $loop->last, not a hardcoded index 2: the block count is 3 on a
                 legacy PRA and 4 on a departmental one. Same content in every
                 block: caption, signing space (the signature image, when there is
                 one), the line and "Signature & Date". No typed name, date,
                 designation or "Pending approval". --}}
            <td style="width: {{ round(100 / max(1, count($signatureBlocks)), 2) }}%; padding: 0 {{ count($signatureBlocks) > 5 ? 5 : (count($signatureBlocks) > 3 ? 12 : 22) }}px;{{ $i === 0 ? ' padding-left:0;' : ($loop->last ? ' padding-right:0;' : '') }}">
                <div class="sign-title">{{ $sign['title'] }}</div>
                <div class="sign-box">
                    @if($sign['src'])<img src="{{ $sign['src'] }}" alt="signature" class="sign-img">@endif
                </div>
                <div class="sign-line"></div>
                <div class="sign-meta">Signature &amp; Date</div>
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
