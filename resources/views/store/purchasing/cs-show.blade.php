@extends('layouts.app')

@section('title', 'General Stock — '.$cs->cs_no)

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchasing'],
        ['label' => 'Comparative Statements', 'url' => route('store.purchasing.cs.index')],
        ['label' => $cs->cs_no],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="columns-gap" eyebrow="General Stock Purchasing" :title="$cs->cs_no">
        <x-slot:actions>
            <a href="{{ route('store.purchasing.cs.pdf', $cs) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Print / PDF
            </a>
            @if(in_array('edit', $allowed, true))
                <a href="{{ route('store.purchasing.cs.edit', $cs) }}" class="btn btn-outline-primary">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        @include('store.purchasing._badge', ['status' => $cs->status, 'label' => $cs->statusLabel()])
        <span class="small text-muted">Made by {{ $cs->creator?->name ?? '—' }} on {{ $cs->created_at?->format('d-M-Y') }}</span>
    </div>

    @if($cs->status === 'draft' && $cs->return_note)
        <div class="alert alert-warning">
            <strong>Sent back{{ $cs->returnedBy ? ' by '.$cs->returnedBy->name : '' }}:</strong> {{ $cs->return_note }}
        </div>
    @endif

    @if($cs->status === 'cancelled')
        <div class="alert alert-danger"><strong>Cancelled:</strong> {{ $cs->cancel_reason }}</div>
    @endif

    {{-- What this user can do next. Same answer the routes use, so a button never leads to a 403. --}}
    <div class="card gx-stock-card mb-3">
        <div class="gx-stock-card-body d-flex flex-wrap align-items-start gap-3">
            @if(in_array('submit', $allowed, true))
                <form method="POST" action="{{ route('store.purchasing.cs.submit', $cs) }}">@csrf
                    <button class="btn btn-primary"><i class="bi bi-send me-1" aria-hidden="true"></i>Send for Checking</button>
                </form>
            @endif

            @if(in_array('check', $allowed, true))
                <form method="POST" action="{{ route('store.purchasing.cs.check', $cs) }}">@csrf
                    <button class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Mark as Checked</button>
                </form>
            @endif

            @if(in_array('approve', $allowed, true))
                <form method="POST" action="{{ route('store.purchasing.cs.approve', $cs) }}" class="d-flex flex-wrap gap-2 align-items-start">@csrf
                    <input type="text" name="approver_note" class="form-control" style="min-width:280px;" maxlength="1000" placeholder="Approver note (optional)" aria-label="Approver note">
                    <button class="btn btn-success"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Approve</button>
                </form>
            @endif

            @if(in_array('make_po', $allowed, true))
                <form method="POST" action="{{ route('store.purchasing.po.store', $cs) }}">@csrf
                    <button class="btn btn-success"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Make Purchase Order</button>
                </form>
            @endif

            @if(in_array('send_back', $allowed, true))
                <form method="POST" action="{{ route('store.purchasing.cs.send-back', $cs) }}" class="d-flex flex-wrap gap-2 align-items-start">@csrf
                    <input type="text" name="note" class="form-control @error('note') is-invalid @enderror" style="min-width:280px;" maxlength="1000" placeholder="What needs to be corrected?" aria-label="Reason for sending back">
                    <button class="btn btn-outline-warning"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Send Back</button>
                </form>
            @endif

            @if(in_array('cancel', $allowed, true))
                <form method="POST" action="{{ route('store.purchasing.cs.cancel', $cs) }}" class="d-flex flex-wrap gap-2 align-items-start ms-auto">@csrf
                    <input type="text" name="reason" class="form-control @error('reason') is-invalid @enderror" style="min-width:240px;" maxlength="1000" placeholder="Why cancel?" aria-label="Reason for cancelling">
                    <button class="btn btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel Statement</button>
                </form>
            @endif

            @if($allowed === [])
                <span class="text-muted small">No action is waiting on you for this statement.</span>
            @endif
        </div>
    </div>

    @if($cs->purchaseOrders->isNotEmpty())
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <h6 class="mb-2">Purchase orders made from this statement</h6>
                <ul class="mb-0">
                    @foreach($cs->purchaseOrders as $po)
                        <li>
                            @can('store.purchase_po.view')
                                <a href="{{ route('store.purchasing.po.show', $po) }}">{{ $po->po_no }}</a>
                            @else
                                {{ $po->po_no }}
                            @endcan
                            &middot; {{ $po->supplier_name }} &middot; {{ $po->statusLabel() }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="card gx-stock-card mb-5">
        <div class="gx-stock-card-body">
            <div class="table-responsive" style="min-width:0;">
                <div style="min-width:1000px;">
                    @include('store.purchasing._cs-sheet', ['cs' => $cs])
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
