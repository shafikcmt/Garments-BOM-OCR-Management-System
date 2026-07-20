{{-- Applied-filter line shared by the PDF and Excel headers. --}}
@php
    $parts = collect([
        'Buyer' => $filters['buyer'] ?? null,
        'Style' => $filters['style'] ?? null,
        'Material' => $filters['material'] ?? null,
    ])->filter()->map(fn ($value, $label) => "{$label}: {$value}");

    $from = $filters['date_from'] ?? null;
    $to = $filters['date_to'] ?? null;

    if ($from || $to) {
        $parts->push('Period: ' . ($from ?: 'Beginning') . ' to ' . ($to ?: 'Today'));
    }
@endphp
{{ $parts->isEmpty() ? 'Filters: All records' : $parts->implode('  |  ') }}
