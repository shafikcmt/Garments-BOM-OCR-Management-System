{{--
    Stock Summary — Purchase View, A4 landscape. Same rows and totals as the
    screen under the same filters; the header repeats on every page.
--}}
@php
    $qty = fn ($v) => \App\Support\Quantity::format($v);
    $filtersLine = str_replace(' · '.$period, '', $title);
    $t = $purchase['totals'];
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Stock Summary</title>
<style>
    @page { margin: 12mm 10mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 0.5pt solid #888; padding: 2.5pt 3pt; vertical-align: top; word-wrap: break-word; }
    thead th.col { background: #e8eef7; font-weight: bold; text-align: center; }
    thead td.head { border: none; text-align: center; padding: 1pt; }
    tr { page-break-inside: avoid; }
    .r { text-align: right; white-space: nowrap; }
    .c { text-align: center; }
    .total td { background: #fff4ce; font-weight: bold; }
    .company { font-size: 12pt; font-weight: bold; }
    .title { font-size: 9.5pt; font-weight: bold; }
    .filters { font-size: 7pt; font-style: italic; color: #444; }
</style>
</head>
<body>
<table>
    <colgroup>
        <col style="width:5%"><col style="width:37%"><col style="width:8%"><col style="width:10%">
        <col style="width:10%"><col style="width:10%"><col style="width:10%"><col style="width:10%">
    </colgroup>
    <thead>
        <tr><td class="head company" colspan="8">{{ config('stock.company_name', 'Humana Apparels Pvt. Ltd.') }}</td></tr>
        <tr><td class="head title" colspan="8">Stock Summary — Purchase View ({{ $period }})</td></tr>
        @if($filtersLine !== $period)
            <tr><td class="head filters" colspan="8">Filters: {{ $filtersLine }}</td></tr>
        @endif
        <tr>
            <th class="col">SL</th><th class="col">Item</th><th class="col">UOM</th><th class="col">Requested</th>
            <th class="col">Current Stock</th><th class="col">Purchased</th><th class="col">To Purchase</th><th class="col">Status</th>
        </tr>
    </thead>
    <tbody>
        @forelse($purchase['rows'] as $i => $row)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $row['item_name'] }}</td>
                <td>{{ $row['uom'] ?: '—' }}</td>
                <td class="r">{{ $qty($row['requested']) }}</td>
                <td class="r">{{ $qty($row['stock_in_hand']) }}</td>
                <td class="r">{{ $qty($row['purchased']) }}</td>
                <td class="r">{{ $qty($row['pending']) }}</td>
                <td>{{ $row['pending'] > 0 ? 'To Purchase' : ($row['over_received'] > 0 ? 'Over Received' : 'Purchased') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="c">No matching items</td></tr>
        @endforelse
        <tr class="total">
            <td></td><td colspan="2">Total</td>
            <td class="r">{{ $qty($t['requested']) }}</td><td class="r">{{ $qty($t['stock_in_hand']) }}</td>
            <td class="r">{{ $qty($t['purchased']) }}</td><td class="r">{{ $qty($t['pending']) }}</td><td></td>
        </tr>
    </tbody>
</table>
<p style="font-size:7pt;color:#444;margin-top:6pt;">Drafts excluded. Current Stock = today.</p>
</body>
</html>
