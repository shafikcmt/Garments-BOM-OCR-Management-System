@extends('layouts.app')

@section('title', 'Workspace Columns · ' . $buyer->buyer_name)

@section('content')
@php($total = $groups->flatten()->count())
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Buyers', 'url' => route('admin.buyers.index')],
        ['label' => $buyer->buyer_name, 'url' => route('admin.buyers.edit', $buyer)],
        ['label' => 'Workspace Columns'],
    ]" />

    <x-page-header icon="layout-three-columns" :eyebrow="$buyer->buyer_name" title="Workspace Columns"
                   copy="Tick the columns this buyer uses. Unticked columns are hidden on this buyer's workspace files. Nothing is deleted; users can still press Show All Columns.">
        <x-slot:actions>
            <a href="{{ route('admin.buyers.edit', $buyer) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Buyer</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <x-card class="mb-3" body-class="p-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
                @if($profile)
                    <strong>Saved column matching found</strong> ({{ $profile->columns->whereNotNull('excel_header_id')->count() }} columns from {{ $buyer->buyer_name }}'s file).
                    <span class="text-muted">Use it to tick the Merchandising columns this buyer sends; other departments' columns stay shown.</span>
                @else
                    <span class="text-muted">No column matching saved for this buyer yet. Import one of the buyer's files (Import Buyer Excel) and tick "Save this matching", or tick columns by hand below.</span>
                @endif
            </div>
            @if($profile)
                <form method="POST" action="{{ route('admin.buyers.columns.from_profile', $buyer) }}" onsubmit="return confirm('Set the columns from {{ $buyer->buyer_name }}\'s saved matching?')">@csrf
                    <button class="btn btn-outline-primary"><i class="bi bi-magic me-1" aria-hidden="true"></i>Use Saved Matching</button>
                </form>
            @endif
        </div>
    </x-card>

    <form method="POST" action="{{ route('admin.buyers.columns.update', $buyer) }}" id="columnsForm">
        @csrf @method('PUT')
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <span class="fw-semibold"><span data-shown-count>{{ $total - count($hidden) }}</span> of {{ $total }} columns shown</span>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-tick="all"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Show All</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Columns</button>
        </div>

        <div class="row g-3">
            @foreach($groups as $role => $headers)
                <div class="col-12 col-xl-6">
                    <x-card body-class="p-3" class="h-100">
                        <x-slot:title>{{ $role }} <span class="badge bg-light text-muted border ms-1">{{ $headers->count() }}</span></x-slot:title>
                        <x-slot:actions>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-tick-group="{{ $loop->index }}"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Tick All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-untick-group="{{ $loop->index }}"><i class="bi bi-square me-1" aria-hidden="true"></i>Untick All</button>
                        </x-slot:actions>
                        <div class="row g-1" data-group="{{ $loop->index }}">
                            @foreach($headers as $h)
                                <div class="col-12 col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="shown[]" value="{{ $h->id }}" id="col{{ $h->id }}" @checked(! in_array($h->id, $hidden, true))>
                                        <label class="form-check-label small" for="col{{ $h->id }}">{{ $h->header_name }}@if($h->value_mode !== 'input') <span class="text-muted">(calculated)</span>@endif</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                </div>
            @endforeach
        </div>

        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Columns</button></div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('columnsForm');
    var count = form.querySelector('[data-shown-count]');
    function refresh() { count.textContent = form.querySelectorAll('input[name="shown[]"]:checked').length; }
    function set(scope, value) { scope.querySelectorAll('input[name="shown[]"]').forEach(function (i) { i.checked = value; }); refresh(); }
    form.addEventListener('change', refresh);
    form.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.tick === 'all') set(form, true);
        if (b.dataset.tickGroup !== undefined) set(form.querySelector('[data-group="' + b.dataset.tickGroup + '"]'), true);
        if (b.dataset.untickGroup !== undefined) set(form.querySelector('[data-group="' + b.dataset.untickGroup + '"]'), false);
    });
})();
</script>
@endsection
