@extends('layouts.app')

@section('title', 'Sample Lessons')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Samples', 'url' => route('merchant.samples.index')], ['label' => 'Buyer Comments']]" />

    <x-page-header icon="lightbulb" eyebrow="Merchandising" title="Buyer Comments on Samples"
                   copy="What buyers said when a sample was rejected or approved with comments. Search it before making the next sample so the same mistake is not repeated." />

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label mb-1" for="lBuyer">Buyer</label>
                <select name="buyer" id="lBuyer" class="form-select form-select-sm">
                    <option value="">All buyers</option>
                    @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label mb-1" for="lQ">Search</label>
                <input type="search" name="q" id="lQ" value="{{ $term }}" class="form-control form-control-sm" placeholder="Word in the comment, style number or product group">
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button></div>
        </form>
    </x-card>

    <x-card body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Date</th><th>Buyer / Style</th><th>Sample</th><th>Verdict</th><th>Buyer said</th><th></th></tr></thead>
                <tbody>
                @forelse($logs as $l)
                    @php($s = $l->sample)
                    <tr>
                        <td class="text-nowrap">{{ $l->event_date->format('d M Y') }}</td>
                        <td>{{ $s->motherStyle->buyer?->buyer_name }}<div class="small text-muted">{{ $s->motherStyle->style_no }}{{ $s->motherStyle->product_group ? ' · ' . $s->motherStyle->product_group : '' }}</div></td>
                        <td>{{ $s->type?->name }}<div class="small text-muted">{{ $s->tag_id }}</div></td>
                        <td><span class="badge {{ (new \App\Models\Sample(['status' => $l->status]))->badgeClass() }}">{{ \App\Models\Sample::STATUSES[$l->status] ?? $l->status }}</span></td>
                        <td>{{ $l->note }}</td>
                        <td class="text-end"><a href="{{ route('merchant.samples.show', $s) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>History</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No buyer comments on rejected samples yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
