@extends('layouts.app')

@section('title', 'Import Profiles · ' . $buyer->buyer_name)

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Admin', 'url' => route('admin.dashboard')],
        ['label' => 'Buyers', 'url' => route('admin.buyers.index')],
        ['label' => $buyer->buyer_name, 'url' => route('admin.buyers.edit', $buyer)],
        ['label' => 'Import Profiles'],
    ]" />

    <x-page-header icon="file-earmark-spreadsheet" :eyebrow="$buyer->buyer_name" title="Import Profiles"
                   copy="Saved from Import Buyer Excel. A profile remembers the sheet, title row, column matching and how mother and child styles are worked out for this buyer.">
        <x-slot:actions>
            <a href="{{ route('admin.buyers.columns.edit', $buyer) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Workspace Columns</a>
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @forelse($profiles as $profile)
        <x-card class="mb-3" body-class="p-3">
            <form method="POST" action="{{ route('admin.buyers.profiles.update', [$buyer, $profile]) }}" class="row g-3 align-items-end">
                @csrf @method('PUT')
                <div class="col-12 col-md-3">
                    <label class="form-label" for="name-{{ $profile->id }}">Profile name</label>
                    <input id="name-{{ $profile->id }}" name="name" class="form-control" maxlength="100" required value="{{ old('name', $profile->name) }}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="rule-{{ $profile->id }}">Mother style comes from</label>
                    <select id="rule-{{ $profile->id }}" name="mother_source" class="form-select">
                        <option value="auto" {{ $profile->mother_source === null ? 'selected' : '' }}>Automatic</option>
                        <option value="column" {{ $profile->mother_source === 'column' ? 'selected' : '' }}>Mother Style column only</option>
                        <option value="code" {{ $profile->mother_source === 'code' ? 'selected' : '' }}>Style code</option>
                        <option value="style" {{ $profile->mother_source === 'style' ? 'selected' : '' }}>Every style is its own mother</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="fit-{{ $profile->id }}">Fit words</label>
                    <input id="fit-{{ $profile->id }}" name="fit_words" class="form-control" maxlength="500" placeholder="PETITE, TALL, CURVY" value="{{ old('fit_words', $profile->fit_words) }}">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save</button>
                </div>
            </form>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                <span class="text-muted small me-auto">
                    Sheet <strong>{{ $profile->sheet_name ?: 'any' }}</strong>, title row <strong>{{ $profile->header_row }}</strong>,
                    {{ $profile->columns->whereNotNull('excel_header_id')->count() }} of {{ $profile->columns->count() }} columns matched.
                </span>
                <form method="POST" action="{{ route('admin.buyers.profiles.copy', [$buyer, $profile]) }}">@csrf
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-files me-1" aria-hidden="true"></i>Copy</button>
                </form>
                <form method="POST" action="{{ route('admin.buyers.profiles.destroy', [$buyer, $profile]) }}" onsubmit="return confirm('Delete this profile? Files already imported are not changed.')">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                </form>
            </div>

            <details class="mt-3">
                <summary class="small fw-semibold">Column matching</summary>
                <div class="table-responsive mt-2" style="max-height:320px">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Column in the buyer's file</th><th>System column</th></tr></thead>
                        <tbody>
                        @foreach($profile->columns as $column)
                            <tr>
                                <td>{{ $column->source_header }}</td>
                                <td>{{ $column->header?->header_name ?? 'Skipped' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </x-card>
    @empty
        <x-card body-class="p-4">
            <p class="mb-0 text-muted">No profile saved for {{ $buyer->buyer_name }} yet. Open Import Buyer Excel, match the columns and tick "Save this matching".</p>
        </x-card>
    @endforelse
</div>
@endsection
