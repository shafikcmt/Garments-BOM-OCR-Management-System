@php
    $map = [
        'draft' => 'bg-secondary-subtle text-secondary-emphasis',
        'submitted' => 'bg-primary-subtle text-primary',
        'checked' => 'bg-info-subtle text-info-emphasis',
        'approved' => 'bg-success-subtle text-success',
        'issued' => 'bg-success-subtle text-success',
        'partially_received' => 'bg-warning-subtle text-warning-emphasis',
        'received' => 'bg-dark-subtle text-dark',
        'cancelled' => 'bg-danger-subtle text-danger',
    ];
@endphp
<span class="badge {{ $map[$status] ?? 'bg-secondary-subtle text-secondary-emphasis' }}">{{ $label }}</span>
