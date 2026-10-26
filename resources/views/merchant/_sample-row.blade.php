{{-- One sample row with its update form underneath. Expects $s (Sample), $canEdit, $showStyle. --}}
@php($late = $s->isLate())
<tr>
    @if($showStyle)
        <td class="fw-semibold text-nowrap">
            <a href="{{ route(auth()->user()->hasRole('merchant') ? 'merchant.styles.mothers.show' : 'merchant.status.style', $s->motherStyle) }}" class="text-decoration-none">{{ $s->motherStyle->style_no }}</a>
            <div class="small text-muted">{{ $s->motherStyle->buyer?->buyer_name }} · {{ $s->motherStyle->season?->code }}</div>
        </td>
    @endif
    <td class="fw-semibold">{{ $s->type?->name }}@if($s->childStyle)<span class="badge bg-light text-dark border ms-1">{{ $s->childStyle->style_no }}</span>@endif</td>
    <td>{{ $s->gmts_color ?: '—' }}{{ $s->size ? ' · ' . $s->size : '' }}</td>
    <td class="text-end">{{ $s->qty }}</td>
    <td class="text-nowrap {{ $late ? 'text-danger fw-semibold' : '' }}">{{ $s->required_date?->format('d M Y') ?? '—' }}@if($late)<div class="small">Late</div>@endif</td>
    <td class="text-nowrap">{{ $s->sent_date?->format('d M Y') ?? '—' }}@if($s->courier_awb)<div class="small text-muted">{{ $s->courier_awb }}</div>@endif</td>
    <td><span class="badge {{ $s->badgeClass() }}">{{ \App\Models\Sample::STATUSES[$s->status] ?? $s->status }}</span></td>
    <td class="small">{{ $s->buyer_comment ?: '—' }}@if($s->comment_date)<div class="text-muted">{{ $s->comment_date->format('d M Y') }}</div>@endif</td>
    <td class="text-end text-nowrap">
        @if($canEdit)
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#sampleEdit{{ $s->id }}"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Update</button>
        @endif
    </td>
</tr>
@if($canEdit)
    <tr class="collapse" id="sampleEdit{{ $s->id }}">
        <td colspan="{{ $showStyle ? 9 : 8 }}">
            <form method="POST" action="{{ route('merchant.samples.update', $s) }}" class="row g-2 align-items-end">
                @csrf @method('PUT')
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        @foreach(\App\Models\Sample::STATUSES as $k => $label)<option value="{{ $k }}" @selected($s->status === $k)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small">Sent date</label>
                    <input type="date" name="sent_date" class="form-control form-control-sm" value="{{ $s->sent_date?->toDateString() }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1 small">Courier / AWB</label>
                    <input type="text" name="courier_awb" class="form-control form-control-sm" maxlength="120" value="{{ $s->courier_awb }}">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1 small">Buyer comment</label>
                    <input type="text" name="buyer_comment" class="form-control form-control-sm" maxlength="2000" value="{{ $s->buyer_comment }}">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save</button>
                </div>
            </form>
            <form method="POST" action="{{ route('merchant.samples.destroy', $s) }}" class="mt-2" onsubmit="return confirm('Delete this sample?')">
                @csrf @method('DELETE')
                <button class="btn btn-link btn-sm text-danger p-0"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Sample</button>
            </form>
        </td>
    </tr>
@endif
