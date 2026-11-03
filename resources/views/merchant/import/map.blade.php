@extends('layouts.app')

@section('title', 'Match Columns')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.dashboard')],
        ['label' => 'Import Buyer Excel', 'url' => route('merchant.import.index')],
        ['label' => 'Match columns'],
    ]" />

    <x-page-header data-aos="fade-down" icon="magic" eyebrow="Merchandising"
                   title="Match columns"
                   :copy="$state['original_name'] . ' — ' . $buyer->buyer_name . ', season ' . $state['season_code']" />

    <x-flash />
    @include('merchant.import._steps', ['current' => 2])

    <x-card class="mb-3">
        <form method="GET" action="{{ route('merchant.import.map') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="mapSheet">Sheet with the data</label>
                <select name="sheet" id="mapSheet" class="form-select">
                    @foreach($sheets as $name)<option value="{{ $name }}" {{ $name === $sheet ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1" for="mapRow">Title row</label>
                <input type="number" name="header_row" id="mapRow" min="1" max="30" value="{{ $headerRow }}" class="form-control">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="mapProfile">Saved matching</label>
                <select name="profile" id="mapProfile" class="form-select">
                    <option value="">None — match by column name</option>
                    @foreach($profiles as $p)<option value="{{ $p->id }}" {{ $profile && $profile->id === $p->id ? 'selected' : '' }}>{{ $p->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button class="btn btn-outline-primary w-100"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Reload</button>
            </div>
        </form>
        @if($autoPicked && $profile)
            <div class="alert alert-success py-2 mt-3 mb-0"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Matched automatically with the saved matching "{{ $profile->name }}".</div>
        @endif
    </x-card>

    @php($matched = collect($suggestions)->whereNotNull('header_id')->count())
    <x-card :title="$matched . ' of ' . count($suggestions) . ' columns matched'">
        @if($autoContinue)
            <div class="alert alert-success d-flex align-items-center gap-2 py-2" id="autoContinueNote">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span>{{ $autoPicked ? 'Matched with the saved matching' : 'All columns matched by name' }}. Opening the preview...</span>
            </div>
        @endif
        <form method="POST" action="{{ route('merchant.import.preview') }}" id="matchForm">
            @csrf
            <input type="hidden" name="sheet" value="{{ $sheet }}">
            <input type="hidden" name="header_row" value="{{ $headerRow }}">

            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Column in the buyer's file</th><th>Example</th><th style="min-width:280px">Save into</th></tr></thead>
                    <tbody>
                    @foreach($suggestions as $index => $s)
                        <tr class="{{ $s['header_id'] ? '' : 'table-warning' }}">
                            <td class="fw-semibold">{{ $s['title'] }}</td>
                            <td class="text-muted small">{{ implode(' · ', $samples[$index] ?? []) ?: '—' }}</td>
                            <td>
                                <select name="map[{{ $index }}]" class="form-select form-select-sm">
                                    <option value="">Skip this column</option>
                                    @foreach($headers as $h)
                                        <option value="{{ $h->id }}" {{ (int) $s['header_id'] === (int) $h->id ? 'selected' : '' }}>{{ $h->header_name }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="form-text mb-3">Columns left on "Skip" belong to other departments or are not needed. Only Merchandising columns can be filled here.</div>

            <div class="border rounded p-3 mb-3">
                <div class="fw-semibold mb-2"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Mother and child styles</div>
                <div class="row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="mother_source">Where does the mother style come from?</label>
                        <select id="mother_source" name="mother_source" class="form-select">
                            <option value="auto" {{ ($motherRule === '' || $motherRule === 'auto') ? 'selected' : '' }}>Automatic (Mother Style column, then style code)</option>
                            <option value="column" {{ $motherRule === 'column' ? 'selected' : '' }}>Mother Style column only</option>
                            <option value="code" {{ $motherRule === 'code' ? 'selected' : '' }}>Style code (number before the bracket or fit word)</option>
                            <option value="style" {{ $motherRule === 'style' ? 'selected' : '' }}>Every style is its own mother</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-7">
                        <label class="form-label" for="fit_words">Fit words (optional)</label>
                        <input id="fit_words" type="text" name="fit_words" maxlength="500" class="form-control"
                               placeholder="PETITE, TALL, CURVY, CURVY PETITE"
                               value="{{ old('fit_words', optional($profile)->fit_words) }}">
                        <div class="form-text">Used with the style code: "608468 PETITE" becomes mother 608468 with child PETITE.</div>
                    </div>
                </div>
            </div>

            <div class="row g-2 align-items-center mb-3">
                <div class="col-auto form-check ms-2">
                    <input type="checkbox" class="form-check-input" id="saveProfile" name="save_profile" value="1" {{ ($profile || $autoPicked) ? 'checked' : '' }}>
                    <label class="form-check-label" for="saveProfile">Save this matching for {{ $buyer->buyer_name }}</label>
                </div>
                <div class="col-12 col-md-3">
                    <input type="text" name="profile_name" class="form-control form-control-sm" maxlength="100" placeholder="Name, e.g. Denim lot" value="{{ $profile?->name }}">
                </div>
            </div>

            <button class="btn btn-primary"><i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Preview</button>
            <a href="{{ route('merchant.import.index') }}" class="btn btn-outline-secondary ms-1">Back</a>
        </form>
    </x-card>
</div>

@if($autoContinue)
    <script>
        // Nothing to decide, so go straight to the preview. Press Back to matching there to review.
        document.getElementById('matchForm')?.submit();
    </script>
@endif
@endsection
