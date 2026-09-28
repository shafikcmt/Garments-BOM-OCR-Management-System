{{--
    The requisition's history, newest first: who did what, at which stage,
    and why. Return reasons stay here after the sheet is resubmitted, so the
    requester can still see why it came back.

    Expects: $requisition with actions.user loaded.
--}}
@php
    $actions = $requisition->actions;
    $badge = [
        'submitted' => 'bg-primary-subtle text-primary',
        'resubmitted' => 'bg-primary-subtle text-primary',
        'head_approved' => 'bg-success-subtle text-success',
        'approved' => 'bg-success-subtle text-success',
        'received' => 'bg-info-subtle text-info-emphasis',
        'head_returned' => 'bg-danger-subtle text-danger',
        'deleted' => 'bg-danger-subtle text-danger',
        'corrected' => 'bg-warning-subtle text-warning-emphasis',
    ];
    $qty = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $recordedFrom = \Illuminate\Support\Carbon::parse(\App\Models\PurchaseRequisitionAction::RECORDED_FROM);
    $predates = $requisition->created_at && $requisition->created_at->lt($recordedFrom);
@endphp

<div class="card gx-stock-card mt-4">
    <div class="gx-stock-card-body">
        <div class="gx-stock-card-head">
            <h5 class="mb-0">History</h5>
            @if($predates)
                <span class="text-muted small">History recorded from {{ $recordedFrom->format('d M Y') }}. Earlier steps are taken from the sheet's sign-off stamps.</span>
            @endif
        </div>

        @if($actions->isEmpty())
            <p class="text-muted small mb-0">No history recorded yet.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:150px;">Date &amp; Time</th>
                            <th>Action</th>
                            <th>Stage</th>
                            <th>By</th>
                            <th>Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($actions as $action)
                            <tr>
                                <td class="text-nowrap small">{{ optional($action->created_at)->format('d M Y, h:i A') ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $badge[$action->action] ?? 'bg-light text-dark' }}">{{ $action->actionLabel() }}</span>
                                    @if($action->cycle > 1)
                                        <span class="text-muted small ms-1">Round {{ $action->cycle }}</span>
                                    @endif
                                </td>
                                <td class="small">{{ $action->stageLabel() ?? '—' }}</td>
                                <td class="small">{{ $action->actorName() }}</td>
                                <td class="small">
                                    {{ $action->comment ?? '' }}
                                    @if($action->action === 'corrected' && ! empty($action->meta['lines']))
                                        <ul class="list-unstyled mb-0 mt-1 text-muted">
                                            @foreach($action->meta['lines'] as $change)
                                                <li>
                                                    {{ $change['item'] }}:
                                                    @if($change['change'] === 'added')
                                                        added ({{ $qty($change['new_qty']) }})
                                                    @elseif($change['change'] === 'removed')
                                                        removed (was {{ $qty($change['old_qty']) }})
                                                    @else
                                                        {{ $qty($change['old_qty']) }} → {{ $qty($change['new_qty']) }}
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @elseif(! $action->comment)
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
