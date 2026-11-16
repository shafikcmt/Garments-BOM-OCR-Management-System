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
                @php($toOrder = $poPlan->where('items', '>', 0)->count())
                <form method="POST" action="{{ route('store.purchasing.po.store', $cs) }}">@csrf
                    <button class="btn btn-success"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>{{ $toOrder > 1 ? 'Make All Purchase Orders (' . $toOrder . ')' : 'Make Purchase Order' }}</button>
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

    {{-- Order by supplier: one PO per chosen supplier, made together or one supplier at a time. --}}
    @if($poPlan->count() > 1)
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <h6 class="mb-1">Purchase orders by supplier</h6>
                <p class="small text-muted mb-2">The items go to the supplier chosen for each line, one purchase order per supplier. Make them together with the button above, or one supplier at a time here.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead><tr><th>Supplier</th><th class="text-end">Items to order</th><th class="text-end">Amount</th><th>Purchase orders made</th><th></th></tr></thead>
                        <tbody>
                        @foreach($poPlan as $row)
                            <tr>
                                <td class="fw-semibold">{{ $row['supplier']->name }}</td>
                                <td class="text-end">{{ $row['items'] }}</td>
                                <td class="text-end">{{ number_format($row['amount'], 2) }}</td>
                                <td>{{ $row['orders'] ? implode(', ', $row['orders']) : '—' }}</td>
                                <td class="text-end">
                                    @if(in_array('make_po', $allowed, true) && $row['items'] > 0)
                                        <form method="POST" action="{{ route('store.purchasing.po.store', $cs) }}" class="d-inline">@csrf
                                            <input type="hidden" name="supplier" value="{{ $row['supplier']->id }}">
                                            <button class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Make PO for {{ $row['supplier']->name }}</button>
                                        </form>
                                    @elseif($row['items'] === 0)
                                        <span class="text-muted small">All ordered</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

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

    {{-- Price comparison: screen only, never on the printed form. --}}
    @if($comparison['has_quotes'])
        @php($money = fn ($n) => number_format((float) $n, 2))
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <h6 class="mb-2">Price comparison</h6>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2" style="min-width:0">
                        <thead><tr><th>Supplier</th><th class="text-end">Items quoted</th><th class="text-end">Total of its quote</th><th class="text-end">Lowest on</th><th class="text-end">Chosen on</th><th class="text-end">Chosen amount</th></tr></thead>
                        <tbody>
                        @foreach($comparison['suppliers'] as $row)
                            @php($isBest = $comparison['best_single'] && $comparison['best_single']['supplier']->id === $row['supplier']->id)
                            <tr>
                                <td class="fw-semibold">{{ $row['supplier']->name }}@if($row['supplier']->is_recommended) <span class="badge bg-light text-dark border">recommended</span>@endif</td>
                                <td class="text-end">{{ $row['quoted'] }} of {{ $cs->items->count() }}</td>
                                <td class="text-end {{ $isBest ? 'text-success fw-semibold' : '' }}">{{ $money($row['total']) }}@if($isBest)<div class="small">lowest full quote</div>@endif</td>
                                <td class="text-end">{{ $row['lowest_on'] }} item(s)</td>
                                <td class="text-end">{{ $row['chosen_on'] }} item(s)</td>
                                <td class="text-end">{{ $money($row['chosen_amount']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($comparison['extra'] > 0)
                    <div class="alert alert-warning py-2 mb-2">
                        The suppliers chosen come to <strong>{{ $money($comparison['chosen_total']) }}</strong>. Taking the lowest price on every line would be <strong>{{ $money($comparison['lowest_total']) }}</strong>:
                        <strong>{{ $money($comparison['extra']) }}</strong>@if($comparison['extra_pct'] !== null) ({{ $comparison['extra_pct'] }}%)@endif more.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="min-width:0">
                            <thead><tr><th>Item where the chosen supplier is not the lowest</th><th class="text-end">Qty</th><th>Chosen</th><th class="text-end">Price</th><th>Lowest</th><th class="text-end">Price</th><th class="text-end">Extra cost</th></tr></thead>
                            <tbody>
                            @foreach($comparison['not_lowest'] as $r)
                                <tr>
                                    <td>{{ $r['item']->item_name }}@if(filled($r['item']->remarks))<div class="small text-muted">Remark: {{ $r['item']->remarks }}</div>@endif</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format($r['qty'], 4), '0'), '.') }}</td>
                                    <td>{{ $r['chosen_name'] }}</td><td class="text-end">{{ $money($r['chosen_price']) }}</td>
                                    <td class="text-success">{{ $r['lowest_name'] }}</td><td class="text-end text-success">{{ $money($r['lowest_price']) }}</td>
                                    <td class="text-end text-danger">+{{ $money($r['extra']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif($comparison['chosen_total'] > 0)
                    <div class="alert alert-success py-2 mb-0">The chosen supplier is the lowest price on every line chosen ({{ $money($comparison['chosen_total']) }}).</div>
                @endif
                @if($comparison['unchosen'] > 0)
                    <p class="small text-muted mt-2 mb-0">{{ $comparison['unchosen'] }} line(s) have quotes but no supplier chosen yet.</p>
                @endif
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
