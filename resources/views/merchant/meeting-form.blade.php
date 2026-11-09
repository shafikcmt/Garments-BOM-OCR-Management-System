@extends('layouts.app')

@section('title', 'New Meeting')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[['label' => 'Merchandising', 'url' => route('merchant.status.index')], ['label' => 'Meetings', 'url' => route('merchant.meetings.index')], ['label' => 'New']]" />

    <x-page-header icon="people" eyebrow="Merchandising" title="New Meeting" copy="Write the minutes and the actions agreed. Each action is given to a person with a due date." />

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger py-2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('merchant.meetings.store') }}">
        @csrf
        <x-card class="mb-3" body-class="p-3">
            <div class="row g-2">
                <div class="col-12 col-md-4"><label class="form-label mb-1" for="mTitle">Title *</label>
                    <input type="text" name="title" id="mTitle" class="form-control form-control-sm" maxlength="160" required value="{{ old('title') }}" placeholder="e.g. SP27 order progress"></div>
                <div class="col-6 col-md-2"><label class="form-label mb-1" for="mDate">Date *</label>
                    <input type="date" name="meeting_date" id="mDate" class="form-control form-control-sm" required value="{{ old('meeting_date', $today->toDateString()) }}"></div>
                <div class="col-6 col-md-3"><label class="form-label mb-1" for="mBuyer">Buyer *</label>
                    <select name="buyer_id" id="mBuyer" class="form-select form-select-sm" required>
                        <option value="">Choose</option>
                        @foreach($buyers as $b)<option value="{{ $b->id }}" @selected((string) old('buyer_id') === (string) $b->id)>{{ $b->buyer_name }}</option>@endforeach
                    </select></div>
                <div class="col-6 col-md-3"><label class="form-label mb-1" for="mStyle">Style no. (optional)</label>
                    <input type="text" name="style_no" id="mStyle" class="form-control form-control-sm" maxlength="80" value="{{ old('style_no') }}"></div>
                <div class="col-12 col-md-6"><label class="form-label mb-1" for="mAtt">Attended by</label>
                    <input type="text" name="attendees" id="mAtt" class="form-control form-control-sm" maxlength="500" value="{{ old('attendees') }}" placeholder="Names, separated by commas"></div>
                <div class="col-6 col-md-3"><label class="form-label mb-1" for="mNext">Next meeting</label>
                    <input type="date" name="next_meeting_date" id="mNext" class="form-control form-control-sm" value="{{ old('next_meeting_date') }}"></div>
                <div class="col-12"><label class="form-label mb-1" for="mMin">Minutes</label>
                    <textarea name="minutes" id="mMin" rows="6" class="form-control form-control-sm" maxlength="20000">{{ old('minutes') }}</textarea></div>
            </div>
        </x-card>
        <x-card title="Actions" class="mb-3" body-class="p-3">
            @include('merchant._meeting-actions', ['people' => $people, 'today' => $today])
        </x-card>
        <button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Meeting</button>
        <a href="{{ route('merchant.meetings.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
@endsection
