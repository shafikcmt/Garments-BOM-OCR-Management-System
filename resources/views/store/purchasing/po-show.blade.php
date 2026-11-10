@extends('layouts.app')

@section('title', 'General Stock — '.$order->po_no)

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchasing'],
        ['label' => 'Internal POs', 'url' => route('store.purchasing.po.index')],
        ['label' => $order->po_no],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="file-earmark-text" eyebrow="General Stock Purchasing" :title="$order->po_no">
        <x-slot:actions>
            <a href="{{ route('store.purchasing.po.pdf', $order) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Print / PDF
            </a>
            @if(isset($allowed['edit']))
                <a href="{{ route('store.purchasing.po.edit', $order) }}" class="btn btn-outline-primary">
                    <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-flash />

    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        @include('store.purchasing._badge', ['status' => $order->status, 'label' => $order->statusLabel()])
        @if($order->statement)
            <span class="small text-muted">From
                @can('store.purchase_cs.view')
                    <a href="{{ route('store.purchasing.cs.show', $order->statement) }}">{{ $order->statement->cs_no }}</a>
                @else
                    {{ $order->statement->cs_no }}
                @endcan
            </span>
        @endif
    </div>

    @if($order->status === 'cancelled')
        <div class="alert alert-danger"><strong>Cancelled:</strong> {{ $order->cancel_reason }}</div>
    @endif

    <div class="card gx-stock-card mb-3">
        <div class="gx-stock-card-body d-flex flex-wrap align-items-start gap-3">
            @if(isset($allowed['check']))
                <form method="POST" action="{{ route('store.purchasing.po.check', $order) }}">@csrf
                    <button class="btn btn-primary"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Check PO</button>
                </form>
            @endif
            @if(isset($allowed['authorise']))
                <form method="POST" action="{{ route('store.purchasing.po.authorise', $order) }}">@csrf
                    <button class="btn btn-success"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Authorise PO</button>
                </form>
            @endif
            @if(isset($allowed['send_back']))
                <form method="POST" action="{{ route('store.purchasing.po.send-back', $order) }}">@csrf
                    <button class="btn btn-outline-secondary"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Send back</button>
                </form>
            @endif
            @if($order->isDraft() && ! isset($allowed['check']))
                <span class="small text-muted">Approval 1 of 2: someone other than the person who made it has to check this purchase order.</span>
            @elseif($order->status === 'checked' && ! isset($allowed['authorise']))
                <span class="small text-muted">Approval 2 of 2: the Authorised Signatory, who is neither the maker nor the checker, has to authorise it before Store can receive goods.</span>
            @endif

            @if(isset($allowed['cancel']))
                <form method="POST" action="{{ route('store.purchasing.po.cancel', $order) }}" class="d-flex flex-wrap gap-2 align-items-start ms-auto">@csrf
                    <input type="text" name="reason" class="form-control @error('reason') is-invalid @enderror" style="min-width:240px;" maxlength="1000" placeholder="Why cancel?" aria-label="Reason for cancelling">
                    <button class="btn btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel PO</button>
                </form>
            @endif
        </div>
    </div>

    @if(! $order->isPending() && $order->status !== 'cancelled')
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <h6 class="mb-2">Receiving progress</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Still to come</th></tr></thead>
                        <tbody>
                            @foreach($order->items as $line)
                                @php($got = $received[$line->id] ?? 0)
                                <tr>
                                    <td>{{ $line->item_name }}</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->qty, 4), '0'), '.') }}</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $got, 4), '0'), '.') ?: '0' }}</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format(max(0, (float) $line->qty - $got), 4), '0'), '.') ?: '0' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <div class="card gx-stock-card mb-5">
        <div class="gx-stock-card-body">
            <div class="table-responsive">
                <div style="min-width:720px;max-width:820px;margin:0 auto;">
                    @include('store.purchasing._po-sheet', ['order' => $order])
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
