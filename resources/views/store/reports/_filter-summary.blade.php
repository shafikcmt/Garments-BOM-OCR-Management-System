{{-- Applied-filter line shared by the PDF and Excel headers. --}}
@php
    // Every active filter has to appear here: a filtered export that prints
    // "All records" misrepresents what the figures below it cover.
    $parts = collect([
        'Buyer' => $filters['buyer'] ?? null,
        'Style' => $filters['style'] ?? null,
        'Season' => $filters['season'] ?? null,
        'PO No' => $filters['po_no'] ?? null,
        'GMTS Color' => $filters['gmts_color'] ?? null,
        'Material' => $filters['material'] ?? null,
    ])->filter()->map(fn ($value, $label) => "{$label}: {$value}");

    $from = $filters['date_from'] ?? null;
    $to = $filters['date_to'] ?? null;

    if ($from || $to) {
        $parts->push('Period: ' . ($from ?: 'Beginning') . ' to ' . ($to ?: 'Today'));
    }
@endphp
{{ $parts->isEmpty() ? 'Filters: All records' : $parts->implode('  |  ') }}
