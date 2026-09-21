{{--
    One signature box on the settings screen. Also the template a new box is
    cloned from (level / index are then placeholders the script fills in).

    Expects: $level, $index, $row (label, title, assignee_type, role_name,
    user_id, stamp_source), plus $assigneeTypes, $stampSources, $roles, $users
    from the page.
--}}
@php
    $n = 'blocks['.$level.']['.$index.']';
    $type = $row['assignee_type'] ?? 'user';
@endphp
<tr>
    <td class="fw-semibold text-muted" data-seq>{{ is_numeric($index) ? $index + 1 : '' }}</td>
    <td>
        <input type="text" name="{{ $n }}[label]" class="form-control form-control-sm" value="{{ $row['label'] ?? '' }}" maxlength="80" placeholder="Checked by" required>
    </td>
    <td>
        <input type="text" name="{{ $n }}[title]" class="form-control form-control-sm" value="{{ $row['title'] ?? '' }}" maxlength="80" placeholder="e.g. Store Head">
    </td>
    <td>
        <select name="{{ $n }}[assignee_type]" class="form-select form-select-sm" data-field="assignee_type">
            @foreach($assigneeTypes as $value => $label)
                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </td>
    <td>
        <div data-role-picker class="{{ $type === 'role' ? '' : 'd-none' }}">
            <select name="{{ $n }}[role_name]" class="form-select form-select-sm">
                <option value="">— Choose role —</option>
                @foreach($roles as $value => $label)
                    <option value="{{ $value }}" @selected(($row['role_name'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div data-user-picker class="{{ $type === 'user' ? '' : 'd-none' }}">
            <select name="{{ $n }}[user_id]" class="form-select form-select-sm">
                <option value="">— Choose user —</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" @selected((string) ($row['user_id'] ?? '') === (string) $u->id)>{{ $u->name }}{{ trim((string) ($u->designation ?? '')) !== '' ? ' — '.$u->designation : '' }}</option>
                @endforeach
            </select>
        </div>
        <div data-no-picker class="text-muted small {{ in_array($type, ['role', 'user'], true) ? 'd-none' : '' }}">Resolved per requisition</div>
    </td>
    <td>
        <select name="{{ $n }}[stamp_source]" class="form-select form-select-sm">
            @foreach($stampSources as $value => $label)
                <option value="{{ $value }}" @selected((string) ($row['stamp_source'] ?? '') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>
    </td>
    <td class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-move-up><i class="bi bi-arrow-up me-1" aria-hidden="true"></i>Up</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-move-down><i class="bi bi-arrow-down me-1" aria-hidden="true"></i>Down</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-block><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Remove</button>
    </td>
</tr>
