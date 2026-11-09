@extends('layouts.app')

@section('title', 'Size-wise BOM ' . $mother->style_no)

@section('content')
@php
    $fmt = fn ($n, $d = 2) => $n === null ? '—' : number_format((float) $n, $d);
    $cons = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 4), '0'), '.');
@endphp
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.status.index')],
        ['label' => 'Style ' . $mother->style_no, 'url' => $backUrl],
        ['label' => 'Size-wise BOM'],
    ]" />

    <x-page-header icon="rulers" :eyebrow="$mother->buyer?->buyer_name . ' · ' . $mother->season?->code"
                   :title="'Size-wise BOM — style ' . $mother->style_no"
                   copy="Type a consumption for a size that differs from the BOM. An empty size uses the BOM figure. The requirement is worked out size by size from the Size Breakdown and shown beside the workspace figure.">
        <x-slot:actions>
            <a href="{{ route('merchant.sizes.show', $mother) }}" class="btn btn-outline-secondary"><i class="bi bi-grid-3x3 me-1" aria-hidden="true"></i>Size Breakdown</a>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Style</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    @if($materials->isEmpty())
        <x-card body-class="p-3"><p class="text-muted mb-0">This style has no BOM yet. Import the buyer's Excel or use Create BOM first.</p></x-card>
    @elseif($sizes === [])
        <x-card body-class="p-3"><p class="mb-0">Choose a size set and enter the colour x size quantities first on <a href="{{ route('merchant.sizes.show', $mother) }}">Size Breakdown</a>.</p></x-card>
    @else
        @unless($hasBreakdown)
            <div class="alert alert-warning py-2">No size quantities are entered yet, so every requirement below uses the BOM figure. Fill <a href="{{ route('merchant.sizes.show', $mother) }}">Size Breakdown</a> first.</div>
        @endunless
        <form method="POST" action="{{ route('merchant.sizebom.update', $mother) }}">
            @csrf @method('PUT')
            @foreach($sizes as $s)<input type="hidden" name="sizes[]" value="{{ $s }}">@endforeach
            <x-card body-class="p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead>
                            <tr>
                                <th>Material</th><th>Unit</th><th class="text-end">BOM cons. (incl. waste)</th>
                                @foreach($sizes as $s)<th class="text-end" style="min-width:84px">{{ $s }}</th>@endforeach
                                <th class="text-end">Size-wise need</th><th class="text-end">Workspace need</th><th class="text-end">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($materials as $i => $m)
                            <tr>
                                <td class="fw-semibold">
                                    {{ $m['name'] }}
                                    <input type="hidden" name="rows[{{ $i }}][key]" value="{{ $m['key'] }}">
                                    <input type="hidden" name="rows[{{ $i }}][name]" value="{{ $m['name'] }}">
                                    @if($m['fallback_lines'] > 0)<div class="small text-muted fw-normal">{{ $m['fallback_lines'] }} of {{ $m['lines'] }} line(s) have no size breakdown</div>@endif
                                </td>
                                <td>{{ $m['uom'] ?: '—' }}</td>
                                <td class="text-end">{{ $cons($m['base']) }}</td>
                                @foreach($sizes as $si => $s)
                                    <td><input type="number" step="any" min="0" name="rows[{{ $i }}][cons][{{ $si }}]" class="form-control form-control-sm text-end"
                                               value="{{ isset($m['per_size'][(string) $s]) ? $cons($m['per_size'][(string) $s]) : '' }}" @disabled(! $canEdit)></td>
                                @endforeach
                                <td class="text-end fw-semibold">{{ $fmt($m['size_wise_qty']) }}</td>
                                <td class="text-end">{{ $fmt($m['workspace_qty']) }}</td>
                                <td class="text-end {{ abs($m['difference']) < 0.005 ? 'text-muted' : ($m['difference'] > 0 ? 'text-danger' : 'text-success') }}">
                                    {{ $m['difference'] > 0 ? '+' : '' }}{{ $fmt($m['difference']) }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
            @if($canEdit)
                <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Size-wise Consumption</button></div>
            @endif
        </form>
        <p class="small text-muted mt-3 mb-0">Need = pieces of each size x that size's consumption x wastage factor of the BOM line. Red means the size-wise need is higher than the workspace figure; green means lower. The workspace and the PO are not changed by this page.</p>
    @endif
</div>
@endsection
