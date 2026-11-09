@extends('layouts.app')

@section('title', 'Consumption Master')

@section('content')
@php($cons = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 6, '.', ''), '0'), '.'))
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Consumption Master']]" />

    <x-page-header icon="list-check" eyebrow="Merchandising" title="Consumption Master"
                   copy="Standard materials, consumption and wastage for each product group. Create BOM can fill a style from here; you check the figures before saving.">
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="cmBuyer">Buyer</label>
                <select name="buyer" id="cmBuyer" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All my buyers</option>
                    @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="cmQ">Search</label>
                <input type="search" name="q" id="cmQ" value="{{ $term }}" class="form-control form-control-sm" placeholder="Material, SAP code or product group">
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button></div>
        </form>
    </x-card>

    @if($canEdit)
        <x-card title="Add a material" class="mb-3" body-class="p-3">
            <form method="POST" action="{{ route('merchant.consumption.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-6 col-md-2">
                    <label class="form-label mb-1">Buyer</label>
                    <select name="buyer_id" class="form-select form-select-sm" required>
                        @if($isAdmin)<option value="">All buyers</option>@endif
                        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2"><label class="form-label mb-1">Product group</label>
                    <input type="text" name="product_group" list="cmGroups" class="form-control form-control-sm" maxlength="120" placeholder="Empty = every group"></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1">Type</label><input type="text" name="material_type" class="form-control form-control-sm" maxlength="60"></div>
                <div class="col-12 col-md-3"><label class="form-label mb-1">Description *</label><input type="text" name="material_description" class="form-control form-control-sm" maxlength="255" required></div>
                <div class="col-6 col-md-1"><label class="form-label mb-1">SAP code</label><input type="text" name="sap_code" class="form-control form-control-sm" maxlength="60"></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1">Material color</label><input type="text" name="material_color" class="form-control form-control-sm" maxlength="120"></div>
                <div class="col-4 col-md-1"><label class="form-label mb-1">Unit</label><input type="text" name="uom" class="form-control form-control-sm" maxlength="20"></div>
                <div class="col-4 col-md-2"><label class="form-label mb-1">Consumption *</label><input type="number" step="any" min="0" name="consumption" class="form-control form-control-sm text-end" required></div>
                <div class="col-4 col-md-1"><label class="form-label mb-1">Wastage %</label><input type="number" step="any" min="0" max="100" name="wastage_percent" class="form-control form-control-sm text-end"></div>
                <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Material</button></div>
            </form>
            <datalist id="cmGroups">@foreach($groups as $g)<option value="{{ $g }}">@endforeach</datalist>

            @if($styles->isNotEmpty())
                <hr>
                <form method="POST" action="{{ route('merchant.consumption.from_style') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-12 col-md-5">
                        <label class="form-label mb-1" for="cmStyle">Learn from the BOM of a style</label>
                        <select name="mother_style_id" id="cmStyle" class="form-select form-select-sm" required>
                            @foreach($styles as $s)<option value="{{ $s->id }}">{{ $s->buyer?->buyer_name }} · {{ $s->style_no }}{{ $s->product_group ? ' · ' . $s->product_group : '' }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-magic me-1" aria-hidden="true"></i>Add Its Materials</button></div>
                    <div class="col-12 form-text">Copies the style's materials into the master for the style's buyer and product group. Materials already there are not added again.</div>
                </form>
            @endif
        </x-card>
    @endif

    <x-card body-class="p-0">
        @if($rows->isEmpty())
            <p class="text-muted p-3 mb-0">No standard materials yet.@if($canEdit) Add one above, or learn them from a style that already has a BOM.@endif</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th>Buyer</th><th>Product group</th><th>Type</th><th>Description</th><th>SAP code</th><th>Color</th><th>Unit</th><th class="text-end">Consumption</th><th class="text-end">Wastage %</th><th></th></tr></thead>
                    <tbody>
                    @foreach($rows as $r)
                        @php($editable = $canEdit && ($r->buyer_id !== null || $isAdmin))
                        <tr>
                            @if($editable)
                                <td>{{ $r->buyer?->buyer_name ?? 'All buyers' }}<input type="hidden" form="u{{ $r->id }}" name="buyer_id" value="{{ $r->buyer_id }}"></td>
                                <td><input form="u{{ $r->id }}" name="product_group" value="{{ $r->product_group }}" class="form-control form-control-sm" maxlength="120"></td>
                                <td><input form="u{{ $r->id }}" name="material_type" value="{{ $r->material_type }}" class="form-control form-control-sm" maxlength="60"></td>
                                <td><input form="u{{ $r->id }}" name="material_description" value="{{ $r->material_description }}" class="form-control form-control-sm" maxlength="255" required></td>
                                <td><input form="u{{ $r->id }}" name="sap_code" value="{{ $r->sap_code }}" class="form-control form-control-sm" maxlength="60"></td>
                                <td><input form="u{{ $r->id }}" name="material_color" value="{{ $r->material_color }}" class="form-control form-control-sm" maxlength="120"></td>
                                <td><input form="u{{ $r->id }}" name="uom" value="{{ $r->uom }}" class="form-control form-control-sm" maxlength="20" style="width:70px"></td>
                                <td><input form="u{{ $r->id }}" type="number" step="any" min="0" name="consumption" value="{{ $cons($r->consumption) }}" class="form-control form-control-sm text-end" required></td>
                                <td><input form="u{{ $r->id }}" type="number" step="any" min="0" max="100" name="wastage_percent" value="{{ $cons($r->wastage_percent) }}" class="form-control form-control-sm text-end"></td>
                                <td class="text-nowrap">
                                    <button form="u{{ $r->id }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                                    <button form="d{{ $r->id }}" class="btn btn-outline-danger btn-sm" onclick="return confirm('Delete this material from the master?')"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                                </td>
                            @else
                                <td>{{ $r->buyer?->buyer_name ?? 'All buyers' }}</td><td>{{ $r->product_group ?: 'Every group' }}</td><td>{{ $r->material_type }}</td>
                                <td>{{ $r->material_description }}</td><td>{{ $r->sap_code }}</td><td>{{ $r->material_color }}</td><td>{{ $r->uom }}</td>
                                <td class="text-end">{{ $cons($r->consumption) }}</td><td class="text-end">{{ $cons($r->wastage_percent) }}</td><td></td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @foreach($rows as $r)
                @if($canEdit && ($r->buyer_id !== null || $isAdmin))
                    <form id="u{{ $r->id }}" method="POST" action="{{ route('merchant.consumption.update', $r) }}" class="d-none">@csrf @method('PUT')</form>
                    <form id="d{{ $r->id }}" method="POST" action="{{ route('merchant.consumption.destroy', $r) }}" class="d-none">@csrf @method('DELETE')</form>
                @endif
            @endforeach
        @endif
    </x-card>
</div>
@endsection
