@extends('layouts.app')

@section('title', 'PRA Approvals — All PRAs')

@section('styles')
@include('supply-chain.bookings.partials._po-module-theme')
<style>
    .pra-all .po-panel-body { padding: 0; }
    .pra-all .pra-sub { font-size: 12px; color: var(--po-muted); margin-top: 2px; }
</style>
@endsection

@section('content')
<div class="container-fluid po-scope pra-all">
    <x-breadcrumb :items="[
        ['label' => 'PRA Approvals'],
        ['label' => 'All PRAs'],
    ]" />

    <div class="po-head mb-3">
        <div class="d-flex align-items-center gap-3">
            <span class="po-head-icon"><i class="bi bi-collection" aria-hidden="true"></i></span>
            <div>
                <div class="po-micro">Approvals</div>
                <h4 class="po-head-title">All PRAs</h4>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <span class="po-pill po-pill-blue"><i class="bi bi-files" aria-hidden="true"></i>{{ $requests->count() }} {{ $isAdmin ? 'in total' : 'you took part in' }}</span>
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
                <span class="po-panel-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                <div>
                    <div class="po-title">{{ $isAdmin ? 'Every departmental payment request' : 'Payment requests you raised or acted on' }}</div>
                    <div class="pra-sub">{{ $isAdmin ? 'Oversight of the whole chain, at every step.' : 'Read-only once your step has passed. A request that comes back to you appears under My Step.' }}</div>
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
                            <th>Current Step</th>
                            <th>Status</th>
                            @unless($isAdmin)<th>Your Decision</th>@endunless
                            <th class="po-num">Total PI Amount</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requests as $pr)
                            @php
                                $progress = $pr->flowProgress();
                                $statusPill = match ($pr->status) {
                                    'completed' => 'po-pill-ok',
                                    'rejected' => 'po-pill-danger',
                                    default => 'po-pill-warn',
                                };
                                $statusText = match ($pr->status) {
                                    'completed' => 'Completed',
                                    'rejected' => 'Rejected',
                                    default => 'In progress',
                                };
                                $mine = $myDecisions[$pr->id] ?? null;
                            @endphp
                            <tr>
                                <td class="fw-bold">
                                    {{ $pr->request_no }}
                                    <div class="pra-sub">{{ optional($pr->created_at)->format('d M Y') }}</div>
                                </td>
                                <td>{{ $pr->createdBy?->name ?? '—' }}</td>
                                <td>
                                    <div>{{ $pr->buyer_name ?: '—' }}</div>
                                    <div class="pra-sub">{{ $pr->supplier_name ?: '—' }}</div>
                                </td>
                                <td>
                                    @if($pr->current_sequence)
                                        <span class="po-pill po-pill-blue">{{ $progress['label'] }}</span>
                                    @else
                                        <span class="pra-sub">—</span>
                                    @endif
                                </td>
                                <td><span class="po-pill {{ $statusPill }}">{{ $statusText }}</span></td>
                                @unless($isAdmin)
                                    <td>
                                        @if($mine)
                                            <div class="fw-semibold">{{ $mine->isApproved() ? 'Approved' : 'Rejected' }} — step {{ $mine->sequence }}</div>
                                            <div class="pra-sub">{{ optional($mine->acted_at)->format('d M Y') }}</div>
                                        @elseif((int) $pr->created_by === (int) auth()->id())
                                            <div class="pra-sub">Raised by you</div>
                                        @else
                                            <span class="pra-sub">—</span>
                                        @endif
                                    </td>
                                @endunless
                                <td class="po-num fw-semibold">{{ number_format((float) $pr->total_pi_amount, 2) }}</td>
                                <td class="text-end">
                                    <a href="{{ route('pra_departmental.show', $pr) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isAdmin ? 7 : 8 }}" class="text-center text-muted py-5">
                                    {{ $isAdmin ? 'No departmental payment request has been raised yet.' : 'You have not raised or acted on any payment request yet.' }}
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
