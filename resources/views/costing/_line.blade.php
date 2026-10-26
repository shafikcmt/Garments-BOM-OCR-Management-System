{{-- One cost line. $line is null for the blank template row. --}}
<tr>
    <td>
        <input type="hidden" name="lines[{{ $i }}][head]" value="{{ $head }}">
        <input type="text" name="lines[{{ $i }}][description]" class="form-control form-control-sm" maxlength="160" value="{{ $line?->description }}" {{ $editable ? '' : 'disabled' }}>
    </td>
    <td><input type="number" step="0.0001" min="0" data-f="qty" name="lines[{{ $i }}][qty]" class="form-control form-control-sm" value="{{ $line ? (float) $line->qty : 1 }}" {{ $editable ? '' : 'disabled' }}></td>
    <td><input type="text" name="lines[{{ $i }}][unit]" class="form-control form-control-sm" maxlength="20" value="{{ $line?->unit }}" {{ $editable ? '' : 'disabled' }}></td>
    <td><input type="number" step="0.001" min="0" max="100" data-f="wastage" name="lines[{{ $i }}][wastage_pct]" class="form-control form-control-sm" value="{{ $line ? (float) $line->wastage_pct : 0 }}" {{ $editable ? '' : 'disabled' }}></td>
    <td><input type="number" step="0.0001" min="0" data-f="rate" name="lines[{{ $i }}][rate]" class="form-control form-control-sm" value="{{ $line ? (float) $line->rate : 0 }}" {{ $editable ? '' : 'disabled' }}></td>
    <td class="text-end" data-amount>{{ $line ? number_format((float) $line->amount, 2) : '0.00' }}</td>
    @if($editable)
        <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm js-del-line"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
    @endif
</tr>
