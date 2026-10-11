{{-- One round of the stage tracker. Expects: $rows, $states, $requisition. --}}
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Stage</th>
                <th>Status</th>
                <th>Person</th>
                <th style="width:150px;">Date &amp; Time</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @php($s = $states[$row['state']])
                <tr class="{{ $row['state'] === 'current' ? 'table-primary' : '' }}">
                    <td class="text-muted small">{{ $row['sequence'] }}</td>
                    <td class="fw-semibold">
                        <i class="bi {{ $s['icon'] }} me-1" aria-hidden="true"></i>{{ $row['label'] }}
                    </td>
                    <td><span class="badge {{ $s['badge'] }}">{{ $s['label'] }}</span></td>
                    <td>{{ $row['who'] ?: '—' }}</td>
                    <td class="text-nowrap small">{{ $row['at'] ? $row['at']->format('d M Y, h:i A') : '—' }}</td>
                    <td class="small">
                        @if($row['comment'])
                            {{ $row['comment'] }}
                        @endif
                        @if($row['return'])
                            <span class="d-block text-warning-emphasis">
                                Returned by {{ $row['return']['by'] }} on {{ $row['return']['at']?->format('d M Y') }}@if($row['return']['to']), sent to {{ $row['return']['to'] }}@endif.
                                @if($row['return']['reason']) Reason: {{ $row['return']['reason'] }} @endif
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
