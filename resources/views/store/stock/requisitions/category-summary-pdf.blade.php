{{--
    Category summary for PDF and Excel (one view, so both match the screen).
    Expects: $categories, $totals, $monthLabel, $previousLabel, $forExcel.
--}}
@php
    $forExcel = $forExcel ?? false;
    $qty = fn ($v) => $forExcel ? \App\Support\Quantity::wholePlain($v) : \App\Support\Quantity::whole($v);
    $money = fn ($v) => $v === null ? ($forExcel ? '' : '-') : ($forExcel ? number_format((float) $v, 2, '.', '') : number_format((float) $v, 2));
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; }
        h1 { font-size: 16px; margin: 0; } h2 { font-size: 11px; font-weight: normal; color: #475569; margin: 2px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #000b6f; color: #fff; padding: 6px; font-size: 9px; text-align: left; }
        td { border: 1px solid #c7d2fe; padding: 5px 6px; }
        .num { text-align: right; } .tot td { background: #eef2ff; font-weight: bold; }
        .kpi td { border: 1px solid #e2e8f0; background: #f8fafc; text-align: center; padding: 8px; }
        .kpi b { display: block; font-size: 14px; } .kpi span { color: #64748b; font-size: 8px; text-transform: uppercase; }
    </style>
</head>
<body>
<table><tr><td colspan="8" style="border:0;padding:0;"><h1>Humana Apparels Pvt. Ltd.</h1></td></tr>
<tr><td colspan="8" style="border:0;padding:0;"><h2>Purchase Requisition - Category Summary - {{ $monthLabel }}</h2></td></tr></table>

<table class="kpi" style="margin-bottom:12px;"><tr>
    <td><span>Requisitions</span><b>{{ number_format($totals['requisitions']) }}</b></td>
    <td><span>Items</span><b>{{ number_format($totals['items']) }}</b></td>
    <td><span>To Be Procured</span><b>{{ $qty($totals['to_be_procured']) }}</b></td>
    <td><span>Total Amount</span><b>{{ $money($totals['amount']) }}</b></td>
</tr></table>

<table>
    <thead><tr>
        <th>#</th><th>Category</th><th class="num">Items</th><th class="num">Qty Requested</th><th class="num">To Be Procured</th>
        <th class="num">Amount</th><th class="num">Share %</th><th class="num">vs {{ $previousLabel }}</th>
    </tr></thead>
    <tbody>
    @forelse($categories as $row)
        <tr>
            <td>{{ $loop->iteration }}</td><td>{{ $row['name'] }}</td>
            <td class="num">{{ number_format($row['items']) }}</td><td class="num">{{ $qty($row['qty_requested']) }}</td>
            <td class="num">{{ $qty($row['to_be_procured']) }}</td><td class="num">{{ $money($row['amount']) }}</td>
            <td class="num">{{ number_format($row['share'], 1) }}%</td>
            <td class="num">{{ $row['change'] === null ? '-' : ($row['change'] > 0 ? '+' : '').number_format($row['change'], 1).'%' }}</td>
        </tr>
    @empty
        <tr><td colspan="8">No counted requisitions in {{ $monthLabel }}.</td></tr>
    @endforelse
    </tbody>
    @if($categories->isNotEmpty())
    <tfoot><tr class="tot">
        <td></td><td>Total</td><td class="num">{{ number_format($totals['items']) }}</td><td class="num">{{ $qty($totals['qty_requested']) }}</td>
        <td class="num">{{ $qty($totals['to_be_procured']) }}</td><td class="num">{{ $money($totals['amount']) }}</td><td class="num">100%</td><td></td>
    </tr></tfoot>
    @endif
</table>
<p style="color:#64748b;font-size:8px;margin-top:8px;">Counted requisitions only (drafts and sheets still with a head are not included). To Be Procured = Qty - Stock in Hand + Safety Stock, stock counted once per item.</p>
</body>
</html>
