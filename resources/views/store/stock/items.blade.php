@extends('layouts.app')

@section('title', 'General Stock — Items')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-box-seam"></i></span>
                <div>
                    <div class="app-hero-eyebrow">General Stock</div>
                    <h3 class="app-hero-title mb-0">Item Master</h3>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.stock.ledger') }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text me-1"></i>Monthly Ledger</a>
                <a href="{{ route('store.stock.purchases.index') }}" class="btn btn-outline-secondary"><i class="bi bi-truck me-1"></i>Purchases</a>
                <a href="{{ route('store.stock.issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1"></i>Issues</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Add Item</h5>
                    <form method="POST" action="{{ route('store.stock.items.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
                        <input name="name" value="{{ old('name') }}" class="form-control mb-3" required>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Code</label>
                                <input name="code" value="{{ old('code') }}" class="form-control">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">UOM</label>
                                <input name="uom" value="{{ old('uom') }}" class="form-control" placeholder="pcs / kg / yd">
                            </div>
                        </div>
                        <label class="form-label fw-semibold">Category</label>
                        <input name="category" value="{{ old('category') }}" class="form-control mb-3" placeholder="Consumable / Accessory">
                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label fw-semibold small">Safety Stock</label>
                                <input type="number" step="0.0001" min="0" name="safety_stock_qty" value="{{ old('safety_stock_qty') }}" class="form-control">
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold small">Re-order Lvl</label>
                                <input type="number" step="0.0001" min="0" name="reorder_level" value="{{ old('reorder_level') }}" class="form-control">
                            </div>
                            <div class="col-4">
                                <label class="form-label fw-semibold small">Lead (days)</label>
                                <input type="number" min="0" name="lead_time_days" value="{{ old('lead_time_days') }}" class="form-control">
                            </div>
                        </div>
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="1000">{{ old('remarks') }}</textarea>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Items <span class="badge bg-primary-subtle text-primary ms-1">{{ $items->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Item</th>
                                    <th>UOM</th>
                                    <th class="text-end">Current Stock</th>
                                    <th class="text-end">Safety / Re-order</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($items as $item)
                                    @php $current = (float) $item->purchased_qty - (float) $item->issued_qty; @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-slate-900">{{ $item->name }}</div>
                                            <div class="small text-muted">{{ $item->code ?: '—' }} @if($item->category) · {{ $item->category }}@endif</div>
                                        </td>
                                        <td class="small">{{ $item->uom ?: '—' }}</td>
                                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format($current, 4), '0'), '.') }}</td>
                                        <td class="text-end small text-muted">
                                            {{ $item->safety_stock_qty !== null ? rtrim(rtrim(number_format($item->safety_stock_qty, 4), '0'), '.') : '—' }}
                                            /
                                            {{ $item->reorder_level !== null ? rtrim(rtrim(number_format($item->reorder_level, 4), '0'), '.') : '—' }}
                                        </td>
                                        <td>
                                            @if($item->is_active)
                                                <span class="badge bg-success-subtle text-success">Active</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editItem{{ $item->id }}"><i class="bi bi-pencil"></i></button>
                                            <form method="POST" action="{{ route('store.stock.items.destroy', $item) }}" class="d-inline" onsubmit="return confirm('Remove this item?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editItem{{ $item->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content" style="border-radius:14px;">
                                                <form method="POST" action="{{ route('store.stock.items.update', $item) }}">
                                                    @csrf @method('PUT')
                                                    <div class="modal-header"><h5 class="modal-title">Edit Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                                    <div class="modal-body">
                                                        <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
                                                        <input name="name" value="{{ $item->name }}" class="form-control mb-3" required>
                                                        <div class="row g-2 mb-3">
                                                            <div class="col-6"><label class="form-label fw-semibold">Code</label><input name="code" value="{{ $item->code }}" class="form-control"></div>
                                                            <div class="col-6"><label class="form-label fw-semibold">UOM</label><input name="uom" value="{{ $item->uom }}" class="form-control"></div>
                                                        </div>
                                                        <label class="form-label fw-semibold">Category</label>
                                                        <input name="category" value="{{ $item->category }}" class="form-control mb-3">
                                                        <div class="row g-2 mb-3">
                                                            <div class="col-4"><label class="form-label fw-semibold small">Safety Stock</label><input type="number" step="0.0001" min="0" name="safety_stock_qty" value="{{ $item->safety_stock_qty }}" class="form-control"></div>
                                                            <div class="col-4"><label class="form-label fw-semibold small">Re-order Lvl</label><input type="number" step="0.0001" min="0" name="reorder_level" value="{{ $item->reorder_level }}" class="form-control"></div>
                                                            <div class="col-4"><label class="form-label fw-semibold small">Lead (days)</label><input type="number" min="0" name="lead_time_days" value="{{ $item->lead_time_days }}" class="form-control"></div>
                                                        </div>
                                                        <div class="form-check mb-3">
                                                            <input type="hidden" name="is_active" value="0">
                                                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active{{ $item->id }}" {{ $item->is_active ? 'checked' : '' }}>
                                                            <label class="form-check-label" for="active{{ $item->id }}">Active</label>
                                                        </div>
                                                        <label class="form-label fw-semibold">Remarks</label>
                                                        <textarea name="remarks" rows="2" class="form-control" maxlength="1000">{{ $item->remarks }}</textarea>
                                                    </div>
                                                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-5">No stock items yet. Add one on the left.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $items->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
