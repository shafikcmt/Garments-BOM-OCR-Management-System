@php $removedEntries = $paymentRequest->removedLines(); @endphp
@if(count($removedEntries))
    <div class="card border-0 shadow-sm mb-4 pra-no-print" style="border-radius:var(--gx-radius);">
        <div class="card-body p-4">
            <h5 class="mb-3"><i class="bi bi-scissors me-1" aria-hidden="true"></i> Lines Removed ({{ count($removedEntries) }})</h5>
            @foreach(array_reverse($removedEntries) as $entry)
                <div class="border rounded-3 px-3 py-2 mb-2">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div class="fw-semibold small">
                            {{ $entry['by_name'] ?? '—' }}
                            @if(! empty($entry['cancelled']))<span class="badge bg-dark-subtle text-dark-emphasis ms-1">PRA cancelled</span>@endif
                        </div>
                        <div class="small text-muted">{{ \Illuminate\Support\Carbon::parse($entry['at'])->format('d M Y H:i') }}</div>
                    </div>
                    <div class="small mt-1">Reason: {{ $entry['reason'] ?? '—' }}</div>
                    <div class="small text-muted mt-1">
                        @php $entryLines = collect($entry['lines'] ?? []); @endphp
                        {{ $entryLines->count() }} line(s) · $ {{ number_format((float) $entryLines->sum('amount'), 2) }}
                        — {{ $entryLines->pluck('vendor')->filter()->unique()->implode(', ') ?: '—' }}
                        ({{ $entryLines->map(fn ($l) => trim(($l['po_no'] ?? '') . ' / ' . ($l['pi_number'] ?? ''), ' /'))->unique()->take(6)->implode(', ') }}{{ $entryLines->count() > 6 ? ' …' : '' }})
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
