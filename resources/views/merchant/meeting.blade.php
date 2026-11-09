@extends('layouts.app')

@section('title', 'Meeting ' . $meeting->title)

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Meetings', 'url' => route('merchant.meetings.index')], ['label' => $meeting->title]]" />

    <x-page-header icon="people" :eyebrow="$meeting->buyer?->buyer_name . ($meeting->motherStyle ? ' · style ' . $meeting->motherStyle->style_no : '')" :title="$meeting->title"
                   :copy="$meeting->meeting_date->format('d M Y') . ' · recorded by ' . ($meeting->creator?->name ?? '—')">
        <x-slot:actions><a href="{{ route('merchant.meetings.index') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Meetings</a></x-slot:actions>
    </x-page-header>

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <x-card title="Minutes" class="mb-3" body-class="p-3">
        <form method="POST" action="{{ route('merchant.meetings.update', $meeting) }}" class="row g-2">
            @csrf @method('PUT')
            <div class="col-12 col-md-5"><label class="form-label mb-1">Title *</label><input type="text" name="title" class="form-control form-control-sm" maxlength="160" required value="{{ old('title', $meeting->title) }}" @disabled(! $canEdit)></div>
            <div class="col-6 col-md-2"><label class="form-label mb-1">Date *</label><input type="date" name="meeting_date" class="form-control form-control-sm" required value="{{ old('meeting_date', $meeting->meeting_date->toDateString()) }}" @disabled(! $canEdit)></div>
            <div class="col-6 col-md-2"><label class="form-label mb-1">Next meeting</label><input type="date" name="next_meeting_date" class="form-control form-control-sm" value="{{ old('next_meeting_date', $meeting->next_meeting_date?->toDateString()) }}" @disabled(! $canEdit)></div>
            <div class="col-12"><label class="form-label mb-1">Attended by</label><input type="text" name="attendees" class="form-control form-control-sm" maxlength="500" value="{{ old('attendees', $meeting->attendees) }}" @disabled(! $canEdit)></div>
            <div class="col-12"><label class="form-label mb-1">Minutes</label><textarea name="minutes" rows="6" class="form-control form-control-sm" maxlength="20000" @disabled(! $canEdit)>{{ old('minutes', $meeting->minutes) }}</textarea></div>
            @if($canEdit)<div class="col-12 d-flex gap-2"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Minutes</button></div>@endif
        </form>
    </x-card>

    <x-card class="mb-3" body-class="p-3">
        <x-slot:title>Actions <span class="badge bg-light text-muted border ms-1">{{ $meeting->actions->where('status', 'done')->count() }} / {{ $meeting->actions->count() }} done</span></x-slot:title>
        @if($meeting->actions->isEmpty())
            <p class="text-muted mb-3">No actions recorded.</p>
        @else
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th>Action</th><th>Given to</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($meeting->actions as $t)
                        @php($light = $t->light())
                        <tr>
                            <td>{{ $t->title }}</td><td>{{ $t->assignee?->name }}</td><td class="text-nowrap">{{ $t->due_date->format('d M Y') }}</td>
                            <td>
                                @if(! $t->isOpen())<span class="badge bg-success-subtle text-success">Done</span>
                                @elseif($light === 'red')<span class="badge bg-danger text-white">Overdue</span>@if($t->escalated_at)<span class="badge bg-light text-muted border ms-1">Escalated</span>@endif
                                @elseif($light === 'amber')<span class="badge bg-warning text-dark">Due soon</span>
                                @else<span class="badge bg-light text-muted border">Open</span>@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @if($canEdit)
            <form method="POST" action="{{ route('merchant.meetings.actions', $meeting) }}">
                @csrf
                <div class="fw-semibold mb-2">Add actions</div>
                @include('merchant._meeting-actions', ['people' => $people, 'today' => $today])
                <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Actions</button>
            </form>
        @endif
        <p class="small text-muted mt-3 mb-0">Each action is in the person's Follow-ups to-do list. They press Mark Done there; it also shows in the morning reminder and, when it stays late, goes to the supervisor.</p>
    </x-card>

    @if($canEdit)
        <form method="POST" action="{{ route('merchant.meetings.destroy', $meeting) }}" onsubmit="return confirm('Delete this meeting? Its actions stay on people\'s to-do lists.')">
            @csrf @method('DELETE')
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete Meeting</button>
        </form>
    @endif
</div>
@endsection
