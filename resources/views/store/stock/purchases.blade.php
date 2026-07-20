@extends('layouts.app')

@section('title', 'General Stock — Purchases')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-truck"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Purchases (Receive)</h3>
                </div>
            </div>
            <a href="{{ route('store.stock.items.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-seam me-1"></i>Items</a>
        </div>
    </div>

    @include('store._flash')

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Record Purchase</h5>
                    @if($items->isEmpty())
                        <p class="text-muted small">Add a stock item first.</p>
                    @else
                    <form method="POST" action="{{ route('store.stock.purchases.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Item <span class="text-danger">*</span></label>
                        <select name="stock_item_id" class="form-select mb-3" required>
                            <option value="">Select item…</option>
                            @foreach($items as $it)<option value="{{ $it->id }}" {{ old('stock_item_id')==$it->id?'selected':'' }}>{{ $it->name }} @if($it->uom)({{ $it->uom }})@endif</option>@endforeach
                        </select>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Challan No</label><input name="challan_no" value="{{ old('challan_no') }}" class="form-control"></div>
                            <div class="col-6"><label class="form-label fw-semibold">Date <span class="text-danger">*</span></label><input type="date" name="purchase_date" value="{{ old('purchase_date', now()->toDateString()) }}" class="form-control" required></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Qty <span class="text-danger">*</span></label><input type="number" step="0.0001" min="0" name="qty" value="{{ old('qty') }}" class="form-control" required></div>
                            <div class="col-6"><label class="form-label fw-semibold">Unit Price</label><input type="number" step="0.0001" min="0" name="unit_price" value="{{ old('unit_price') }}" class="form-control"></div>
                        </div>
                        <label class="form-label fw-semibold">Supplier</label>
                        <input name="supplier_name" value="{{ old('supplier_name') }}" class="form-control mb-3">
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="1000">{{ old('remarks') }}</textarea>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add Purchase</button>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Purchase History <span class="badge bg-primary-subtle text-primary ms-1">{{ $purchases->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Date</th><th>Item</th><th>Challan</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th>Supplier</th><th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($purchases as $p)
                                    <tr>
                                        <td class="small">{{ optional($p->purchase_date)->format('d-M-Y') ?? '—' }}</td>
                                        <td><div class="fw-semibold">{{ optional($p->stockItem)->name ?? '—' }}</div></td>
                                        <td class="small">{{ $p->challan_no ?: '—' }}</td>
                                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$p->qty, 4), '0'), '.') }}</td>
                                        <td class="text-end small">{{ $p->unit_price !== null ? number_format((float)$p->unit_price, 4) : '—' }}</td>
                                        <td class="small text-muted">{{ $p->supplier_name ?: '—' }}</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('store.stock.purchases.destroy', $p) }}" onsubmit="return confirm('Remove this purchase?');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-5">No purchases recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $purchases->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
