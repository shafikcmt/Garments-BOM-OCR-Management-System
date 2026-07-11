@extends('layouts.app')

@section('title', 'Material Stock — Requisitions')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-list-check"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Requisitions</h3>
                    <p class="app-hero-copy mb-0">Request → Approve → fulfilled by a Bulk Issue.</p>
                </div>
            </div>
            <a href="{{ route('store.material.bulk-issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1"></i>Bulk Issue</a>
        </div>
    </div>

    @include('store._flash')

    <div class="row g-4">
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">New Requisition</h5>
                    @if($bookingPos->isEmpty())
                        <p class="text-muted small">No Booking POs available.</p>
                    @else
                    <form method="POST" action="{{ route('store.material.requisitions.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Booking PO / Material <span class="text-danger">*</span></label>
                        <select name="booking_po_id" class="form-select mb-3" required>
                            <option value="">Select PO…</option>
                            @foreach($bookingPos as $po)
                                <option value="{{ $po->id }}" {{ old('booking_po_id')==$po->id?'selected':'' }}>
                                    {{ collect([$po->po_no, $po->style_name, $po->item_name ?: $po->description, $po->color, $po->size_width])->filter()->implode(' · ') }}
                                </option>
                            @endforeach
                        </select>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label fw-semibold">Req No</label><input name="requisition_no" value="{{ old('requisition_no') }}" class="form-control"></div>
                            <div class="col-6"><label class="form-label fw-semibold">Qty <span class="text-danger">*</span></label><input type="number" step="0.0001" min="0" name="qty" value="{{ old('qty') }}" class="form-control" required></div>
                        </div>
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea name="remarks" rows="2" class="form-control mb-3" maxlength="1000">{{ old('remarks') }}</textarea>
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Create Requisition</button>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:14px;">
                <div class="card-body p-4">
                    <h5 class="mb-3">Requisitions <span class="badge bg-primary-subtle text-primary ms-1">{{ $requisitions->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>#</th><th>PO / Material</th><th class="text-end">Qty</th><th>Status</th><th>Requested</th><th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($requisitions as $req)
                                    @php $variant = ['pending'=>'warning','approved'=>'info','issued'=>'success'][$req->status] ?? 'secondary'; @endphp
                                    <tr>
                                        <td class="fw-semibold">#{{ $req->id }}</td>
                                        <td>
                                            <div class="fw-semibold">{{ $req->po_no }} · {{ $req->material_description }}</div>
                                            <div class="small text-muted">{{ $req->style_name }}@if($req->material_color) · {{ $req->material_color }}@endif</div>
                                        </td>
                                        <td class="text-end fw-bold">{{ rtrim(rtrim(number_format((float)$req->qty, 4), '0'), '.') }}</td>
                                        <td><span class="badge bg-{{ $variant }}-subtle text-{{ $variant }}">{{ ucfirst($req->status) }}</span></td>
                                        <td class="small text-muted">{{ optional($req->requestedBy)->name ?? '—' }}<br>{{ optional($req->requested_at)->format('d-M-Y') }}</td>
                                        <td class="text-end text-nowrap">
                                            @if($req->status === 'pending')
                                                <form method="POST" action="{{ route('store.material.requisitions.approve', $req) }}" class="d-inline">
                                                    @csrf @method('PATCH')
                                                    <button class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="bi bi-check2 me-1"></i>Approve</button>
                                                </form>
                                            @endif
                                            @if($req->status !== 'issued')
                                                <form method="POST" action="{{ route('store.material.requisitions.destroy', $req) }}" class="d-inline" onsubmit="return confirm('Remove this requisition?');">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center text-muted py-5">No requisitions yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $requisitions->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
