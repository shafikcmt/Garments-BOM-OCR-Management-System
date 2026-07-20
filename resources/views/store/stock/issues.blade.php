@extends('layouts.app')

@section('title', 'General Stock — Issues')

@section('content')
<div class="container-fluid">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => route('store.dashboard')],
        ['label' => 'General Stock'],
        ['label' => 'Issues'],
    ]" />

    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-arrow-up"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Issues (Consumption / Non-Stock)</h3>
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
                    <h5 class="mb-3">Record Issue</h5>
                    <form method="POST" action="{{ route('store.stock.issues.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Issue Type</label>
                        <select name="is_stock_item" id="issueType" class="form-select mb-3">
                            <option value="1" {{ old('is_stock_item','1')=='1'?'selected':'' }}>Stock Item (Consumption)</option>
                            <option value="0" {{ old('is_stock_item')=='0'?'selected':'' }}>Non-Stock (free item)</option>
                        </select>

                        <div id="stockItemBlock">
                            <label class="form-label fw-semibold">Item</label>
                            <select name="stock_item_id" class="form-select mb-3">
                                <option value="">Select item…</option>
                                @foreach($items as $it)<option value="{{ $it->id }}" {{ old('stock_item_id')==$it->id?'selected':'' }}>{{ $it->name }} @if($it->uom)({{ $it->uom }})@endif</option>@endforeach
                            </select>
                        </div>
                        <div id="nonStockBlock" class="d-none">
                            <label class="form-label fw-semibold">Item Description</label>
                            <input name="item_description" value="{{ old('item_description') }}" class="form-control mb-3" placeholder="Non-stock item name">
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Requisition No</label><input name="requisition_no" value="{{ old('requisition_no') }}" class="form-control"></div>
                            <div class="col-6"><label class="form-label fw-semibold">Date <span class="text-danger">*</span></label><input type="date" name="issue_date" value="{{ old('issue_date', now()->toDateString()) }}" class="form-control" required></div>
                        </div>
                        <label class="form-label fw-semibold">Qty <span class="text-danger">*</span></label>
                        <input type="number" step="0.0001" min="0" name="qty" value="{{ old('qty') }}" class="form-control mb-3" required>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Issued To</label><input name="issued_to" value="{{ old('issued_to') }}" class="form-control"></div>
                            <div class="col-6"><label class="form-label fw-semibold">Department</label><input name="department" value="{{ old('department') }}" class="form-control"></div>
                        </div>
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="1000">{{ old('remarks') }}</textarea>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add Issue</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Issue History <span class="badge bg-primary-subtle text-primary ms-1">{{ $issues->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Date</th><th>Item</th><th>Req No</th><th class="text-end">Qty</th><th>Issued To</th><th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($issues as $i)
                                    <tr>
                                        <td class="small">{{ optional($i->issue_date)->format('d-M-Y') ?? '—' }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ $i->is_stock_item ? (optional($i->stockItem)->name ?? '—') : $i->item_description }}</div>
                                            @unless($i->is_stock_item)<span class="badge bg-warning-subtle text-warning">Non-Stock</span>@endunless
                                        </td>
                                        <td class="small">{{ $i->requisition_no ?: '—' }}</td>
                                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$i->qty, 4), '0'), '.') }}</td>
                                        <td class="small text-muted">{{ $i->issued_to ?: '—' }}</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('store.stock.issues.destroy', $i) }}" onsubmit="return confirm('Remove this issue?');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-5">No issues recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $issues->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var type = document.getElementById('issueType');
        var stockBlock = document.getElementById('stockItemBlock');
        var nonStockBlock = document.getElementById('nonStockBlock');
        function sync() {
            var isStock = type.value === '1';
            stockBlock.classList.toggle('d-none', !isStock);
            nonStockBlock.classList.toggle('d-none', isStock);
        }
        if (type) { type.addEventListener('change', sync); sync(); }
    })();
</script>
@endsection
