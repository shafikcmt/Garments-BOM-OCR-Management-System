{{-- One material of the BOM builder. --}}
<tr>
    <td><input type="text" name="materials[{{ $i }}][type]" list="materialTypes" class="form-control form-control-sm" maxlength="60" value="{{ $m['type'] ?? '' }}"></td>
    <td><input type="text" name="materials[{{ $i }}][description]" class="form-control form-control-sm" maxlength="255" value="{{ $m['description'] ?? '' }}" required></td>
    <td><input type="text" name="materials[{{ $i }}][sap_code]" class="form-control form-control-sm" maxlength="60" value="{{ $m['sap_code'] ?? '' }}"></td>
    <td><input type="text" name="materials[{{ $i }}][color]" class="form-control form-control-sm" maxlength="120" value="{{ $m['color'] ?? '' }}"></td>
    <td><input type="text" name="materials[{{ $i }}][uom]" list="units" class="form-control form-control-sm" maxlength="20" value="{{ $m['uom'] ?? '' }}"></td>
    <td><input type="number" min="0" step="0.0001" name="materials[{{ $i }}][consumption]" class="form-control form-control-sm" value="{{ $m['consumption'] ?? '' }}" required></td>
    <td><input type="number" min="0" max="100" step="0.01" name="materials[{{ $i }}][wastage]" class="form-control form-control-sm" value="{{ $m['wastage'] ?? '' }}"></td>
    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm js-del-row"><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
</tr>
