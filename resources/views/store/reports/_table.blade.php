{{--
    Shared report table — the single source of truth for screen preview, PDF and
    Excel. Emits plain semantic markup only; each medium supplies its own CSS, so
    the three outputs can never show different numbers or columns.

    Numbers are formatted without thousand separators on purpose: it keeps the
    text identical everywhere and lets Excel read the cells as real numbers.
--}}
@php
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.') ?: '0';
    $money = fn ($v) => number_format((float) $v, 2, '.', '');
@endphp
<table class="report-table">
    <thead>
        <tr>
            <th class="col-sl">SL</th>
            <th class="col-group">{{ $groupHeading }}</th>
            <th class="num">Total Receive</th>
            <th class="num">Bulk Issue</th>
            <th class="num">Sample</th>
            <th class="num">Liability</th>
            <th class="num">Dead</th>
            <th class="num">Total Issue</th>
            <th class="num">Period Movement<span class="sub">Receive &minus; Issue</span></th>
            <th class="num">Current Stock Balance<span class="sub">Ledger &mdash; lifetime</span></th>
            <th class="num">Receive Value</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $index => $row)
            <tr>
                <td class="col-sl">{{ $index + 1 }}</td>
                <td class="col-group">{{ $row['label'] }}</td>
                <td class="num">{{ $qty($row['receive_qty']) }}</td>
                <td class="num">{{ $qty($row['bulk_qty']) }}</td>
                <td class="num">{{ $qty($row['sample_qty']) }}</td>
                <td class="num">{{ $qty($row['liability_qty']) }}</td>
                <td class="num">{{ $qty($row['dead_qty']) }}</td>
                <td class="num">{{ $qty($row['total_issue']) }}</td>
                <td class="num">{{ $qty($row['period_movement']) }}</td>
                <td class="num">{{ $qty($row['ledger_balance']) }}</td>
                <td class="num">{{ $money($row['receive_value']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="11" class="empty">No records found for the selected filters.</td>
            </tr>
        @endforelse
    </tbody>
    @if($rows->isNotEmpty())
        <tfoot>
            <tr class="grand">
                <td colspan="2">Total</td>
                <td class="num">{{ $qty($totals['receive_qty']) }}</td>
                <td class="num">{{ $qty($totals['bulk_qty']) }}</td>
                <td class="num">{{ $qty($totals['sample_qty']) }}</td>
                <td class="num">{{ $qty($totals['liability_qty']) }}</td>
                <td class="num">{{ $qty($totals['dead_qty']) }}</td>
                <td class="num">{{ $qty($totals['total_issue']) }}</td>
                <td class="num">{{ $qty($totals['period_movement']) }}</td>
                <td class="num">{{ $qty($totals['ledger_balance']) }}</td>
                <td class="num">{{ $money($totals['receive_value']) }}</td>
            </tr>
        </tfoot>
    @endif
</table>
