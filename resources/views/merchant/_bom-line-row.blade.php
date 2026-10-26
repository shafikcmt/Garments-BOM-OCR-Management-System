{{-- One order line of the BOM builder. --}}
<tr>
    <td><input type="text" name="lines[{{ $i }}][color]" class="form-control form-control-sm" maxlength="120" value="{{ $l['color'] ?? '' }}"></td>
    <td><input type="text" name="lines[{{ $i }}][po]" class="form-control form-control-sm" maxlength="80" value="{{ $l['po'] ?? '' }}"></td>
    <td><input type="date" name="lines[{{ $i }}][ship_date]" class="form-control form-control-sm" value="{{ $l['ship_date'] ?? '' }}"></td>
    <td><input type="number" min="1" step="1" name="lines[{{ $i }}][qty]" class="form-control form-control-sm" value="{{ $l['qty'] ?? '' }}" required></td>
    @if($children->isNotEmpty())
        <td>
            <select name="lines[{{ $i }}][child]" class="form-select form-select-sm">
                <option value="">None</option>
                @foreach($children as $c)<option value="{{ $c->id }}" {{ (string) ($l['child'] ?? '') === (string) $c->id ? 'selected' : '' }}>{{ $c->style_no }}</option>@endforeach
            </select>
        </td>
    @endif
    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm js-del-row"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
</tr>
