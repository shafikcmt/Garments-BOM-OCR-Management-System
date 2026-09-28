{{--
    The Issued % cell: the figure with a thin bar under it. Red when the
    balance is negative (over-issued), grey when nothing was issued. A row
    with nothing requested has no percentage, so it prints a dash.

    Expects: $row (a Requirement vs Issue row)
--}}
@if($row['pct'] === null)
    —
@else
    @php($tone = $row['balance_qty'] < 0 ? 'is-over' : ($row['status'] === \App\Services\RequirementVsIssueService::STATUS_NOT_ISSUED ? 'is-none' : ''))
    <div class="rvi-pct {{ $tone }}">
        {{ number_format((float) $row['pct'], 1) }}%
        <div class="rvi-pct-bar" aria-hidden="true"><span style="width: {{ min(100, max(0, (float) $row['pct'])) }}%"></span></div>
    </div>
@endif
@include('store.stock.requisitions._rvi-chart-styles')
