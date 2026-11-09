{{-- Action rows of a meeting. Needs $people (id, name) and $today. Rows with an empty action are ignored. --}}
@php($rows = old('actions', [['title' => '', 'assigned_to' => '', 'due_date' => '']]))
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="min-width:0">
        <thead><tr><th>Action</th><th style="width:220px">Given to</th><th style="width:160px">Due</th><th style="width:100px"></th></tr></thead>
        <tbody data-actions>
        @foreach($rows as $i => $a)
            <tr>
                <td><input type="text" name="actions[{{ $i }}][title]" class="form-control form-control-sm" maxlength="160" value="{{ $a['title'] ?? '' }}" placeholder="e.g. Confirm trims in-house date"></td>
                <td><select name="actions[{{ $i }}][assigned_to]" class="form-select form-select-sm"><option value="">Choose</option>@foreach($people as $p)<option value="{{ $p->id }}" @selected((string) ($a['assigned_to'] ?? '') === (string) $p->id)>{{ $p->name }}</option>@endforeach</select></td>
                <td><input type="date" name="actions[{{ $i }}][due_date]" class="form-control form-control-sm" min="{{ $today->toDateString() }}" value="{{ $a['due_date'] ?? '' }}"></td>
                <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-action><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<button type="button" class="btn btn-outline-primary btn-sm mt-2" data-add-action><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Action</button>
<template data-action-row>
    <tr>
        <td><input type="text" name="actions[__I__][title]" class="form-control form-control-sm" maxlength="160" placeholder="e.g. Confirm trims in-house date"></td>
        <td><select name="actions[__I__][assigned_to]" class="form-select form-select-sm"><option value="">Choose</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></td>
        <td><input type="date" name="actions[__I__][due_date]" class="form-control form-control-sm" min="{{ $today->toDateString() }}"></td>
        <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-action><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
    </tr>
</template>
<script>
(function () {
    var scope = document.currentScript.parentElement, body = scope.querySelector('[data-actions]'), next = {{ count($rows) }};
    scope.addEventListener('click', function (e) {
        if (e.target.closest('[data-add-action]')) {
            body.insertAdjacentHTML('beforeend', scope.querySelector('[data-action-row]').innerHTML.replace(/__I__/g, next++));
        }
        var rm = e.target.closest('[data-remove-action]');
        if (rm && body.querySelectorAll('tr').length > 1) { rm.closest('tr').remove(); }
    });
})();
</script>
