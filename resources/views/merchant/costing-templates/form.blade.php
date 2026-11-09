@extends('layouts.app')

@section('title', $template->exists ? 'Edit Costing Template' : 'New Costing Template')

@section('content')
@php
    $rows = old('lines', $lines->map(fn ($l) => ['head' => $l->head, 'description' => $l->description, 'qty' => (float) $l->qty, 'unit' => $l->unit, 'wastage_pct' => (float) $l->wastage_pct, 'rate' => (float) $l->rate])->all());
    if ($rows === []) {
        $rows = [['head' => 'fabric', 'description' => '', 'qty' => 1, 'unit' => 'yds', 'wastage_pct' => 0, 'rate' => 0]];
    }
    $headSelect = function ($name, $selected) use ($heads) {
        $out = '<select name="' . $name . '" class="form-select form-select-sm">';
        foreach ($heads as $k => [$label]) {
            $out .= '<option value="' . $k . '"' . ($selected === $k ? ' selected' : '') . '>' . e($label) . '</option>';
        }

        return $out . '</select>';
    };
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Costing Templates', 'url' => route('merchant.costing_templates.index')], ['label' => $template->exists ? 'Edit' : 'New']]" />

    <x-page-header icon="file-earmark-ruled" eyebrow="Merchandising" :title="$template->exists ? 'Edit Costing Template' : 'New Costing Template'"
                   copy="Rates and quantities are the usual figures for this product group. A costing started from it can still be changed line by line." />

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" id="tplForm" action="{{ $template->exists ? route('merchant.costing_templates.update', $template) : route('merchant.costing_templates.store') }}">
        @csrf
        @if($template->exists) @method('PUT') @endif

        <x-card class="mb-3" body-class="p-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3"><label class="form-label mb-1" for="tName">Name *</label>
                    <input type="text" name="name" id="tName" class="form-control form-control-sm" maxlength="120" required value="{{ old('name', $template->name) }}"></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="tBuyer">Buyer</label>
                    <select name="buyer_id" id="tBuyer" class="form-select form-select-sm" @if(! $isAdmin) required @endif>
                        @if($isAdmin)<option value="">All buyers</option>@endif
                        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected((string) old('buyer_id', $template->buyer_id) === (string) $b->id)>{{ $b->buyer_name }}</option>@endforeach
                    </select></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="tGroup">Product group</label>
                    <input type="text" name="product_group" id="tGroup" list="tGroups" class="form-control form-control-sm" maxlength="120" placeholder="Empty = every group" value="{{ old('product_group', $template->product_group) }}">
                    <datalist id="tGroups">@foreach($groups as $g)<option value="{{ $g }}">@endforeach</datalist></div>
                <div class="col-4 col-md-1"><label class="form-label mb-1" for="tCur">Currency</label>
                    <select name="currency" id="tCur" class="form-select form-select-sm">@foreach(\App\Models\Costing::CURRENCIES as $c)<option @selected(old('currency', $template->currency ?: 'USD') === $c)>{{ $c }}</option>@endforeach</select></div>
                <div class="col-4 col-md-1"><label class="form-label mb-1" for="tBasis">Per</label>
                    <select name="basis" id="tBasis" class="form-select form-select-sm">@foreach(\App\Models\Costing::BASES as $k => $label)<option value="{{ $k }}" @selected(old('basis', $template->basis ?: 'pc') === $k)>{{ ucfirst($label) }}</option>@endforeach</select></div>
                <div class="col-4 col-md-2"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="is_active" id="tActive" value="1" @checked(old('is_active', $template->is_active))><label class="form-check-label" for="tActive">Active</label></div></div>
            </div>
        </x-card>

        <x-card class="mb-3" body-class="p-3">
            <x-slot:title>Lines</x-slot:title>
            <x-slot:actions><button type="button" class="btn btn-outline-primary btn-sm" data-add-line><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Line</button></x-slot:actions>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th style="width:200px">Head</th><th>Description</th><th style="width:100px">Qty</th><th style="width:80px">Unit</th><th style="width:100px">Wastage %</th><th style="width:110px">Rate</th><th style="width:100px"></th></tr></thead>
                    <tbody data-lines>
                    @foreach($rows as $i => $r)
                        <tr>
                            <td>{!! $headSelect("lines[$i][head]", $r['head'] ?? 'fabric') !!}</td>
                            <td><input type="text" name="lines[{{ $i }}][description]" class="form-control form-control-sm" maxlength="160" value="{{ $r['description'] ?? '' }}"></td>
                            <td><input type="number" step="any" min="0" name="lines[{{ $i }}][qty]" class="form-control form-control-sm text-end" value="{{ $r['qty'] ?? 1 }}"></td>
                            <td><input type="text" name="lines[{{ $i }}][unit]" class="form-control form-control-sm" maxlength="20" value="{{ $r['unit'] ?? '' }}"></td>
                            <td><input type="number" step="any" min="0" max="100" name="lines[{{ $i }}][wastage_pct]" class="form-control form-control-sm text-end" value="{{ $r['wastage_pct'] ?? 0 }}"></td>
                            <td><input type="number" step="any" min="0" name="lines[{{ $i }}][rate]" class="form-control form-control-sm text-end" value="{{ $r['rate'] ?? 0 }}"></td>
                            <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-line><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">Amount = qty x (1 + wastage %) x rate, worked out when a costing is started from the template.</p>
        </x-card>

        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Template</button>
            <a href="{{ route('merchant.costing_templates.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

<template id="tplRow">
    <tr>
        <td>{!! $headSelect('lines[__I__][head]', 'fabric') !!}</td>
        <td><input type="text" name="lines[__I__][description]" class="form-control form-control-sm" maxlength="160"></td>
        <td><input type="number" step="any" min="0" name="lines[__I__][qty]" class="form-control form-control-sm text-end" value="1"></td>
        <td><input type="text" name="lines[__I__][unit]" class="form-control form-control-sm" maxlength="20"></td>
        <td><input type="number" step="any" min="0" max="100" name="lines[__I__][wastage_pct]" class="form-control form-control-sm text-end" value="0"></td>
        <td><input type="number" step="any" min="0" name="lines[__I__][rate]" class="form-control form-control-sm text-end" value="0"></td>
        <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-line><i class="bi bi-trash me-1" aria-hidden="true"></i>Remove</button></td>
    </tr>
</template>
<script>
(function () {
    var form = document.getElementById('tplForm'), body = form.querySelector('[data-lines]'), next = {{ count($rows) }};
    form.addEventListener('click', function (e) {
        if (e.target.closest('[data-add-line]')) {
            body.insertAdjacentHTML('beforeend', document.getElementById('tplRow').innerHTML.replace(/__I__/g, next++));
        }
        var rm = e.target.closest('[data-remove-line]');
        if (rm && body.querySelectorAll('tr').length > 1) { rm.closest('tr').remove(); }
    });
})();
</script>
@endsection
