@extends('layouts.app')

@section('title', 'Import Preview')

@section('content')
{{--
    Step one of the reference import: what WOULD change, before anything does.

    Nothing on this page has been saved. The plan behind it is held for
    {{ $expiryMinutes }} minutes and applied only when Apply Import is pressed.
--}}
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('store.dashboard')],
        ['label' => 'Stock Report', 'url' => route('store.stock.ledger')],
        ['label' => 'Import Preview'],
    ]" />

    <x-page-header icon="clipboard-check" eyebrow="General Stock" title="Import Preview"
                   copy="Nothing has been saved yet. Check the figures below, then apply." />

    <x-flash />

    <x-card class="mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <i class="bi bi-file-earmark-spreadsheet text-muted" aria-hidden="true"></i>
            <span class="fw-semibold">{{ $fileName }}</span>
            <span class="text-muted small">&middot; {{ $matched }} {{ Str::plural('item', $matched) }} matched in the item master</span>
        </div>

        <div class="row g-3">
            @foreach(\App\Imports\StockReferenceImport::FIELDS as $field)
                <div class="col-12 col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small text-uppercase fw-semibold">{{ \App\Imports\StockReferenceImport::FIELD_LABELS[$field] }}</div>
                        <div class="fs-3 fw-bold">{{ $counts[$field] }}</div>
                        <div class="text-muted small">
                            {{ $counts[$field] === 1 ? 'item will be filled in' : 'items will be filled in' }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-muted small mt-3 mb-0">
            {{ $itemCount }} {{ Str::plural('item', $itemCount) }} will be updated in total. Only columns
            that are currently blank on the report are filled — an item counted above for one column keeps
            whatever it already has in the other two. No receiving or issue record is created.
        </p>
    </x-card>

    @if($skippedTotal > 0)
        <x-card class="mb-3" title="Skipped rows ({{ $skippedTotal }})">
            <p class="text-muted small">
                These rows were read without trouble and deliberately left alone — usually because the
                report already has a value there.
            </p>
            <ul class="mb-0 small">
                @foreach($skipped as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
            @if($skippedTotal > count($skipped))
                <p class="text-muted small mt-2 mb-0">
                    Showing the first {{ count($skipped) }} of {{ $skippedTotal }}.
                </p>
            @endif
        </x-card>
    @endif

    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('store.stock.ledger.import.apply') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="btn btn-primary">
                Apply Import
            </button>
        </form>
        <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary">
            Cancel
        </a>
    </div>

    <p class="text-muted small mt-3">
        This preview expires after {{ $expiryMinutes }} minutes. After that the file needs uploading again.
    </p>
</div>
@endsection
