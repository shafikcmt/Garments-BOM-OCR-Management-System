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
            <a href="{{ route('merchant.import.template') }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-download" aria-hidden="true"></i>Download Standard Template
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
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-arrow-right-circle me-1" aria-hidden="true"></i>Continue</button>
                <a href="{{ route('merchant.workspace') }}" class="btn btn-outline-secondary ms-1">Cancel</a>
            </div>
        </form>
        @endif
    </x-card>
</div>
@endsection
