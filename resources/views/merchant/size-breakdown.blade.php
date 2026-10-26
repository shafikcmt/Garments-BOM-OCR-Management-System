@extends('layouts.app')

@section('title', 'Size Breakdown ' . $mother->style_no)

@section('content')
@php
    $num = fn ($n) => $n === null ? '—' : number_format((float) $n);
    $grandOrder = collect($rows)->sum(fn ($r) => $r['order_qty'] ?? 0);
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.status.index')],
        ['label' => 'Style ' . $mother->style_no, 'url' => $backUrl],
        ['label' => 'Size Breakdown'],
    ]" />

    <x-page-header icon="grid-3x3" :eyebrow="$mother->buyer?->buyer_name . ' · ' . $mother->season?->code"
                   :title="'Size Breakdown — style ' . $mother->style_no"
                   copy="Pieces per size for each colour and customer PO. The row total is checked against the order quantity.">
        <x-slot:actions>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    {{-- Size set: the size run this style uses. Sets are made per buyer here. --}}
    <x-card class="mb-3" body-class="p-3">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-6">
                @if($canEdit)
                    <form method="POST" action="{{ route('merchant.sizes.set', $mother) }}" class="row g-2 align-items-end">
                        @csrf @method('PUT')
                        <div class="col">
                            <label class="form-label mb-1" for="sizeSet">Size set</label>
                            <select name="size_set_id" id="sizeSet" class="form-select" required>
                                <option value="">Choose a size set</option>
                                @foreach($sets as $s)
                                    <option value="{{ $s->id }}" @selected($set?->id === $s->id)>{{ $s->name }} — {{ implode(', ', $s->sizes) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Use Size Set</button></div>
                    </form>
                @else
                    <div class="form-label mb-1">Size set</div>
                    <div class="fw-semibold">{{ $set ? $set->name . ' — ' . implode(', ', $set->sizes) : 'Not chosen yet' }}</div>
                @endif
            </div>
            @if($canEdit)
                <div class="col-12 col-lg-6">
                    <form method="POST" action="{{ route('merchant.sizes.sets.store', $mother) }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-5">
                            <label class="form-label mb-1" for="setName">New size set name</label>
                            <input type="text" name="name" id="setName" class="form-control" maxlength="80" placeholder="e.g. Missy XS-XXL" value="{{ old('name') }}" required>
                        </div>
                        <div class="col">
                            <label class="form-label mb-1" for="setSizes">Sizes (comma separated)</label>
                            <input type="text" name="sizes" id="setSizes" class="form-control" maxlength="1000" placeholder="XS, S, M, L, XL, XXL" value="{{ old('sizes') }}" required>
                        </div>
                        <div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Size Set</button></div>
                    </form>
                </div>
            @endif
        </div>
    </x-card>

    @if($rows === [])
        <x-card><p class="mb-0 text-muted">This style has no order lines yet. Import the buyer's Excel or press Create BOM on the style first.</p></x-card>
    @elseif($sizes === [])
        <x-card><p class="mb-0 text-muted">Choose or add a size set above to enter the size breakdown.</p></x-card>
    @else
        <form method="POST" action="{{ route('merchant.sizes.update', $mother) }}" id="sizeForm">
            @csrf @method('PUT')
            @foreach($sizes as $size)<input type="hidden" name="sizes[]" value="{{ $size }}">@endforeach

            <x-card title="Colour x Size" body-class="p-3">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead><tr>
                            <th>GMTS Color</th><th>Customer PO</th><th>Ship Date</th>
                            @foreach($sizes as $size)<th class="text-center" style="min-width:76px">{{ $size }}</th>@endforeach
                            <th class="text-end">Size Total</th><th class="text-end">Order Qty</th><th class="text-end">Difference</th>
                        </tr></thead>
                        <tbody>
                        @foreach($rows as $i => $r)
                            <tr data-row data-order="{{ $r['order_qty'] ?? '' }}">
                                <td class="fw-semibold">{{ $r['color'] ?: '—' }}<input type="hidden" name="rows[{{ $i }}][color]" value="{{ $r['color'] }}"></td>
                                <td>{{ $r['po'] ?: '—' }}<input type="hidden" name="rows[{{ $i }}][po]" value="{{ $r['po'] }}"></td>
                                <td class="text-nowrap">{{ $r['ship_date'] ? \Illuminate\Support\Carbon::parse($r['ship_date'])->format('d M Y') : '—' }}</td>
                                @foreach($sizes as $j => $size)
                                    <td>
                                        <input type="number" step="1" min="0" class="form-control form-control-sm text-end" data-qty
                                               name="rows[{{ $i }}][qty][{{ $j }}]" value="{{ isset($r['sizes'][$size]) ? (float) $r['sizes'][$size] : '' }}"
                                               aria-label="{{ $size }} for {{ $r['color'] }} {{ $r['po'] }}" {{ $canEdit ? '' : 'disabled' }}>
                                    </td>
                                @endforeach
                                <td class="text-end fw-semibold" data-total>{{ $num($r['total']) }}</td>
                                <td class="text-end">{{ $num($r['order_qty']) }}</td>
                                <td class="text-end" data-diff>—</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr class="fw-semibold">
                            <td colspan="3">Total</td>
                            @foreach($sizes as $j => $size)<td class="text-end" data-col-total="{{ $j }}">0</td>@endforeach
                            <td class="text-end" data-grand>0</td><td class="text-end">{{ $num($grandOrder) }}</td><td></td>
                        </tr></tfoot>
                    </table>
                </div>
            </x-card>

            @if($canEdit)
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Save Size Breakdown</button>
                </div>
            @endif
        </form>

        <script>
        (function () {
            var form = document.getElementById('sizeForm');
            var fmt = function (n) { return Number(n || 0).toLocaleString(); };
            function refresh() {
                var cols = {}, grand = 0;
                form.querySelectorAll('[data-row]').forEach(function (tr) {
                    var total = 0;
                    tr.querySelectorAll('[data-qty]').forEach(function (input, j) {
                        var v = parseFloat(input.value) || 0;
                        total += v; cols[j] = (cols[j] || 0) + v;
                    });
                    grand += total;
                    tr.querySelector('[data-total]').textContent = fmt(total);
                    var order = parseFloat(tr.dataset.order), diff = tr.querySelector('[data-diff]');
                    if (isNaN(order) || total === 0) { diff.textContent = '—'; diff.className = 'text-end'; return; }
                    var d = total - order;
                    diff.textContent = d === 0 ? 'Matches' : (d > 0 ? '+' : '') + fmt(d);
                    diff.className = 'text-end ' + (d === 0 ? 'text-success' : 'text-danger fw-semibold');
                });
                form.querySelectorAll('[data-col-total]').forEach(function (td) { td.textContent = fmt(cols[td.dataset.colTotal]); });
                form.querySelector('[data-grand]').textContent = fmt(grand);
            }
            form.addEventListener('input', refresh);
            refresh();
        })();
        </script>
    @endif
</div>
@endsection
