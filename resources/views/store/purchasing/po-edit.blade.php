@extends('layouts.app')

@section('title', 'General Stock — Edit '.$order->po_no)

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'General Stock'],
        ['label' => 'Purchasing'],
        ['label' => 'Internal POs', 'url' => route('store.purchasing.po.index')],
        ['label' => $order->po_no, 'url' => route('store.purchasing.po.show', $order)],
        ['label' => 'Edit'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="file-earmark-text" eyebrow="General Stock Purchasing" :title="'Edit '.$order->po_no"
                   copy="Quantity and rate come from the approved Comparative Statement and cannot be changed here." />

    <x-flash />

    <form method="POST" action="{{ route('store.purchasing.po.update', $order) }}" novalidate>
        @csrf @method('PUT')

        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="po_no">PO No</label>
                        <input type="text" id="po_no" class="form-control" value="{{ $order->po_no }}" readonly>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="po_date">Date</label>
                        <input type="date" id="po_date" name="po_date" class="form-control @error('po_date') is-invalid @enderror" value="{{ old('po_date', $order->po_date?->toDateString()) }}">
                        @error('po_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="supplier">Supplier</label>
                        <input type="text" id="supplier" class="form-control" value="{{ $order->supplier_name }}" readonly>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="invoice_to">Invoice to</label>
                        <textarea id="invoice_to" name="invoice_to" rows="3" class="form-control">{{ old('invoice_to', $order->invoice_to) }}</textarea>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="consignee">Consignee (ship to)</label>
                        <textarea id="consignee" name="consignee" rows="3" class="form-control">{{ old('consignee', $order->consignee) }}</textarea>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="reference_no">Reference No. &amp; date</label>
                        <input type="text" id="reference_no" name="reference_no" class="form-control" maxlength="100" value="{{ old('reference_no', $order->reference_no) }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="bin_number">BIN number</label>
                        <input type="text" id="bin_number" class="form-control" value="{{ \App\Support\PurchasingSettings::poDefaults()['bin_number'] }}" readonly>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="dispatched_through">Dispatched through</label>
                        <input type="text" id="dispatched_through" name="dispatched_through" class="form-control" maxlength="150" value="{{ old('dispatched_through', $order->dispatched_through) }}">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="destination">Destination</label>
                        <input type="text" id="destination" name="destination" class="form-control" maxlength="255" value="{{ old('destination', $order->destination) }}">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="payment_terms">Mode / terms of payment</label>
                        <textarea id="payment_terms" name="payment_terms" rows="3" class="form-control">{{ old('payment_terms', $order->payment_terms) }}</textarea>
                    </div>
                    @php
                        $splitLines = fn ($text) => array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $text), fn ($l) => trim($l) !== ''));
                        $deliveryLines = old('delivery_lines', $splitLines($order->delivery_terms)) ?: [''];
                        $remarkLines = old('remark_lines', $splitLines($order->remarks)) ?: [''];
                    @endphp
                    {{-- One box per line. Terms of delivery holds up to 5 lines. --}}
                    <div class="col-12 col-md-4" data-lines data-max="5">
                        <label class="form-label">Terms of delivery <span class="text-muted small">(up to 5 lines)</span></label>
                        <div data-lines-list>
                            @foreach($deliveryLines as $line)
                                <input type="text" name="delivery_lines[]" class="form-control form-control-sm mb-1" maxlength="190" value="{{ $line }}" aria-label="Terms of delivery line">
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-lines-add data-name="delivery_lines[]">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Line
                        </button>
                        @error('delivery_terms')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4" data-lines data-max="10">
                        <label class="form-label">Remarks <span class="text-muted small">(numbered on the PO)</span></label>
                        <div data-lines-list>
                            @foreach($remarkLines as $line)
                                <input type="text" name="remark_lines[]" class="form-control form-control-sm mb-1" maxlength="190" value="{{ $line }}" aria-label="Remark">
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-lines-add data-name="remark_lines[]">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Remark
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card gx-stock-card mb-3">
            <div class="gx-stock-card-body">
                <div class="table-responsive">
                    <table class="table align-middle gx-stock-table">
                        <thead>
                            <tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th><th>Due on</th><th>Remarks</th></tr>
                        </thead>
                        <tbody>
                            @foreach($order->items as $line)
                                <tr>
                                    <td class="fw-semibold">{{ $line->item_name }} <span class="text-muted small">{{ $line->uom }}</span></td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->qty, 4), '0'), '.') }}</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->rate, 4), '0'), '.') }}</td>
                                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->amount, 2), '0'), '.') }}</td>
                                    <td>{{ $line->due_on }}</td>
                                    <td>{{ $line->remarks }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr class="fw-semibold"><td colspan="3" class="text-end">Total</td><td class="text-end">{{ rtrim(rtrim(number_format((float) $order->total_amount, 2), '0'), '.') }}</td><td colspan="2"></td></tr></tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mb-5">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Save</button>
            <a href="{{ route('store.purchasing.po.show', $order) }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Cancel</a>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
    // Add Line / Add Remark: one more box, up to the list's limit.
    document.querySelectorAll('[data-lines]').forEach(function (group) {
        var list = group.querySelector('[data-lines-list]');
        var add = group.querySelector('[data-lines-add]');
        var max = parseInt(group.dataset.max || '10', 10);
        function refresh() { add.disabled = list.querySelectorAll('input').length >= max; }
        add.addEventListener('click', function () {
            var input = list.querySelector('input').cloneNode(false);
            input.value = '';
            list.appendChild(input);
            input.focus();
            refresh();
        });
        refresh();
    });
</script>

@endsection
