@extends('layouts.app')

@section('title', 'Create BOM ' . $mother->style_no)

@section('content')
@php
    $isMerchant = auth()->user()->hasRole('merchant');
    $backUrl = $isMerchant ? route('merchant.styles.mothers.show', $mother) : route('merchant.status.style', $mother);
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => $isMerchant ? route('merchant.dashboard') : route('merchant.status.index')],
        ['label' => 'Style ' . $mother->style_no, 'url' => $backUrl],
        ['label' => 'Create BOM'],
    ]" />

    <x-page-header data-aos="fade-down" icon="journal-plus" :eyebrow="$mother->buyer?->buyer_name . ' · ' . $mother->season?->code"
                   :title="'Create BOM — style ' . $mother->style_no"
                   copy="List the order lines and the materials. Every order line gets every material.">
        <x-slot:actions>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif
    @if($columns['missing'] || $columns['locked'])
        <div class="alert alert-warning py-2">
            @if($columns['missing'])<div>These columns do not exist in the workspace and will not be filled: <strong>{{ implode(', ', $columns['missing']) }}</strong>.</div>@endif
            @if($columns['locked'])<div>Your role cannot edit these workspace columns, so they will be left empty: <strong>{{ implode(', ', $columns['locked']) }}</strong>.</div>@endif
        </div>
    @endif

    @if($copied)
        <div class="alert alert-success py-2">Copied {{ count($copied['rows']) }} material(s) from style {{ $copied['from'] }}. The materials below replace anything you had typed.</div>
    @elseif($copyNote)
        <div class="alert alert-warning py-2">{{ $copyNote }}</div>
    @endif

    {{-- Copy the materials of an earlier BOM of this buyer --}}
    @if($sources->isNotEmpty())
        <x-card title="Start from an earlier BOM" class="mb-3" body-class="p-3">
            <form method="GET" action="{{ route('merchant.bom.create', $mother) }}" class="row g-2 align-items-end" id="copyForm">
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1" for="copyFrom">Copy materials from style</label>
                    <select name="from" id="copyFrom" class="form-select form-select-sm">
                        @foreach($sources as $src)
                            <option value="{{ $src->id }}" {{ $copyFrom === $src->id ? 'selected' : '' }}>{{ $src->style_no }}{{ $src->season ? ' · ' . $src->season->code : '' }}{{ $src->id === $mother->id ? ' (this style)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label mb-1" for="copyLine">From</label>
                    <select name="line" id="copyLine" class="form-select form-select-sm">
                        <option value="" data-style="">All its order lines (same material once)</option>
                        @foreach($sources as $src)
                            @foreach($src->orderLines as $ol)
                                <option value="{{ $ol->id }}" data-style="{{ $src->id }}" {{ $copyLine === $ol->id ? 'selected' : '' }}>{{ $ol->gmts_color ?: 'No color' }}{{ $ol->customer_po_no ? ' · PO ' . $ol->customer_po_no : '' }} ({{ $ol->bom_items_count }})</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-files me-1" aria-hidden="true"></i>Copy Materials</button>
                </div>
            </form>
            <div class="form-text mt-2">Fills the Materials table below. Check the consumption and wastage, then press Create BOM.</div>
        </x-card>
    @endif

    <form method="POST" action="{{ route('merchant.bom.store', $mother) }}" id="bomForm">
        @csrf

        <x-card title="1. Order lines" class="mb-3" body-class="p-3">
            <x-slot:actions><button type="button" class="btn btn-outline-primary btn-sm" id="addLine"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Order Line</button></x-slot:actions>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>GMTS color</th><th>Customer PO</th><th style="width:150px">Ship date</th><th style="width:140px">Garment qty *</th>
                        @if($mother->children->isNotEmpty())<th style="width:170px">Child style</th>@endif<th style="width:100px"></th></tr></thead>
                    <tbody id="lineBody">
                    @foreach($prefill as $i => $l)
                        @include('merchant._bom-line-row', ['i' => $i, 'l' => $l, 'children' => $mother->children])
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="form-text mt-2">Garment qty is the quantity of the order line; the material quantity is worked out from it and the consumption. Lines already in this style are filled in for you.</div>
        </x-card>

        <x-card title="2. Materials" class="mb-3" body-class="p-3">
            <x-slot:actions><button type="button" class="btn btn-outline-primary btn-sm" id="addMaterial"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Material</button></x-slot:actions>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th style="width:150px">Type</th><th>Description *</th><th style="width:130px">SAP code</th><th style="width:150px">Material color</th>
                        <th style="width:80px">Unit</th><th style="width:130px">Consumption *</th><th style="width:110px">Wastage %</th><th style="width:100px"></th></tr></thead>
                    <tbody id="materialBody">
                    @foreach($materials as $i => $m)
                        @include('merchant._bom-material-row', ['i' => $i, 'm' => $m])
                    @endforeach
                    </tbody>
                </table>
            </div>
            <datalist id="materialTypes"><option value="SHELL"><option value="TRIMS"><option value="LINING"><option value="POCKETING"><option value="INTERLINING"><option value="LABEL"><option value="PACKING"><option value="THREAD"></datalist>
            <datalist id="matSuggest"></datalist>
            <datalist id="units"><option value="YDS"><option value="MTR"><option value="PCS"><option value="KG"><option value="SET"></datalist>
        </x-card>

        <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
            <button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Create BOM</button>
            <span class="text-muted" id="bomCount"></span>
        </div>
    </form>

    <template id="lineTemplate">@include('merchant._bom-line-row', ['i' => '__I__', 'l' => ['color' => '', 'po' => '', 'ship_date' => '', 'qty' => '', 'child' => ''], 'children' => $mother->children])</template>
    <template id="materialTemplate">@include('merchant._bom-material-row', ['i' => '__I__', 'm' => ['type' => '', 'description' => '', 'sap_code' => '', 'color' => '', 'uom' => '', 'consumption' => '', 'wastage' => '']])</template>
</div>

<script>
(function () {
    var nextLine = {{ count($prefill) }}, nextMat = {{ count($materials) }};
    var lineBody = document.getElementById('lineBody'), matBody = document.getElementById('materialBody'), count = document.getElementById('bomCount');

    function refresh() {
        var l = lineBody.querySelectorAll('tr').length, m = matBody.querySelectorAll('tr').length;
        count.textContent = l + ' order line(s) x ' + m + ' material(s) = ' + (l * m) + ' BOM line(s)';
        count.className = (l * m > {{ \App\Services\BomBuilder::MAX_ROWS }}) ? 'text-danger' : 'text-muted';
    }
    function add(templateId, body, idx) {
        body.insertAdjacentHTML('beforeend', document.getElementById(templateId).innerHTML.replace(/__I__/g, idx));
        refresh();
    }
    document.getElementById('addLine').addEventListener('click', function () { add('lineTemplate', lineBody, nextLine++); });
    document.getElementById('addMaterial').addEventListener('click', function () { add('materialTemplate', matBody, nextMat++); });
    document.getElementById('bomForm').addEventListener('click', function (e) {
        var del = e.target.closest('.js-del-row');
        if (!del) return;
        var tr = del.closest('tr'), body = tr.parentNode;
        if (body.querySelectorAll('tr').length > 1) { tr.remove(); refresh(); }
    });
    refresh();

    // Copy card: show only the order lines of the chosen style.
    var from = document.getElementById('copyFrom'), line = document.getElementById('copyLine');
    if (from && line) {
        var filterLines = function () {
            Array.prototype.forEach.call(line.options, function (o) {
                var show = o.dataset.style === '' || o.dataset.style === from.value;
                o.hidden = !show; o.disabled = !show;
            });
            if (line.selectedOptions[0] && line.selectedOptions[0].disabled) line.value = '';
        };
        from.addEventListener('change', function () { line.value = ''; filterLines(); });
        filterLines();
    }

    // Suggestions from past BOMs of this buyer while typing a description or SAP code.
    var list = document.getElementById('matSuggest'), picks = {}, timer = null;
    matBody.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.dataset.suggest || el.value.trim().length < 2) return;
        clearTimeout(timer);
        timer = setTimeout(function () {
            fetch('{{ route('merchant.bom.suggest', $mother) }}?q=' + encodeURIComponent(el.value.trim()), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (items) {
                    picks = {}; list.innerHTML = '';
                    items.forEach(function (it) {
                        var value = el.dataset.suggest === 'sap' ? it.sap_code : it.description;
                        if (!value) return;
                        var o = document.createElement('option');
                        o.value = value; o.label = [it.sap_code, it.description, it.uom].filter(Boolean).join(' · ');
                        list.appendChild(o); picks[value] = it;
                    });
                });
        }, 250);
    });
    matBody.addEventListener('change', function (e) {
        var el = e.target, it = el.dataset.suggest ? picks[el.value] : null;
        if (!it) return;
        var row = el.closest('tr');
        // Fill what is still empty, so nothing the user typed is overwritten.
        [['type', it.type], ['description', it.description], ['sap_code', it.sap_code], ['color', it.color], ['uom', it.uom], ['consumption', it.consumption], ['wastage', it.wastage]].forEach(function (f) {
            var input = row.querySelector('[name$="[' + f[0] + ']"]');
            if (input && input.value.trim() === '' && f[1] !== null && f[1] !== undefined) input.value = f[1];
        });
    });
})();
</script>
@endsection
