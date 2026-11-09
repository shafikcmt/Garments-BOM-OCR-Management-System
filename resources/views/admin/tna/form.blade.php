@extends('layouts.app')

@section('title', $template->exists ? 'Edit TnA Template' : 'New TnA Template')

@section('content')
@php
    $rows = old('tasks', $tasks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'anchor' => $t->anchor, 'offset_days' => $t->offset_days, 'assignee_role' => $t->assignee_role, 'is_critical' => $t->is_critical])->all());
    if ($rows === []) {
        $rows = [['id' => null, 'title' => '', 'anchor' => 'ship_date', 'offset_days' => -30, 'assignee_role' => 'merchant']];
    }
    $roleLabel = fn ($r) => ucfirst(str_replace('_', ' ', $r));
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Admin', 'url' => route('admin.dashboard')], ['label' => 'TnA Templates', 'url' => route('admin.tna.index')], ['label' => $template->exists ? 'Edit' : 'New']]" />

    <x-page-header icon="calendar2-check" eyebrow="Admin / TnA Templates" :title="$template->exists ? 'Edit TnA Template' : 'New TnA Template'"
                   copy="Days before an anchor date are negative (-21 = 21 days before ship date); after are positive.">
        <x-slot:actions>
            <a href="{{ route('admin.tna.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Templates</a>
        </x-slot:actions>
    </x-page-header>

    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ $template->exists ? route('admin.tna.update', $template) : route('admin.tna.store') }}" id="tnaForm">
        @csrf
        @if($template->exists) @method('PUT') @endif

        <x-card class="mb-3" body-class="p-3">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1" for="tnaName">Template name</label>
                    <input type="text" name="name" id="tnaName" class="form-control" maxlength="120" required value="{{ old('name', $template->name) }}" placeholder="e.g. Standard 90-day order">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label mb-1" for="tnaBuyer">Buyer</label>
                    <select name="buyer_id" id="tnaBuyer" class="form-select">
                        <option value="">All buyers</option>
                        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected((int) old('buyer_id', $template->buyer_id) === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" value="1" id="tnaActive" @checked(old('is_active', $template->is_active))>
                        <label class="form-check-label" for="tnaActive">Active</label>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="auto_apply" value="1" id="tnaAuto" @checked(old('auto_apply', $template->auto_apply))>
                        <label class="form-check-label" for="tnaAuto">Apply automatically every morning</label>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card title="Tasks" body-class="p-3">
            <x-slot:actions>
                <button type="button" class="btn btn-outline-primary btn-sm" data-add-task><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Task</button>
            </x-slot:actions>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th>Task</th><th style="width:150px">Days</th><th style="width:190px">From</th><th style="width:200px">Given to role</th><th style="width:90px">Critical</th><th style="width:110px"></th></tr></thead>
                    <tbody data-tasks>
                    @foreach($rows as $i => $r)
                        <tr>
                            <td><input type="hidden" name="tasks[{{ $i }}][id]" value="{{ $r['id'] ?? '' }}">
                                <input type="text" name="tasks[{{ $i }}][title]" class="form-control form-control-sm" maxlength="160" required value="{{ $r['title'] }}" placeholder="e.g. Fabric booking"></td>
                            <td><input type="number" name="tasks[{{ $i }}][offset_days]" class="form-control form-control-sm" min="-365" max="365" required value="{{ $r['offset_days'] }}"></td>
                            <td><select name="tasks[{{ $i }}][anchor]" class="form-select form-select-sm">@foreach($anchors as $k => $label)<option value="{{ $k }}" @selected($r['anchor'] === $k)>{{ $label }}</option>@endforeach</select></td>
                            <td><select name="tasks[{{ $i }}][assignee_role]" class="form-select form-select-sm">@foreach($roles as $role)<option value="{{ $role }}" @selected($r['assignee_role'] === $role)>{{ $roleLabel($role) }}</option>@endforeach</select></td>
                            <td><input type="hidden" name="tasks[{{ $i }}][is_critical]" value="0"><input type="checkbox" class="form-check-input" name="tasks[{{ $i }}][is_critical]" value="1" aria-label="Critical" @checked(! empty($r['is_critical']))></td>
                            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-task><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">Each task goes to someone with that role who can see the buyer (the person applying, if they have the role). Tick Critical for the steps the shipment depends on; the style page shows the order at risk when one of them is late.</p>
        </x-card>

        <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Template</button></div>
    </form>
</div>

<template id="tnaRow">
    <tr>
        <td><input type="hidden" name="tasks[__I__][id]" value=""><input type="text" name="tasks[__I__][title]" class="form-control form-control-sm" maxlength="160" required placeholder="e.g. Trims in-house"></td>
        <td><input type="number" name="tasks[__I__][offset_days]" class="form-control form-control-sm" min="-365" max="365" required value="0"></td>
        <td><select name="tasks[__I__][anchor]" class="form-select form-select-sm">@foreach($anchors as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></td>
        <td><select name="tasks[__I__][assignee_role]" class="form-select form-select-sm">@foreach($roles as $role)<option value="{{ $role }}">{{ $roleLabel($role) }}</option>@endforeach</select></td>
        <td><input type="hidden" name="tasks[__I__][is_critical]" value="0"><input type="checkbox" class="form-check-input" name="tasks[__I__][is_critical]" value="1" aria-label="Critical"></td>
        <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-task><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
    </tr>
</template>
<script>
(function () {
    var form = document.getElementById('tnaForm'), body = form.querySelector('[data-tasks]'), next = {{ count($rows) }};
    form.addEventListener('click', function (e) {
        if (e.target.closest('[data-add-task]')) {
            body.insertAdjacentHTML('beforeend', document.getElementById('tnaRow').innerHTML.replace(/__I__/g, next++));
        }
        var rm = e.target.closest('[data-remove-task]');
        if (rm && body.querySelectorAll('tr').length > 1) { rm.closest('tr').remove(); }
    });
})();
</script>
@endsection
