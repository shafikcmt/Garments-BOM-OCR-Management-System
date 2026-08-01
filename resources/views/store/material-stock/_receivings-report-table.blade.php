{{-- The Receiving History (MRR) register table — the SINGLE definition of the
     25 report columns. Both the PDF blade and the Excel blade include this, so
     column order, headers and values cannot drift between the two outputs.

     $mode = 'pdf' | 'excel'
       pdf   — blanks render as an em dash, numbers carry thousand separators.
       excel — blanks render empty and numbers are emitted raw (no separators,
               '.' decimal) so Excel keeps the cell numeric and the user can
               still sum/filter the sheet.

     $docs is keyed by excel_row_id and holds the fields that live on the BOM
     row rather than on the GRN (PI / LC / BL / BOE / Vendor Type / Booking Qty).
     A receiving with no linked BOM row — an Independent one — simply has none. --}}
@php
    $mode = $mode ?? 'pdf';
    $isExcel = $mode === 'excel';
    $blank = $isExcel ? '' : '—';

    // Text: never let a null reach the cell.
    $txt = fn ($v) => (trim((string) $v) !== '') ? trim((string) $v) : $blank;

    // Quantities — up to 4 decimals, trailing zeros trimmed.
    $qty = function ($v) use ($isExcel, $blank) {
        if ($v === null || $v === '') {
            return $blank;
        }
        $formatted = $isExcel
            ? number_format((float) $v, 4, '.', '')
            : number_format((float) $v, 4);

        return rtrim(rtrim($formatted, '0'), '.') ?: '0';
    };

    // Money — rate keeps 4 decimals, value 2, matching the on-screen table.
    $money = function ($v, int $dp) use ($isExcel, $blank) {
        if ($v === null || $v === '') {
            return $blank;
        }

        return $isExcel
            ? number_format((float) $v, $dp, '.', '')
            : number_format((float) $v, $dp);
    };

    // Column widths are a PDF concern only. PhpSpreadsheet's HTML reader treats
    // an inline `width` on a <th> as an EXPLICIT column width and switches that
    // column's auto-size off — "width:4%" became a 0.34-character column, which
    // is why the sheet looked cut/overlapped despite ShouldAutoSize. In excel
    // mode no width is emitted and MaterialReceivingExport sizes the columns.
    $w = fn (string $pct) => $isExcel ? '' : ' style="width:'.$pct.'"';

    // BOE Date arrives as a raw OCR cell: an Excel serial on some sheets, plain
    // text on others. Normalised here so both outputs print the same thing.
    $cellDate = function ($v) use ($blank) {
        $v = trim((string) $v);
        if ($v === '') {
            return $blank;
        }
        try {
            if (is_numeric($v)) {
                return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $v)->format('d-M-Y');
            }
            return \Carbon\Carbon::parse($v)->format('d-M-Y');
        } catch (\Throwable $e) {
            // Unparseable text is still information — show it as written.
            return $v;
        }
    };
@endphp
<table class="rcv-report">
    <thead>
        <tr>
            <th{!! $w('4%') !!}>Inventory/MRR Date</th>
            <th{!! $w('5.5%') !!}>GRN/MRN No</th>
            <th{!! $w('5%') !!}>PO Number</th>
            <th{!! $w('4.5%') !!}>PI Number</th>
            <th{!! $w('4%') !!}>LC No</th>
            <th{!! $w('4%') !!}>BL No / AWBL No</th>
            <th{!! $w('3.5%') !!}>BOE No</th>
            <th{!! $w('4%') !!}>BOE Date</th>
            <th{!! $w('3.5%') !!}>Vendor Type</th>
            <th{!! $w('3.5%') !!}>Season</th>
            <th{!! $w('4%') !!}>Buyer</th>
            <th{!! $w('4.5%') !!}>Style No.</th>
            <th{!! $w('7%') !!}>Material Description</th>
            <th{!! $w('4.5%') !!}>Art# No / SAP Code</th>
            <th{!! $w('4%') !!}>Color</th>
            <th{!! $w('3%') !!}>Size</th>
            <th{!! $w('3.6%') !!} class="num">Booking Qty</th>
            <th{!! $w('3.6%') !!} class="num">Invoice Qty</th>
            <th{!! $w('2.3%') !!}>UoM</th>
            <th{!! $w('3.6%') !!} class="num">Physical Qty</th>
            <th{!! $w('3.6%') !!} class="num">Short/Excess</th>
            <th{!! $w('3%') !!}>Roll/Bale</th>
            <th{!! $w('3.8%') !!} class="num">Rate as per Commercial Invoice</th>
            <th{!! $w('4%') !!} class="num">Total Value</th>
            <th{!! $w('4%') !!}>Remarks</th>
        </tr>
    </thead>
    <tbody>
        @forelse($receivings as $r)
            @php
                $doc = $docs[$r->excel_row_id] ?? [];

                // Booking Qty: the BOM cell is authoritative, but a receiving
                // recorded earlier already carries the value it was booked
                // against, so that is the fallback.
                $bookingQty = $doc['booking_qty'] ?? $r->internal_po_qty;

                // Short/Excess is never stored — always Invoice Qty less what
                // physically arrived, so it can never disagree with its inputs.
                $shortExcess = ($r->invoice_qty !== null)
                    ? (float) $r->invoice_qty - (float) $r->qty
                    : null;

                // Art. No and SAP Code share one column on the register.
                $artSap = collect([$r->art_no, $r->sap_code])
                    ->map(fn ($v) => trim((string) $v))
                    ->filter()
                    ->unique()
                    ->implode(' / ');

                // Material name and description are two columns in the database
                // but one on this sheet; show both when they differ.
                $material = collect([$r->material_name, $r->material_description])
                    ->map(fn ($v) => trim((string) $v))
                    ->filter()
                    ->unique()
                    ->implode(' — ');
            @endphp
            <tr>
                <td>{{ optional($r->receive_date)->format('d-M-Y') ?: $blank }}</td>
                <td>{{ $txt($r->grn_no) }}</td>
                <td>{{ $r->po_no ? $txt($r->po_no) : ($r->isIndependent() ? 'Independent' : $blank) }}</td>
                <td>{{ $txt($doc['pi_number'] ?? null) }}</td>
                <td>{{ $txt($doc['lc_no'] ?? null) }}</td>
                <td>{{ $txt($doc['bl_no'] ?? null) }}</td>
                <td>{{ $txt($doc['boe_no'] ?? null) }}</td>
                <td>{{ $cellDate($doc['boe_date'] ?? null) }}</td>
                <td>{{ $txt($doc['vendor_type'] ?? null) }}</td>
                <td>{{ $txt($r->season_name) }}</td>
                <td>{{ $txt($r->buyer_name) }}</td>
                <td>{{ $txt($r->style_name) }}</td>
                <td>{{ $txt($material) }}</td>
                <td>{{ $txt($artSap) }}</td>
                <td>{{ $txt($r->material_color) }}</td>
                <td>{{ $txt($r->size) }}</td>
                <td class="num">{{ $qty($bookingQty) }}</td>
                <td class="num">{{ $qty($r->invoice_qty) }}</td>
                <td>{{ $txt($r->uom) }}</td>
                <td class="num">{{ $qty($r->qty) }}</td>
                <td class="num">{{ $qty($shortExcess) }}</td>
                <td>{{ $txt($r->roll_bale) }}</td>
                <td class="num">{{ $money($r->unit_price, 4) }}</td>
                <td class="num">{{ $money($r->invoice_value, 2) }}</td>
                <td>{{ $txt($r->remarks) }}</td>
            </tr>
        @empty
            <tr><td colspan="25" class="empty">No receiving recorded yet.</td></tr>
        @endforelse
    </tbody>
</table>
