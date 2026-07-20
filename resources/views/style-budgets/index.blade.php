@extends('layouts.app')

@section('title', 'Style Budgets')

@section('content')
<div class="container-fluid">
    <div class="app-hero-card p-4 mb-4">
        <div class="d-flex align-items-center gap-3">
            <span class="app-stat-icon" style="width:46px;height:46px;border-radius:15px;font-size:20px;"><i class="bi bi-bar-chart"></i></span>
            <div>
                <div class="app-hero-eyebrow">Merchandising / Admin</div>
                <h3 class="app-hero-title mb-0">Style Budgets</h3>
            </div>
        </div>
    </div>

    @foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $variant)
        @if(session($key))
            <div class="alert alert-{{ $variant }} border-0 shadow-sm rounded-3">{{ session($key) }}</div>
        @endif
    @endforeach
    @if($errors->any())
        <div class="alert alert-danger border-0 shadow-sm rounded-3">
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-4">
        {{-- Add / update budget --}}
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-1">Set Budget</h5>
                    <p class="text-muted small mb-3">A style with a budget is checked when a PRA is created. Leave Buyer / Season blank for a budget that applies to the style everywhere.</p>

                    <form method="POST" action="{{ route('style-budgets.store') }}">
                        @csrf
                        <label class="form-label fw-semibold">Style <span class="text-danger">*</span></label>
                        <input list="styleOptions" name="style_name" value="{{ old('style_name') }}" class="form-control mb-3" required placeholder="Style name">
                        <datalist id="styleOptions">
                            @foreach($styleOptions as $option)<option value="{{ $option }}"></option>@endforeach
                        </datalist>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Buyer <span class="text-muted small">(optional)</span></label>
                                <input list="buyerOptions" name="buyer_name" value="{{ old('buyer_name') }}" class="form-control" placeholder="All buyers">
                                <datalist id="buyerOptions">
                                    @foreach($buyerOptions as $option)<option value="{{ $option }}"></option>@endforeach
                                </datalist>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Season <span class="text-muted small">(optional)</span></label>
                                <input list="seasonOptions" name="season_name" value="{{ old('season_name') }}" class="form-control" placeholder="All seasons">
                                <datalist id="seasonOptions">
                                    @foreach($seasonOptions as $option)<option value="{{ $option }}"></option>@endforeach
                                </datalist>
                            </div>
                        </div>

                        <label class="form-label fw-semibold">Budget Amount (USD) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0" name="budget_amount" value="{{ old('budget_amount') }}" class="form-control mb-3" required placeholder="0.00">

                        <label class="form-label fw-semibold">Note <span class="text-muted small">(optional)</span></label>
                        <textarea name="note" rows="2" class="form-control mb-3" maxlength="1000">{{ old('note') }}</textarea>

                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i> Save Budget</button>
                        <div class="form-text">Saving the same Style + Buyer + Season updates the existing budget.</div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Budget list --}}
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm" style="border-radius:var(--gx-radius);">
                <div class="card-body p-4">
                    <h5 class="mb-3">Configured Budgets <span class="badge bg-primary-subtle text-primary ms-1">{{ $budgets->total() }}</span></h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr class="text-muted small text-uppercase">
                                    <th>Style</th>
                                    <th>Scope</th>
                                    <th class="text-end">Budget (USD)</th>
                                    <th>Set By</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($budgets as $budget)
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-slate-900">{{ $budget->style_name }}</div>
                                            @if($budget->note)<div class="small text-muted">{{ $budget->note }}</div>@endif
                                        </td>
                                        <td class="small">
                                            <div>Buyer: {{ $budget->buyer_name ?: 'All' }}</div>
                                            <div class="text-muted">Season: {{ $budget->season_name ?: 'All' }}</div>
                                        </td>
                                        <td class="text-end fw-bold">{{ number_format((float) $budget->budget_amount, 2) }}</td>
                                        <td class="small text-muted">{{ optional($budget->setBy)->name ?? '—' }}</td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                                    data-bs-toggle="modal" data-bs-target="#editBudget{{ $budget->id }}">
                                                <i class="bi bi-pencil me-1"></i> Edit
                                            </button>
                                            <form method="POST" action="{{ route('style-budgets.destroy', $budget) }}" class="d-inline"
                                                  onsubmit="return confirm('Remove this style budget?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>

                                    {{-- Edit modal --}}
                                    <div class="modal fade" id="editBudget{{ $budget->id }}" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content" style="border-radius:var(--gx-radius);">
                                                <form method="POST" action="{{ route('style-budgets.update', $budget) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <div class="modal-header">
                                                        <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Style Budget</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <label class="form-label fw-semibold">Style <span class="text-danger">*</span></label>
                                                        <input name="style_name" value="{{ $budget->style_name }}" class="form-control mb-3" required>
                                                        <div class="row g-2 mb-3">
                                                            <div class="col-6">
                                                                <label class="form-label fw-semibold">Buyer</label>
                                                                <input name="buyer_name" value="{{ $budget->buyer_name }}" class="form-control" placeholder="All buyers">
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="form-label fw-semibold">Season</label>
                                                                <input name="season_name" value="{{ $budget->season_name }}" class="form-control" placeholder="All seasons">
                                                            </div>
                                                        </div>
                                                        <label class="form-label fw-semibold">Budget Amount (USD) <span class="text-danger">*</span></label>
                                                        <input type="number" step="0.01" min="0" name="budget_amount" value="{{ $budget->budget_amount }}" class="form-control mb-3" required>
                                                        <label class="form-label fw-semibold">Note</label>
                                                        <textarea name="note" rows="2" class="form-control" maxlength="1000">{{ $budget->note }}</textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Update</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-5">No style budgets configured yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $budgets->links() }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
