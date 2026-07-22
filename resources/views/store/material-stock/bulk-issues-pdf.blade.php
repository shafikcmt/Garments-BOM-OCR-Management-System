<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 16px 16px 26px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #0F172A; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .head td { border: 0; vertical-align: top; padding: 0; }
        .title { text-align: center; font-size: 18px; font-weight: 800; letter-spacing: .02em; }
        .subtitle { text-align: center; font-size: 9px; margin-top: 4px; color: #475569; }
        .meta { text-align: right; font-size: 8.5px; font-weight: 700; line-height: 1.6; color: #334155; }

        .bi-table { table-layout: fixed; margin-top: 10px; }
        .bi-table th {
            background: #1D4ED8; color: #fff; border: 1px solid #1E40AF; padding: 4px 3px;
            font-size: 7px; line-height: 1.15; font-weight: 700; text-align: left; vertical-align: middle;
        }
        .bi-table td {
            border: 1px solid #E2E8F0; padding: 3px 4px; font-size: 7.5px; line-height: 1.2;
            word-wrap: break-word; overflow-wrap: break-word;
        }
        .bi-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .bi-table tbody tr:nth-child(even) td { background: #F8FAFC; }
        .c-bulk { color: #059669; }
        .c-sample { color: #2563EB; }
        .c-liab { color: #D97706; }
        .c-dead { color: #E11D48; }
        .empty { text-align: center; padding: 20px; color: #64748B; }
    </style>
</head>
<body>
    @php
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 4), '0'), '.') ?: '0';
    @endphp
    <table class="head">
        <tr>
            <td style="width:33%"></td>
            <td style="width:34%">
                <div class="title">Bulk Issuing Register</div>
                <div class="subtitle">Store · Buyer / Style Stock · {{ $issues->count() }} record(s)</div>
            </td>
            <td style="width:33%">
                <div class="meta">Generated: {{ $generatedAt->format('d-M-Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <table class="bi-table">
        <thead>
            <tr>
                <th style="width:5.5%">Date</th>
                <th style="width:8%">Issue No</th>
                <th style="width:6%">Section</th>
                <th style="width:7%">Person</th>
                <th style="width:6%">Req. No</th>
                <th style="width:7%">Buyer</th>
                <th style="width:7%">Style</th>
                <th style="width:7%">PO No</th>
                <th style="width:8%">Material</th>
                <th style="width:6%">Art. No</th>
                <th style="width:6%">SAP</th>
                <th style="width:5%">Color</th>
                <th style="width:4%">Size</th>
                <th style="width:4%">Unit</th>
                <th style="width:4.5%" class="c-bulk">Bulk</th>
                <th style="width:4.5%" class="c-sample">Sample</th>
                <th style="width:4.5%" class="c-liab">Liab.</th>
                <th style="width:4.5%" class="c-dead">Dead</th>
            </tr>
        </thead>
        <tbody>
            @forelse($issues as $i)
                <tr>
                    <td>{{ optional($i->issue_date)->format('d-M-y') }}</td>
                    <td>{{ $i->issue_no }}</td>
                    <td>{{ $i->indent_section }}</td>
                    <td>{{ $i->indent_person }}</td>
                    <td>{{ $i->requisition_number }}</td>
                    <td>{{ $i->buyer_name }}</td>
                    <td>{{ $i->style_name }}</td>
                    <td>{{ $i->po_no }}</td>
                    <td>{{ $i->material_name ?: $i->material_description }}</td>
                    <td>{{ $i->art_no }}</td>
                    <td>{{ $i->sap_code }}</td>
                    <td>{{ $i->material_color }}</td>
                    <td>{{ $i->size }}</td>
                    <td>{{ $i->uom }}</td>
                    <td class="num c-bulk">{{ $num($i->bulk_qty) }}</td>
                    <td class="num c-sample">{{ $num($i->sample_qty) }}</td>
                    <td class="num c-liab">{{ $num($i->liability_qty) }}</td>
                    <td class="num c-dead">{{ $num($i->dead_qty) }}</td>
                </tr>
            @empty
                <tr><td colspan="18" class="empty">No bulk issues selected.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
