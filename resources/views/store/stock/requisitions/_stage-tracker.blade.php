{{--
    Where the requisition is in its approval chain: every stage in order, who
    did it and when, the stage it waits at, and any Return with its reason.
    Built by RequisitionWorkflow::tracker() from the stage plan and the
    history — nothing here is stored separately. Older rounds (after a
    return and resubmit) are folded away.

    Expects: $stageTracker, $requisition.
--}}
@php
    $states = [
        'done' => ['label' => 'Done', 'badge' => 'bg-success-subtle text-success', 'icon' => 'bi-check-circle-fill text-success'],
        'current' => ['label' => 'Waiting here', 'badge' => 'bg-primary text-white', 'icon' => 'bi-hourglass-split text-primary'],
        'waiting' => ['label' => 'Waiting', 'badge' => 'bg-light text-secondary border', 'icon' => 'bi-circle text-secondary'],
        'skipped' => ['label' => 'Skipped', 'badge' => 'bg-light text-secondary border', 'icon' => 'bi-dash-circle text-secondary'],
        'returned' => ['label' => 'Returned', 'badge' => 'bg-warning-subtle text-warning-emphasis', 'icon' => 'bi-arrow-return-left text-warning'],
        'closed' => ['label' => 'Cancelled', 'badge' => 'bg-danger-subtle text-danger', 'icon' => 'bi-x-circle-fill text-danger'],
        'not_reached' => ['label' => 'Not reached', 'badge' => 'bg-light text-secondary border', 'icon' => 'bi-circle text-secondary'],
    ];
@endphp

@if(! empty($stageTracker))
<div class="card gx-stock-card mb-4" id="stageTracker">
    <div class="gx-stock-card-body">
        <div class="gx-stock-card-head">
            <h5 class="mb-0">Approval Progress</h5>
            @if($requisition->isCancelled())
                <span class="badge bg-danger-subtle text-danger">Cancelled</span>
            @endif
        </div>

        @foreach($stageTracker as $round)
            @php($rows = $round['rows'])
            @if($round['active'])
                @include('store.stock.requisitions._stage-tracker-rows', ['rows' => $rows, 'states' => $states, 'requisition' => $requisition])
            @else
                <details class="mt-3">
                    <summary class="small text-muted">Round {{ $round['cycle'] }} (earlier, sent back)</summary>
                    <div class="mt-2">
                        @include('store.stock.requisitions._stage-tracker-rows', ['rows' => $rows, 'states' => $states, 'requisition' => $requisition])
                    </div>
                </details>
            @endif
        @endforeach
    </div>
</div>
@endif
