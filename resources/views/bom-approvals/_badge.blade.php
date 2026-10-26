{{-- Status badge of a BOM approval. $state: not_submitted | pending | returned | approved | changed. $approval optional (for the level). --}}
@php
    $map = [
        'not_submitted' => ['Not submitted', 'bg-light text-muted border'],
        'pending' => ['Waiting for ' . (isset($approval) ? $approval->levelLabel() : 'approval'), 'bg-warning-subtle text-warning'],
        'returned' => ['Returned', 'bg-danger-subtle text-danger'],
        'approved' => ['Approved', 'bg-success-subtle text-success'],
        'changed' => ['Changed after approval', 'bg-danger-subtle text-danger'],
    ];
    [$label, $class] = $map[$state] ?? $map['not_submitted'];
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
