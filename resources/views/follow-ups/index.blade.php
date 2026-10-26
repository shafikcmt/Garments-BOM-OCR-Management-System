@extends('layouts.app')

@section('title', 'Follow-ups')

@section('content')
@php
    $tone = ['Overdue' => 'danger', 'Due today' => 'warning', 'Coming up' => 'success', 'Done' => 'secondary'];
@endphp
<div class="container-fluid">
    <x-page-header data-aos="fade-down" icon="check2-square" eyebrow="Time and Action"
                   title="Follow-ups"
                   copy="What has to be chased, by whom, and by when." />

    <x-flash />

    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <div class="btn-group" role="group">
            <a href="{{ route('follow_ups.index', ['tab' => 'mine', 'done' => $showDone ? 1 : null]) }}" class="btn btn-sm {{ $tab === 'mine' ? 'btn-primary' : 'btn-outline-secondary' }}">My follow-ups</a>
            <a href="{{ route('follow_ups.index', ['tab' => 'created', 'done' => $showDone ? 1 : null]) }}" class="btn btn-sm {{ $tab === 'created' ? 'btn-primary' : 'btn-outline-secondary' }}">Given by me</a>
            @if($canSeeAll)
                <a href="{{ route('follow_ups.index', ['tab' => 'all', 'done' => $showDone ? 1 : null]) }}" class="btn btn-sm {{ $tab === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}">Everyone</a>
            @endif
        </div>
        <a href="{{ route('follow_ups.index', ['tab' => $tab, 'done' => $showDone ? null : 1]) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-{{ $showDone ? 'eye-slash' : 'eye' }} me-1" aria-hidden="true"></i>{{ $showDone ? 'Hide done' : 'Show done' }}
        </a>
    </div>

    @php($total = collect($groups)->sum(fn ($g) => $g->count()))
    @if($total === 0)
        <x-card><p class="text-muted mb-0">Nothing here. Follow-ups are created from a style's status page when materials are late.</p></x-card>
    @endif

    @foreach($groups as $label => $tasks)
        @continue($tasks->isEmpty())
        <x-card class="mb-3" body-class="p-3">
            <x-slot:title>
                <span class="badge bg-{{ $tone[$label] }}-subtle text-{{ $tone[$label] }} me-1">{{ $tasks->count() }}</span>{{ $label }}
            </x-slot:title>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Task</th><th>Style</th><th>Assigned to</th><th>Given by</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                    @foreach($tasks as $t)
                        <tr>
                            <td>
                                {{ $t->title }}
                                @if($t->note)<div class="small text-muted">{{ $t->note }}</div>@endif
                            </td>
                            <td>
                                @if($t->motherStyle)
                                    <a href="{{ auth()->user()->hasRole('merchant') ? route('merchant.styles.mothers.show', $t->motherStyle) : route('merchant.status.style', $t->motherStyle) }}">{{ $t->motherStyle->style_no }}</a>
                                @else — @endif
                            </td>
                            <td>{{ $t->assignee?->name }}</td>
                            <td>{{ $t->creator?->name ?: '—' }}</td>
                            <td class="text-nowrap">{{ $t->due_date->format('d M Y') }}</td>
                            <td class="text-end">
                                @if(auth()->user()->hasRole('admin') || in_array(auth()->id(), [$t->assigned_to, $t->created_by], true))
                                    @if($t->isOpen())
                                        <form method="POST" action="{{ route('follow_ups.done', $t) }}">@csrf
                                            <button class="btn btn-outline-success btn-sm"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Mark Done</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('follow_ups.reopen', $t) }}">@csrf
                                            <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reopen</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endforeach
</div>
@endsection
