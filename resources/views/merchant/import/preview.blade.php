@extends('layouts.app')

@section('title', 'Preview Import')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.dashboard')],
        ['label' => 'Import Buyer Excel', 'url' => route('merchant.import.index')],
        ['label' => 'Preview'],
    ]" />

    <x-page-header data-aos="fade-down" icon="magic" eyebrow="Merchandising"
                   title="Preview and import"
                   :copy="$state['original_name'] . ' — ' . $buyer->buyer_name . ', season ' . $state['season_code']" />

    <x-flash />
    @include('merchant.import._steps', ['current' => 3])

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card icon="list-ul" tone="primary" label="Rows to import" :value="$rowCount" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="columns-gap" tone="success" label="Columns matched" :value="$mappedCount" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="skip-forward" tone="warning" label="Columns skipped" :value="max(0, $skippedCount)" /></div>
        <div class="col-6 col-xl-3"><x-stat-card icon="tags" tone="primary" label="Styles found" :value="$styles->count()" /></div>
    </div>

    @if(! $styleMapped)
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>No Style column is matched, so styles will not be linked to the Style Library.</div>
    @endif
    @if($missingRequired->isNotEmpty())
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Required columns not matched: {{ $missingRequired->implode(', ') }}. They stay empty until filled in the workspace.</div>
    @endif

    <div class="row g-3">
        <div class="col-12 col-xl-4">
            <x-card title="Styles found" body-class="p-3">
                @if($styles->isEmpty())
                    <p class="text-muted mb-0">None.</p>
                @else
                    <div class="table-responsive" style="max-height:320px">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Mother style</th><th>Child</th><th class="text-end">Rows</th></tr></thead>
                            <tbody>
                            @foreach($styles as $s)
                                <tr><td class="fw-semibold">{{ $s['style_no'] }}</td><td>{{ $s['child'] ?: '—' }}</td><td class="text-end">{{ $s['rows'] }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="form-text mt-2">Added to the Style Library under {{ $buyer->buyer_name }} / {{ $state['season_code'] }}.</div>
                @endif
            </x-card>
        </div>
        <div class="col-12 col-xl-8">
            <x-card title="First rows" body-class="p-3">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr>@foreach($sampleHeaders as $h)<th>{{ $h->header_name }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach($sampleRows as $row)
                            <tr>@foreach($sampleHeaders as $h)<td>{{ $row[$h->id] ?? '' }}</td>@endforeach</tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <form method="POST" action="{{ route('merchant.import.confirm') }}">
            @csrf
            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Import {{ $rowCount }} Rows</button>
        </form>
        <a href="{{ route('merchant.import.map') }}?sheet={{ urlencode($state['sheet']) }}&header_row={{ $state['header_row'] }}" class="btn btn-outline-secondary">Back to matching</a>
        <form method="POST" action="{{ route('merchant.import.cancel') }}">
            @csrf
            <button class="btn btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel Import</button>
        </form>
    </div>
</div>
@endsection
