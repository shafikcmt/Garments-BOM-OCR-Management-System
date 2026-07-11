@extends('layouts.app')

@section('title', 'Material Stock — Closing Stock')

@php
    $fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 4), '0'), '.');
@endphp

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-clipboard-data"></i></span>
                <div>
                    <div class="app-hero-eyebrow">Buyer / Style Stock</div>
                    <h3 class="app-hero-title mb-0">Closing Stock Report</h3>
                    <p class="app-hero-copy mb-0">Running / Liability / Dead — Liability &amp; Dead can be reused (transfer to bulk).</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('store.material.receivings.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down me-1"></i>Receiving</a>
                <a href="{{ route('store.material.bulk-issues.index') }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up me-1"></i>Bulk Issue</a>
            </div>
        </div>
    </div>

    @include('store._flash')

    {{-- Summary cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="app-stat-card p-3 h-100"><div class="app-stat-label">Running Closing</div><div class="fw-bold fs-4 text-success">{{ $fmt($totals['running']) }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="app-stat-card p-3 h-100"><div class="app-stat-label">Liability Closing</div><div class="fw-bold fs-4 text-warning">{{ $fmt($totals['liability']) }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="app-stat-card p-3 h-100"><div class="app-stat-label">Dead Closing</div><div class="fw-bold fs-4 text-danger">{{ $fmt($totals['dead']) }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="app-stat-card p-3 h-100"><div class="app-stat-label">Total Value</div><div class="fw-bold fs-4">{{ number_format($totals['value'], 2) }}</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3" style="border-radius:14px;">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold small mb-1">Buyer</label>
                    <select name="buyer" class="form-select"><option value="">All</option>@foreach($buyers as $b)<option value="{{ $b }}" {{ request('buyer')==$b?'selected':'' }}>{{ $b }}</option>@endforeach</select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold small mb-1">Style</label>
                    <select name="style" class="form-select"><option value="">All</option>@foreach($styles as $s)<option value="{{ $s }}" {{ request('style')==$s?'selected':'' }}>{{ $s }}</option>@endforeach</select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold small mb-1">Search (material / SAP / PO / color)</label>
                    <input name="q" value="{{ request('q') }}" class="form-control" placeholder="Type to search…">
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="{{ route('store.material.ledger') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:14px;">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="text-muted small text-uppercase">
                            <th class="ps-3">PO / Material</th>
                            <th class="text-end">Total Recv</th>
                            <th class="text-end">Bulk</th>
                            <th class="text-end">Sample</th>
                            <th class="text-end text-success">Running</th>
                            <th class="text-end text-warning">Liability</th>
                            <th class="text-end text-danger">Dead</th>
                            <th class="text-end fw-bold">Total Closing</th>
                            <th class="text-end pe-3">Reuse</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ledgers as $l)
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold">{{ $l->po_no }} · {{ $l->material_description }}</div>
                                    <div class="small text-muted">{{ collect([$l->buyer_name.' / '.$l->style_name, $l->material_color, $l->size, $l->sap_code ? 'SAP '.$l->sap_code : null])->filter()->implode(' · ') }}</div>
                                    <div class="small text-muted">Booking: {{ $fmt($l->booking_receive_qty) }} · Internal PO: {{ $fmt($l->internal_po_receive_qty) }}</div>
                                </td>
                                <td class="text-end">{{ $fmt($l->total_receive_qty) }}</td>
                                <td class="text-end">{{ $fmt($l->bulk_issue_qty) }}</td>
                                <td class="text-end">{{ $fmt($l->sample_qty) }}</td>
                                <td class="text-end fw-bold text-success">{{ $fmt($l->running_closing_qty) }}</td>
                                <td class="text-end text-warning">{{ $fmt($l->liability_closing_qty) }}</td>
                                <td class="text-end text-danger">{{ $fmt($l->dead_closing_qty) }}</td>
                                <td class="text-end fw-bold">{{ $fmt($l->total_closing_qty) }}</td>
                                <td class="text-end pe-3 text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-2" data-bs-toggle="modal" data-bs-target="#liab{{ $l->id }}" @disabled((float)$l->liability_closing_qty <= 0)>Liab.</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2" data-bs-toggle="modal" data-bs-target="#dead{{ $l->id }}" @disabled((float)$l->dead_closing_qty <= 0)>Dead</button>
                                </td>
                            </tr>

                            {{-- Liability reuse modal --}}
                            <div class="modal fade" id="liab{{ $l->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content" style="border-radius:14px;">
                                        <form method="POST" action="{{ route('store.material.ledger.liability', $l) }}">
                                            @csrf
                                            <div class="modal-header"><h5 class="modal-title text-warning">Liability Movement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <div class="modal-body">
                                                <p class="small text-muted">{{ $l->po_no }} · {{ $l->material_description }} — available Liability: <strong>{{ $fmt($l->liability_closing_qty) }}</strong></p>
                                                <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                                <input type="date" name="movement_date" value="{{ now()->toDateString() }}" class="form-control mb-3" required>
                                                <div class="row g-2">
                                                    <div class="col-6"><label class="form-label fw-semibold">Transfer to Bulk (reuse)</label><input type="number" step="0.0001" min="0" name="transfer_to_bulk_qty" class="form-control"></div>
                                                    <div class="col-6"><label class="form-label fw-semibold">Sample Issue</label><input type="number" step="0.0001" min="0" name="sample_issue_qty" class="form-control"></div>
                                                </div>
                                                <label class="form-label fw-semibold mt-3">Remarks</label>
                                                <textarea name="remarks" rows="2" class="form-control" maxlength="1000"></textarea>
                                            </div>
                                            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning">Save Movement</button></div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            {{-- Dead reuse modal --}}
                            <div class="modal fade" id="dead{{ $l->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content" style="border-radius:14px;">
                                        <form method="POST" action="{{ route('store.material.ledger.dead', $l) }}">
                                            @csrf
                                            <div class="modal-header"><h5 class="modal-title text-danger">Dead Movement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <div class="modal-body">
                                                <p class="small text-muted">{{ $l->po_no }} · {{ $l->material_description }} — available Dead: <strong>{{ $fmt($l->dead_closing_qty) }}</strong></p>
                                                <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                                <input type="date" name="movement_date" value="{{ now()->toDateString() }}" class="form-control mb-3" required>
                                                <div class="row g-2">
                                                    <div class="col-6"><label class="form-label fw-semibold">Transfer to Bulk (reuse)</label><input type="number" step="0.0001" min="0" name="transfer_to_bulk_qty" class="form-control"></div>
                                                    <div class="col-6"><label class="form-label fw-semibold">Sample Issue</label><input type="number" step="0.0001" min="0" name="sample_issue_qty" class="form-control"></div>
                                                </div>
                                                <label class="form-label fw-semibold mt-3">Remarks</label>
                                                <textarea name="remarks" rows="2" class="form-control" maxlength="1000"></textarea>
                                            </div>
                                            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Save Movement</button></div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-5">No closing stock yet. Record a receiving to start the ledger.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="mt-3">{{ $ledgers->links() }}</div>

    <p class="small text-muted mt-2">Running = Total Receive − Bulk − Sample − Declared Liability − Calculated Dead + (Liability→Bulk) + (Dead→Bulk). Liability &amp; Dead transferred back to Bulk return to Running (reused in production).</p>
</div>
@endsection
