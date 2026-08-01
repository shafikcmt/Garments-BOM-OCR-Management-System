{{-- Receiving History table rows.

     Extracted so the first page load and the AJAX filter endpoint render the
     exact same markup from one place — a row can never drift between the two.

     Expects: $receivings (paginator), $canEdit, $canDelete, $activeFilters. --}}
@forelse($receivings as $r)
    <tr>
        <td class="small">{{ optional($r->receive_date)->format('d-M-Y') ?? '—' }}</td>
        <td class="small">
            <span class="fw-semibold text-nowrap d-block">{{ $r->grn_no ?: '—' }}</span>
            {{-- Only worth showing when it differs from the
                 Date column already on the left. --}}
            @if($r->grn_date && optional($r->receive_date)->toDateString() !== $r->grn_date->toDateString())
                <span class="text-muted d-block">GRN dt: {{ $r->grn_date->format('d-M-Y') }}</span>
            @endif
            @if($r->invoice_no)<span class="text-muted">Inv: {{ $r->invoice_no }}</span>@endif
        </td>
        <td>
            <div class="fw-semibold">
                @if($r->isIndependent())
                    {{-- No PO number to show yet, so the badge
                         takes its place rather than leaving a
                         bare separator. --}}
                    <span class="badge bg-warning-subtle text-warning-emphasis me-1">Independent</span>
                @else
                    {{ $r->po_no }} ·
                @endif
                {{ $r->material_name ?: $r->material_description }}
            </div>
            <div class="small text-muted">{{ collect([$r->buyer_name.' / '.$r->style_name, $r->material_color, $r->size])->filter()->implode(' · ') }}</div>
            @if($r->isIndependent())
                <div class="small text-warning-emphasis">Not counted in closing stock until linked to a PO.</div>
            @endif
        </td>
        {{-- Booking vs Internal PO read as two different
             routes into stock, so they get two tones
             rather than one shared badge colour. --}}
        <td>
            @if($r->source_type == 'internal_po')
                <span class="badge bg-success-subtle text-success-emphasis">Internal PO</span>
            @else
                <span class="badge bg-primary-subtle text-primary-emphasis">Booking</span>
            @endif
        </td>
        <td class="text-end small">{{ $r->invoice_qty !== null ? rtrim(rtrim(number_format((float)$r->invoice_qty, 4), '0'), '.') : '—' }}</td>
        <td class="text-end fw-bold">
            {{ rtrim(rtrim(number_format((float)$r->qty, 4), '0'), '.') }}
            {{-- Packing info sits with the physical qty it
                 describes rather than taking its own column. --}}
            @if($r->roll_bale)<div class="small text-muted fw-normal">{{ $r->roll_bale }} roll/bale</div>@endif
        </td>
        <td class="text-end small">
            {{ $r->unit_price !== null ? number_format((float)$r->unit_price, 4) : '—' }}
            @if($r->invoice_value !== null)<div class="text-muted">Val: {{ number_format((float)$r->invoice_value, 2) }}</div>@endif
        </td>
        <td class="text-end">
            {{-- Corrections are an Admin / Management right
                 (store.edit / store.delete). The buttons are
                 absent for everyone else rather than disabled,
                 and the controller re-checks server-side. --}}
            <div class="d-flex justify-content-end align-items-center gap-2">
                @if($canEdit && $r->isIndependent())
                    {{-- The deliberate, human-confirmed re-match.
                         There is no automatic OCR matcher in this
                         app, and guessing the BOM line would book
                         stock onto the wrong row. --}}
                    <button type="button" class="btn btn-sm btn-outline-primary text-nowrap"
                            data-rcv-link="{{ $r->id }}"
                            data-rcv-grn="{{ $r->grn_no }}"
                            data-rcv-style="{{ $r->buyer_name }} / {{ $r->style_name }}">
                        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Link to PO
                    </button>
                @endif
                @if($canDelete)
                    <form method="POST" action="{{ route('store.material.receivings.destroy', $r) }}" onsubmit="return confirm('Remove this receiving? Closing stock will update.');">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger text-nowrap"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                    </form>
                @endif
                @if(! $canEdit && ! $canDelete)
                    <span class="text-muted small">—</span>
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr><td colspan="8" class="text-center text-muted py-5">
        {{ $activeFilters ? 'No receiving matches this filter.' : 'No receiving recorded yet.' }}
    </td></tr>
@endforelse
