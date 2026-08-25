{{-- Applied-filter line shared by the PDF and Excel headers. --}}
@php
    // Every active filter has to appear here: a filtered export that prints
    // "All records" misrepresents the lines below it. Labels come from the
    // headers themselves, so a renamed column renames its own filter line.
    $parts = collect([
        'buyer' => $filters['buyer'] ?? null,
        'style' => $filters['style'] ?? null,
        'payment_status' => $filters['payment_status'] ?? null,
        'sg_status' => $filters['sg_status'] ?? null,
        'docs_status' => $filters['docs_status'] ?? null,
    ])->filter()->map(fn ($value, $key) => $reports->filterLabel($key) . ': ' . $value);

    if ($material = ($filters['material'] ?? null)) {
        $parts->push('Material / SAP: ' . $material);
    }

    $from = $filters['date_from'] ?? null;
    $to = $filters['date_to'] ?? null;

    if ($from || $to) {
        $parts->push($reports->dateLabel() . ': ' . ($from ?: 'Beginning') . ' to ' . ($to ?: 'Today'));
    }
@endphp
{{ $parts->isEmpty() ? 'Filters: All records' : $parts->implode('  |  ') }}
