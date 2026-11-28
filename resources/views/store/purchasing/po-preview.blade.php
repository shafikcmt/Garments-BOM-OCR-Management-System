@extends('layouts.app')

@section('title', 'Preview purchase orders — '.$cs->cs_no)

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[['label' => 'General Stock'], ['label' => 'Purchasing'], ['label' => 'Comparative Statements', 'url' => route('store.purchasing.cs.index')], ['label' => $cs->cs_no, 'url' => route('store.purchasing.cs.show', $cs)], ['label' => 'Preview']]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="eye" eyebrow="General Stock Purchasing" title="Check before generating"
                   copy="This is how the purchase order{{ $orders->count() > 1 ? 's' : '' }} will look. Nothing has been created yet. The PO number is given when you press Generate." />

    <x-flash />
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    {{-- What will be made, at a glance, and the two choices: generate, or go back. --}}
    <div class="card gx-stock-card mb-3">
        <div class="gx-stock-card-body">
            <div class="table-responsive mb-3">
                <table class="table table-sm align-middle mb-0" style="min-width:0">
                    <thead><tr><th>Purchase order for</th><th class="text-end">Items</th><th class="text-end">Amount (Tk)</th></tr></thead>
                    <tbody>
                    @foreach($orders as $po)
                        <tr>
                            <td class="fw-semibold">{{ $po->supplier_name }}</td>
                            <td class="text-end">{{ $po->items->count() }}</td>
                            <td class="text-end">{{ number_format((float) $po->total_amount, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr><th>Total</th><th class="text-end">{{ $orders->sum(fn ($po) => $po->items->count()) }}</th><th class="text-end">{{ number_format((float) $orders->sum('total_amount'), 2) }}</th></tr></tfoot>
                </table>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <form method="POST" action="{{ route('store.purchasing.po.store', $cs) }}">@csrf
                    @if(! empty($carry['supplier']))<input type="hidden" name="supplier" value="{{ $carry['supplier'] }}">@endif
                    <input type="hidden" name="basis" value="{{ $carry['basis'] }}">
                    @foreach($carry['qty'] as $itemId => $qty)<input type="hidden" name="qty[{{ $itemId }}]" value="{{ $qty }}">@endforeach
                    <button class="btn btn-success"><i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>Generate {{ $orders->count() > 1 ? 'these ' . $orders->count() . ' Purchase Orders' : 'Purchase Order' }}</button>
                </form>
                <a href="{{ route('store.purchasing.cs.show', $cs) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to change quantities</a>
            </div>
        </div>
    </div>

    @foreach($orders as $po)
        <div class="card gx-stock-card mb-4">
            <div class="gx-stock-card-body">
                <div class="small text-muted mb-2">Preview {{ $loop->iteration }} of {{ $orders->count() }}: {{ $po->supplier_name }}</div>
                <div class="table-responsive">
                    <div style="min-width:720px;max-width:820px;margin:0 auto;">
                        @include('store.purchasing._po-sheet', ['order' => $po])
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
