@extends('layouts.app')

@section('title', 'General Stock — My Approvals')

@php
    $waitingSince = function (?int $days) {
        if ($days === null) { return ['—', 'text-muted']; }
        $text = $days === 0 ? 'Today' : $days.' '.\Illuminate\Support\Str::plural('day', $days);

        return [$text, $days > 3 ? 'badge bg-danger-subtle text-danger' : 'text-muted'];
    };
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'My Approvals'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="inbox" eyebrow="General Stock" title="My Approvals"
                   copy="{{ $tab === 'all' ? 'Every requisition waiting at any stage. View only.' : 'Requisitions waiting for your action.' }}">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    @include('store.stock.requisitions._tabs', ['active' => 'approvals'])

    @if($overview)
        <ul class="nav nav-pills mb-3">
            <li class="nav-item"><a class="nav-link {{ $tab === 'mine' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.my-approvals') }}">Waiting for me</a></li>
            <li class="nav-item"><a class="nav-link {{ $tab === 'all' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.my-approvals', ['tab' => 'all']) }}">All Pending</a></li>
        </ul>
    @endif

    @if($tab === 'all')
        <div class="card gx-stock-card">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5 class="mb-0">All Pending <span class="badge bg-primary-subtle text-primary ms-1">{{ $all->total() }}</span></h5>
                    <span class="text-muted small">Oldest first. Open a requisition to see its progress.</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle gx-stock-table">
                        <thead>
                            <tr>
                                <th>Requisition No</th>
                                <th>Level</th>
                                <th>Department</th>
                                <th>Waiting at</th>
                                <th>Waiting since</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($all as $r)
                                @php
                                    $d = $details[$r->id] ?? ['stage' => null, 'days' => null];
                                    [$sinceText, $sinceClass] = $waitingSince($d['days']);
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $r->requisition_no }}</td>
                                    <td>{{ \App\Models\PurchaseRequisition::levelLabels()[$r->level] ?? $r->level }}</td>
                                    <td>{{ $r->isDepartmentLevel() ? ($r->indentSection?->name ?: $r->departmentLabel()) : 'Store' }}</td>
                                    <td>
                                        @if((int) $r->current_stage === 1)
                                            Requester <span class="text-muted">· {{ $r->createdBy?->name ?: '—' }} (returned)</span>
                                        @else
                                            {{ $d['stage']?->displayLabel() ?: '—' }}
                                            <span class="text-muted">· {{ $d['stage']?->assignee_name ?: '—' }}</span>
                                        @endif
                                    </td>
                                    <td><span class="{{ $sinceClass }}">{{ $sinceText }}</span></td>
                                    <td class="text-end">
                                        <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1" aria-hidden="true"></i>View</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">Nothing is waiting.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $all->links() }}
            </div>
        </div>
    @else
        @if($returned->isNotEmpty())
            <div class="card gx-stock-card mb-4 border-warning">
                <div class="gx-stock-card-body">
                    <div class="gx-stock-card-head">
                        <h5 class="mb-0">Returned to you <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ $returned->count() }}</span></h5>
                        <span class="text-muted small">Amend and submit again, or cancel.</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle gx-stock-table">
                            <thead>
                                <tr>
                                    <th>Requisition No</th>
                                    <th>Returned by</th>
                                    <th>Reason</th>
                                    <th>With you since</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($returned as $r)
                                    @php
                                        $note = $returnNotes->get($r->id);
                                        [$sinceText, $sinceClass] = $waitingSince($details[$r->id]['days'] ?? null);
                                    @endphp
                                    <tr>
                                        <td class="fw-semibold">{{ $r->requisition_no }}</td>
                                        <td>{{ $note?->actorName() ?: '—' }}@if($note?->stageLabel()) <span class="text-muted">· {{ $note->stageLabel() }}</span>@endif</td>
                                        <td class="small">{{ $note?->comment ?: '—' }}</td>
                                        <td><span class="{{ $sinceClass }}">{{ $sinceText }}</span></td>
                                        <td class="text-end">
                                            <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        <div class="card gx-stock-card">
            <div class="gx-stock-card-body">
                <div class="gx-stock-card-head">
                    <h5 class="mb-0">Waiting for you <span class="badge bg-primary-subtle text-primary ms-1">{{ $waiting->count() }}</span></h5>
                    <span class="text-muted small">Oldest first.</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle gx-stock-table">
                        <thead>
                            <tr>
                                <th>Requisition No</th>
                                <th>Level</th>
                                <th>Department</th>
                                <th>Raised by</th>
                                <th>Your stage</th>
                                <th>Waiting since</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($waiting as $r)
                                @php
                                    [$sinceText, $sinceClass] = $waitingSince($details[$r->id]['days'] ?? null);
                                @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $r->requisition_no }}</td>
                                    <td>{{ \App\Models\PurchaseRequisition::levelLabels()[$r->level] ?? $r->level }}</td>
                                    <td>{{ $r->isDepartmentLevel() ? ($r->indentSection?->name ?: $r->departmentLabel()) : 'Store' }}</td>
                                    <td>{{ $r->createdBy?->name ?: '—' }}</td>
                                    <td>{{ $r->waitingStage->displayLabel() }}</td>
                                    <td><span class="{{ $sinceClass }}">{{ $sinceText }}</span></td>
                                    <td class="text-end">
                                        <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-primary"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Review</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">Nothing is waiting for you.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
