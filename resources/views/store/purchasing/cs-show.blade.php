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
                <form method="POST" action="{{ route('store.purchasing.po.preview', $cs) }}" data-po-form>@csrf
                    <input type="hidden" name="basis" value="{{ $basis }}">
                    <button class="btn btn-success"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>{{ $toOrder > 1 ? 'Preview All Purchase Orders (' . $toOrder . ')' : 'Preview Purchase Order' }}</button>
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

    {{-- WHO THE PURCHASE ORDERS GO TO. By default each line is ordered from the supplier with the lowest
         real price; a supplier that did not price a line (blank or 0) is ignored for it. The other
         choice orders from the supplier the committee ticked on the CS. --}}
    @if(in_array('make_po', $allowed, true) && ($poPlan->isNotEmpty() || ($unpricedLines ?? collect())->isNotEmpty()))
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body d-flex flex-wrap align-items-center gap-3">
                <div class="fw-semibold">Order from the lowest price</div>
                <div class="small text-muted">Each item goes to the supplier with the lowest price. A supplier with no price on an item is ignored for it. You see the purchase order and check it before it is generated.</div>
                @if(($unpricedLines ?? collect())->isNotEmpty())
                    <div class="small text-warning w-100">{{ $unpricedLines->count() }} item(s) have no usable price on this basis and cannot be ordered: {{ $unpricedLines->pluck('item_name')->take(4)->implode(', ') }}{{ $unpricedLines->count() > 4 ? '…' : '' }}</div>
                @endif
            </div>
        </div>
    @endif

    {{-- QUANTITY TO ORDER. The approved quantities are the start; change a line here before the
         purchase orders are made. 0 leaves a line out (it stays to be ordered later). The rate is
         the approved one and cannot be changed. The numbers travel with whichever "Make PO"
         button is pressed (see the script at the end). --}}
    @if(in_array('make_po', $allowed, true) && ($pendingLines ?? collect())->isNotEmpty())
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <h6 class="mb-1">Quantity to order</h6>
                <p class="small text-muted mb-2">Change a quantity before making the purchase order. The rate stays as approved. Enter 0 to leave a line out for now. Quantities you do not change are ordered as approved.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="min-width:0">
                        <thead><tr><th>Item</th><th>Supplier</th><th class="text-end">Approved qty</th><th class="text-end">Rate</th><th class="text-end" style="width:140px;">Order qty</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        @foreach($pendingLines as $line)
                            @php($rate = (float) $line->order_rate)
                            @php($qtyShown = rtrim(rtrim(number_format((float) $line->qty, 4, '.', ''), '0'), '.'))
                            <tr>
                                <td>{{ $line->item_name }} <span class="text-muted small">{{ $line->uom }}</span></td>
                                <td class="small">{{ $cs->suppliers->firstWhere('id', $line->order_supplier_id)?->name }}@if((int) $line->selected_supplier_id !== (int) $line->order_supplier_id && $line->selected_supplier_id) <span class="text-muted">(CS chose {{ $cs->suppliers->firstWhere('id', $line->selected_supplier_id)?->name }})</span>@endif</td>
                                <td class="text-end">{{ $qtyShown }}</td>
                                <td class="text-end">{{ number_format($rate, 2) }}</td>
                                <td class="text-end">
                                    <input type="number" step="any" min="0" class="form-control form-control-sm text-end" value="{{ $qtyShown }}"
                                           data-order-qty="{{ $line->id }}" data-rate="{{ $rate }}" data-approved="{{ (float) $line->qty }}" aria-label="Quantity to order for {{ $line->item_name }}">
                                </td>
                                <td class="text-end" data-line-amount>{{ number_format((float) $line->qty * $rate, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="5" class="text-end">Total to order</th><th class="text-end" id="orderQtyTotal"></th></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>
    @endif

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
                                        <form method="POST" action="{{ route('store.purchasing.po.preview', $cs) }}" class="d-inline" data-po-form>@csrf
                                            <input type="hidden" name="basis" value="{{ $basis }}">
                                            <input type="hidden" name="supplier" value="{{ $row['supplier']->id }}">
                                            <button class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Preview PO for {{ $row['supplier']->name }}</button>
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

    {{-- Purchase orders made from this statement: live ones in a table, cancelled ones kept out of
         the way (history only, nothing is deleted). --}}
    <?php
        $livePos = $cs->purchaseOrders->where('status', '!=', \App\Models\GsPurchaseOrder::STATUS_CANCELLED)->values();
        $cancelledPos = $cs->purchaseOrders->where('status', \App\Models\GsPurchaseOrder::STATUS_CANCELLED)->values();
        $poBadge = fn ($status) => match ($status) {
            'draft' => 'text-bg-secondary',
            'checked' => 'text-bg-info',
            'issued', 'received' => 'text-bg-success',
            'partially_received' => 'text-bg-warning',
            default => 'text-bg-light border',
        };
    ?>
    @if($cs->purchaseOrders->isNotEmpty())
        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <h6 class="mb-0">Purchase orders</h6>
                    <span class="small text-muted">{{ $livePos->count() }} active</span>
                </div>

                @if($livePos->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="min-width:0">
                            <thead><tr><th>PO No.</th><th>Supplier</th><th>Status</th><th class="text-end">Amount (Tk)</th><th></th></tr></thead>
                            <tbody>
                            @foreach($livePos as $po)
                                <tr>
                                    <td class="fw-semibold">{{ $po->po_no }}</td>
                                    <td>{{ $po->supplier_name }}</td>
                                    <td><span class="badge {{ $poBadge($po->status) }}">{{ $po->statusLabel() }}</span></td>
                                    <td class="text-end">{{ number_format((float) $po->total_amount, 2) }}</td>
                                    <td class="text-end">
                                        @can('store.purchase_po.view')
                                            <a href="{{ route('store.purchasing.po.show', $po) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Open</a>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="small text-muted">No active purchase order. Everything made from this statement was cancelled.</div>
                @endif

                @if($cancelledPos->isNotEmpty())
                    <details class="mt-3">
                        <summary class="small text-muted" style="cursor:pointer;">{{ $cancelledPos->count() }} cancelled purchase order{{ $cancelledPos->count() === 1 ? '' : 's' }} (history)</summary>
                        <div class="small text-muted mt-2">
                            @foreach($cancelledPos as $po)
                                <div>
                                    @can('store.purchase_po.view')<a href="{{ route('store.purchasing.po.show', $po) }}">{{ $po->po_no }}</a>@else{{ $po->po_no }}@endcan
                                    &middot; {{ $po->supplier_name }} &middot; cancelled
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
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

@section('scripts')
<script>
(function () {
    var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-order-qty]'));
    if (!inputs.length) return;

    function recalc() {
        var total = 0;
        inputs.forEach(function (input) {
            var qty = parseFloat(input.value);
            var amount = (isFinite(qty) && qty > 0 ? qty : 0) * parseFloat(input.dataset.rate);
            total += amount;
            input.closest('tr').querySelector('[data-line-amount]').textContent = amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            input.classList.toggle('border-warning', isFinite(qty) && qty !== parseFloat(input.dataset.approved));
        });
        document.getElementById('orderQtyTotal').textContent = total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    inputs.forEach(function (input) { input.addEventListener('input', recalc); });
    recalc();

    // Whichever "Make PO" button is pressed, the chosen quantities go with it.
    document.querySelectorAll('form[data-po-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (inputs.some(function (i) { return i.value.trim() === '' || !isFinite(parseFloat(i.value)) || parseFloat(i.value) < 0; })) {
                event.preventDefault();
                alert('Enter a quantity (0 or more) for every line.');
                return;
            }
            form.querySelectorAll('input[data-injected]').forEach(function (n) { n.remove(); });
            inputs.forEach(function (i) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'qty[' + i.dataset.orderQty + ']'; h.value = i.value; h.setAttribute('data-injected', '1');
                form.appendChild(h);
            });
        });
    });
})();
</script>
@endsection
