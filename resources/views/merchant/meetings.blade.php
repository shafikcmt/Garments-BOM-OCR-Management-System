@extends('layouts.app')

@section('title', 'Meetings')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Meetings']]" />

    <x-page-header icon="people" eyebrow="Merchandising" title="Order Progress Meetings"
                   copy="Minutes of buyer and internal order meetings. Every action becomes a follow-up task for the person, with a due date.">
        @if($canEdit)
            <x-slot:actions><a href="{{ route('merchant.meetings.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Meeting</a></x-slot:actions>
        @endif
    </x-page-header>

    <x-flash />

    <x-card class="mb-3" body-class="p-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label mb-1" for="mBuyer">Buyer</label>
                <select name="buyer" id="mBuyer" class="form-select form-select-sm">
                    <option value="">All buyers</option>
                    @foreach($buyers as $b)<option value="{{ $b->id }}" @selected($buyer?->id === $b->id)>{{ $b->buyer_name }}</option>@endforeach
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button></div>
        </form>
    </x-card>

    <x-card body-class="p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:0">
                <thead><tr><th>Date</th><th>Meeting</th><th>Buyer</th><th>Style</th><th class="text-end">Actions</th><th>Next meeting</th><th></th></tr></thead>
                <tbody>
                @forelse($meetings as $m)
                    <tr>
                        <td class="text-nowrap">{{ $m->meeting_date->format('d M Y') }}</td>
                        <td class="fw-semibold">{{ $m->title }}</td>
                        <td>{{ $m->buyer?->buyer_name }}</td>
                        <td>{{ $m->motherStyle?->style_no ?? '—' }}</td>
                        <td class="text-end">{{ $m->actions_count }}@if($m->open_actions_count > 0) <span class="badge bg-warning text-dark ms-1">{{ $m->open_actions_count }} open</span>@endif</td>
                        <td class="text-nowrap">{{ $m->next_meeting_date?->format('d M Y') ?? '—' }}</td>
                        <td class="text-end"><a href="{{ route('merchant.meetings.show', $m) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No meetings recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
