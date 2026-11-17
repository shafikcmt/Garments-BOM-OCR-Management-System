@extends('layouts.app')

@section('title', 'Import Buyer Excel')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Merchandising', 'url' => route('merchant.dashboard')],
        ['label' => 'Workspace', 'url' => route('merchant.workspace')],
        ['label' => 'Import Buyer Excel'],
    ]" />

    <x-page-header data-aos="fade-down" icon="magic" eyebrow="Merchandising"
                   title="Import Buyer Excel"
                   copy="Any layout. Match the columns once and the system remembers them for this buyer.">
        <x-slot:actions>
            <a href="{{ route('merchant.excel.sample') }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-download" aria-hidden="true"></i>Download Sample
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />
    @include('merchant.import._steps', ['current' => 1])

    <x-card title="Choose buyer, season and file">
        @if($buyers->isEmpty())
            <p class="text-muted mb-0">No buyer is available to you. Ask admin to assign a buyer.</p>
        @else
        <form method="POST" action="{{ route('merchant.import.upload') }}" enctype="multipart/form-data" class="row g-3">
            @csrf
            <div class="col-12 col-md-4">
                <label class="form-label" for="importBuyer">Buyer <span class="text-danger">*</span></label>
                <select name="buyer_id" id="importBuyer" class="form-select @error('buyer_id') is-invalid @enderror" required>
                    <option value="">Select buyer</option>
                    @foreach($buyers as $buyer)
                        <option value="{{ $buyer->id }}" {{ (int) old('buyer_id', $buyers->count() === 1 ? $buyer->id : 0) === $buyer->id ? 'selected' : '' }}>{{ $buyer->buyer_name }}</option>
                    @endforeach
                </select>
                @error('buyer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="importSeason">Season <span class="text-danger">*</span></label>
                <input type="text" name="season_code" id="importSeason" list="importSeasons" maxlength="50"
                       class="form-control @error('season_code') is-invalid @enderror" placeholder="e.g. SP27" value="{{ old('season_code') }}" required>
                <datalist id="importSeasons">@foreach($seasons as $code)<option value="{{ $code }}">@endforeach</datalist>
                @error('season_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label" for="importFile">Excel or CSV file <span class="text-danger">*</span></label>
                <input type="file" name="file" id="importFile" accept=".xlsx,.xls,.csv" class="form-control @error('file') is-invalid @enderror" required>
                @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            {{-- Shown only when this buyer already has a file for the chosen season. --}}
            <div class="col-12 d-none" id="targetBox">
                <div class="border rounded-3 p-3" style="background:#f8fafc;">
                    <div class="fw-semibold mb-2"><i class="bi bi-files me-1" aria-hidden="true"></i>Where should the rows go?</div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="radio" name="target_choice" id="targetNew" value="new" checked>
                        <label class="form-check-label" for="targetNew">Create a new file</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="target_choice" id="targetExisting" value="existing">
                        <label class="form-check-label" for="targetExisting">Add to the end of an existing file <span class="text-muted">(same buyer and season)</span></label>
                    </div>
                    <div id="targetPick" class="d-none">
                        <select name="target_file_id" id="targetFile" class="form-select @error('target_file_id') is-invalid @enderror" disabled></select>
                        @error('target_file_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">The new rows are added after the last row. You can undo the import from Existing Files, as long as nobody has worked on those rows.</div>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Continue</button>
                <a href="{{ route('merchant.workspace') }}" class="btn btn-outline-secondary ms-1">Cancel</a>
            </div>
        </form>
        @endif
    </x-card>
</div>
@endsection

@section('scripts')
<script>
(function () {
    const files = @json($existingFiles->values());
    const preselect = @json($preselect);
    const buyer = document.getElementById('importBuyer');
    const season = document.getElementById('importSeason');
    const box = document.getElementById('targetBox');
    const pick = document.getElementById('targetPick');
    const select = document.getElementById('targetFile');
    const radios = document.querySelectorAll('input[name="target_choice"]');
    if (!buyer || !season || !box) return;

    function chosen() { return document.querySelector('input[name="target_choice"]:checked')?.value; }

    function refresh(keep) {
        const code = (season.value || '').trim().toUpperCase();
        const matches = files.filter(f => String(f.buyer_id) === buyer.value && f.season === code);
        box.classList.toggle('d-none', matches.length === 0);
        select.innerHTML = matches.map(f => '<option value="' + f.id + '">' + f.name.replace(/</g, '&lt;') + ' \u2014 ' + f.rows + ' rows, updated ' + f.updated + '</option>').join('');
        if (keep && matches.some(f => f.id === keep)) select.value = String(keep);
        if (matches.length === 0) document.getElementById('targetNew').checked = true;
        const existing = chosen() === 'existing' && matches.length > 0;
        pick.classList.toggle('d-none', !existing);
        select.disabled = !existing;
    }

    [buyer, season].forEach(el => el.addEventListener('input', () => refresh()));
    radios.forEach(r => r.addEventListener('change', () => refresh()));

    if (preselect) {
        buyer.value = String(preselect.buyer_id);
        season.value = preselect.season;
        document.getElementById('targetExisting').checked = true;
        refresh(preselect.id);
    } else {
        refresh();
    }
})();
</script>
@endsection
