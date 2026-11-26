@extends('layouts.app')

@section('title', 'PRA Approvals — My Step')

@section('styles')
{{-- The module token layer shared with PO Generation. These screens produce
     and route the PRA document, so they wear the document's chrome rather than
     the generic dashboard indigo. Nothing here reaches inside a printed sheet. --}}
@include('supply-chain.bookings.partials._po-module-theme')
<style>
    .pra-queue .po-panel-body { padding: 0; }
    /* A row pointed at THIS user carries the docket rail on its first cell —
       the same stripe the module uses everywhere to say "this one is yours". */
    .pra-queue tr.is-mine td:first-child { box-shadow: inset 3px 0 0 var(--po-blue); }
    .pra-queue .pra-sub { font-size: 12px; color: var(--po-muted); margin-top: 2px; }
    .pra-queue .pra-assigned {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 4px;
        font-size: 11.5px;
        font-weight: 700;
        color: var(--po-blue-deep);
    }
</style>
@endsection

@section('content')
<div class="container-fluid po-scope pra-queue">
    <x-breadcrumb :items="[
        ['label' => 'PRA Approvals'],
        ['label' => 'My Step'],
    ]" />

    <div class="po-head mb-3">
        <div class="d-flex align-items-center gap-3">
            <span class="po-head-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
            <div>
                <div class="po-micro">Approvals</div>
                <h4 class="po-head-title">Waiting for You</h4>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <span class="po-pill po-pill-blue"><i class="bi bi-clipboard-check" aria-hidden="true"></i>{{ $pendingRequests->count() }} waiting</span>
        </div>
    </div>

    @include('pra-departmental._tabs')

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3 py-2">{{ session($key) }}</div>
        @endif
    @endforeach

    <div class="po-panel">
        <div class="po-panel-head">
            <div class="po-panel-head-title">
                <span class="po-panel-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span>
                <div>
                    <div class="po-title">Payment requests at a step you can action</div>
                    <div class="pra-sub">Any eligible member of the step's team may act. A request assigned to you by name is listed first.</div>
                </div>
            </div>
        </div>
        <div class="po-panel-body">
            <div class="table-responsive">
                <table class="table po-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>PRA Number</th>
                            <th>Requested By</th>
                            <th>Buyer / Supplier</th>
                            <th class="po-num">Total PI Amount</th>
                            <th>Your Step</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pendingRequests as $pr)
                            @php
                                $stage = $stages[$pr->id];
                                $mine = $stage?->isAssignee(auth()->user());
                            @endphp
                            <tr class="{{ $mine ? 'is-mine' : '' }}">
                                <td class="fw-bold">
                                    {{ $pr->request_no }}
                                    @if($mine)
                                        <div class="pra-assigned"><i class="bi bi-person-check-fill" aria-hidden="true"></i>Assigned to you</div>
                                    @elseif($stage?->assignee)
                                        {{-- Secondary cue: named, but the step is still yours to act on. --}}
                                        <div class="pra-sub"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Assigned to {{ $stage->assignee->name }}</div>
                                    @endif
                                </td>
                                <td>{{ $pr->createdBy?->name ?? '—' }}</td>
                                <td>
                                    <div>{{ $pr->buyer_name ?: '—' }}</div>
                                    <div class="pra-sub">{{ $pr->supplier_name ?: '—' }}</div>
                                </td>
                                <td class="po-num fw-semibold">{{ number_format((float) $pr->total_pi_amount, 2) }}</td>
                                <td>
                                    <span class="po-pill po-pill-blue">Step {{ app(\App\Services\PraStageResolver::class)->ordinal($stage->sequence) }} — {{ $stage->label }}</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('pra_departmental.show', $pr) }}" class="btn btn-sm btn-primary">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>Review
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    Nothing is waiting for you right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
