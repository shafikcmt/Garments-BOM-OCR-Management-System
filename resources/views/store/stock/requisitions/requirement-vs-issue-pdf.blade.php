{{--
    Requirement vs Issue — A4 landscape. Same rows, filters and totals as the
    screen and the Excel. The <thead> (title block + column header) repeats on
    every page; rows never split across a page.
--}}
@php
    $qty = fn ($v) => $v === null ? '—' : (rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.') ?: '0');
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%';
    $filtersLine = str_replace(' · '.$period, '', $title);
    $sectionLine = $sections->count() === 1 ? $sections->first()['section_name'] : 'All';
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Requirement vs Issue</title>
<style>
    @page { margin: 12mm 8mm 12mm 8mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 7.2pt; color: #111; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 0.5pt solid #888; padding: 2.5pt 3pt; vertical-align: top; word-wrap: break-word; }
    thead th.col { background: #e8eef7; font-weight: bold; text-align: center; vertical-align: middle; }
    thead td.head { border: none; text-align: center; padding: 1pt; }
    tr { page-break-inside: avoid; }
    .r { text-align: right; white-space: nowrap; }
    .c { text-align: center; }
    .neg { color: #c62828; }
    .total td { background: #fff4ce; font-weight: bold; }
    .company { font-size: 12pt; font-weight: bold; }
    .title { font-size: 9.5pt; font-weight: bold; }
    .filters { font-size: 7pt; font-style: italic; color: #444; }
    .note { margin-top: 6pt; font-size: 7pt; color: #444; }
</style>
</head>
<body>
@php
    $totals = '<tr class="total"><td></td><td colspan="5">Total</td>'
        .'<td class="r">'.$qty($summary['requested_qty']).'</td>'
        .'<td class="r">'.$money($summary['requested_value']).'</td>'
        .'<td class="r">'.$qty($summary['issued_qty']).'</td>'
        .'<td class="r">'.$money($summary['issued_value']).'</td>'
        .'<td class="r'.($summary['balance_qty'] < 0 ? ' neg' : '').'">'.$qty($summary['balance_qty']).'</td>'
        .'<td class="r'.($summary['balance_value'] < 0 ? ' neg' : '').'">'.$money($summary['balance_value']).'</td>'
        .'<td class="r">'.$pct($summary['pct']).'</td><td></td></tr>';
@endphp
<table>
    <colgroup>
        <col style="width:3%"><col style="width:17%"><col style="width:4.5%"><col style="width:11%"><col style="width:10%">
        <col style="width:6%"><col style="width:6%"><col style="width:7.5%"><col style="width:6%"><col style="width:7.5%">
        <col style="width:6%"><col style="width:7%"><col style="width:4.5%"><col style="width:7%">
    </colgroup>
    <thead>
        <tr><td class="head company" colspan="14">{{ config('stock.company_name', 'Humana Apparels Pvt. Ltd.') }}</td></tr>
        <tr><td class="head title" colspan="14">Purchase Requisition vs Issue ({{ $period }})</td></tr>
        <tr><td class="head title" colspan="14">Dept. of {{ $sectionLine }}</td></tr>
        @if($filtersLine !== $period)
            <tr><td class="head filters" colspan="14">Filters: {{ $filtersLine }}</td></tr>
        @endif
        <tr>
            <th class="col">SL</th><th class="col">Item Name</th><th class="col">UOM</th><th class="col">Specification / Brand</th>
            <th class="col">User Dept./Section</th><th class="col">Approx Rate</th><th class="col">Requested Qty</th>
            <th class="col">Requested Value (BDT)</th><th class="col">Issued Qty</th><th class="col">Issued Value (BDT)</th>
            <th class="col">Issue Bal. Qty</th><th class="col">Issue Bal. Value</th><th class="col">Issued %</th><th class="col">Status</th>
        </tr>
    </thead>
    <tbody>
        @if($rows->isEmpty())
            <tr><td colspan="14" class="c">No matching items</td></tr>
        @else
            {!! $totals !!}
            @foreach($rows as $i => $row)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $row['item_name'] }}</td>
                    <td>{{ $row['uom'] ?: '—' }}</td>
                    <td>{{ $row['specification'] ?: '—' }}</td>
                    <td>{{ $row['section_name'] }}</td>
                    <td class="r">{{ $money($row['rate']) }}</td>
                    <td class="r">{{ $qty($row['requested_qty']) }}</td>
                    <td class="r">{{ $money($row['requested_value']) }}</td>
                    <td class="r">{{ $qty($row['issued_qty']) }}</td>
                    <td class="r">{{ $money($row['issued_value']) }}</td>
                    <td class="r {{ $row['balance_qty'] < 0 ? 'neg' : '' }}">{{ $qty($row['balance_qty']) }}</td>
                    <td class="r {{ ($row['balance_value'] ?? 0) < 0 ? 'neg' : '' }}">{{ $money($row['balance_value']) }}</td>
                    <td class="r">{{ $pct($row['pct']) }}</td>
                    <td>{{ $row['status_label'] }}</td>
                </tr>
            @endforeach
            {!! $totals !!}
        @endif
    </tbody>
</table>

<div class="note">
    Issued % = issued value of requested items ÷ requested value.
    Requested quantities come from department requisitions; final-sheet items not linked to a section are shown as Not assigned.
    @if($summary['not_requested_qty'] > 0)
        Not Requested (issued without a requisition): {{ $qty($summary['not_requested_qty']) }} qty, {{ $money($summary['not_requested_value']) }} BDT — not in the totals or Issued %.
    @endif
</div>
</body>
</html>
