<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        {{-- A4 landscape with the same margins as the Bulk Issuing Register, so
             the two Store registers print on identical paper. 25 columns is wide,
             so the body font drops a step and every cell wraps rather than
             clipping — long buyer/style/vendor/material names must stay readable. --}}
        {{-- Margins are tight because 25 columns have to fit ONE page width;
             the bottom margin is the only generous one, reserved for the fixed
             page-number footer so it can never overlap table content. --}}
        @page { size: A4 landscape; margin: 12px 10px 30px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #0F172A; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .head td { border: 0; vertical-align: top; padding: 0; }
        .title { text-align: center; font-size: 17px; font-weight: 800; letter-spacing: .02em; }
        .subtitle { text-align: center; font-size: 8.5px; margin-top: 4px; color: #475569; }
        {{-- Amber so a scoped register is never mistaken for the full history. --}}
        .filter-note {
            text-align: center; font-size: 8px; margin-top: 3px;
            font-weight: 700; color: #B45309;
        }
        .meta { text-align: right; font-size: 8px; font-weight: 700; line-height: 1.6; color: #334155; }

        .rcv-report { table-layout: fixed; margin-top: 9px; }
        .rcv-report th {
            background: #1D4ED8; color: #fff; border: 1px solid #1E40AF; padding: 3px 1.5px;
            font-size: 5.9px; line-height: 1.15; font-weight: 700; text-align: left; vertical-align: middle;
            word-wrap: break-word; overflow-wrap: break-word;
        }
        .rcv-report th.num { text-align: right; }
        .rcv-report td {
            border: 1px solid #E2E8F0; padding: 2.5px 1.5px; font-size: 6.2px; line-height: 1.2;
            vertical-align: top; word-wrap: break-word; overflow-wrap: break-word;
        }
        .rcv-report td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .rcv-report tbody tr:nth-child(even) td { background: #F8FAFC; }
        {{-- Repeat the header on every page and never split a row across a break. --}}
        .rcv-report thead { display: table-header-group; }
        .rcv-report tr { page-break-inside: avoid; }
        .empty { text-align: center; padding: 20px; color: #64748B; font-size: 9px; }

        {{-- Sign-off block. In normal flow straight after the table, so it
             appears ONCE at the end of the report — unlike the table header,
             which repeats per page. Kept whole across a page break. --}}
        .sign { margin-top: 34px; page-break-inside: avoid; table-layout: fixed; }
        .sign td { border: 0; padding: 0 6px; vertical-align: bottom; }
        {{-- The line that gets signed over. --}}
        .sign .line { height: 26px; border-bottom: 1px solid #334155; }
        .sign .label {
            padding-top: 4px; font-size: 7px; font-weight: 700; text-align: center;
            color: #0F172A; line-height: 1.25;
        }

        {{-- position:fixed repeats on every page in DomPDF, and it sits inside
             the reserved bottom margin so it never collides with the table or
             the signature block. --}}
        .page-footer {
            position: fixed; bottom: -22px; left: 0; right: 0;
            font-size: 6.5px; color: #64748B;
        }
        .page-footer .pno:after { content: counter(page); }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:33%"></td>
            <td style="width:34%">
                <div class="title">Receiving Register (MRR)</div>
                <div class="subtitle">Store · Buyer / Style Stock · {{ $receivings->count() }} record(s)</div>
                @if(($filterSummary ?? null))
                    <div class="filter-note">Filtered — {{ $filterSummary }}</div>
                @endif
            </td>
            <td style="width:33%">
                <div class="meta">Generated: {{ $generatedAt->format('d-M-Y H:i') }}</div>
            </td>
        </tr>
    </table>

    {{-- Columns and values come from the shared register partial, so this PDF
         and the Excel sheet can never drift apart. --}}
    @include('store.material-stock._receivings-report-table', [
        'receivings' => $receivings,
        'docs' => $docs,
        'mode' => 'pdf',
    ])

    {{-- Five equal sign-off columns: line first, label underneath. --}}
    <table class="sign">
        <tr>
            @foreach(['Prepared By', 'Checked By QC', 'Store Manager', 'Accounts Manager', 'Approved By Finance and Commercial Head'] as $role)
                <td style="width:20%" class="line"></td>
            @endforeach
        </tr>
        <tr>
            @foreach(['Prepared By', 'Checked By QC', 'Store Manager', 'Accounts Manager', 'Approved By Finance and Commercial Head'] as $role)
                <td class="label">{{ $role }}</td>
            @endforeach
        </tr>
    </table>

    <div class="page-footer">
        <table>
            <tr>
                <td style="border:0; padding:0; text-align:left;">Receiving Register (MRR)</td>
                <td style="border:0; padding:0; text-align:right;">Page <span class="pno"></span></td>
            </tr>
        </table>
    </div>
</body>
</html>
