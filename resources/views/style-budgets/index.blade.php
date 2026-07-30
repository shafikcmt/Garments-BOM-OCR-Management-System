@extends('layouts.app')

@section('title', 'Style Budgets')

@section('styles')
<style>
    /* Same compact-row + hover treatment the Store stock tables use, so a list
       screen reads the same wherever it appears. */
    .sb-table thead th {
        background: var(--gx-bg, #F8FAFC);
        font-size: .6875rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 600;
        color: var(--gx-text-muted);
        border-bottom: 1px solid var(--gx-surface-border);
        white-space: nowrap;
    }
    .sb-table tbody tr { transition: background-color .15s ease; }
    .sb-table tbody tr:hover { background: var(--gx-bg, #F8FAFC); }
    .sb-table td { padding-top: .6rem; padding-bottom: .6rem; }
    .sb-table td.sb-amount { font-variant-numeric: tabular-nums; }

    .sb-empty { padding: 2.25rem 1rem; text-align: center; }
    .sb-empty-icon {
        width: 44px; height: 44px; border-radius: 12px; margin: 0 auto .6rem;
        display: inline-flex; align-items: center; justify-content: center;
        background: #fff; border: 1px solid var(--gx-surface-border);
        color: #94A3B8; font-size: 1.15rem;
    }
    .sb-empty-title { font-size: .8125rem; font-weight: 600; color: #334155; }
    .sb-empty-text { font-size: .75rem; color: #94A3B8; margin-top: .15rem; }

    @media (prefers-reduced-motion: reduce) {
        .sb-table tbody tr { transition: none; }
    }
</style>
@endsection

@section('content')
<div class="container-fluid">
    {{-- Both roles that hold manage-style-budgets land here, so the trail goes
         to the role dispatcher rather than to one department's dashboard. --}}
    <x-breadcrumb :items="[
        ['label' => 'Planning', 'url' => route('dashboard')],
        ['label' => 'Style Budgets'],
    ]" />

    <x-page-header data-aos="fade-down" icon="bar-chart" eyebrow="Planning"
                   title="Style Budgets"
                   copy="A style with a budget is checked every time a payment request is raised against it." />

    <x-flash />

    <div class="row g-4">
        {{-- Add / update budget --}}
        <div class="col-12 col-xl-4">
            <x-card>
                <x-slot:title>
                    Set Budget
                    <span class="d-block fw-normal text-muted small mt-1">Leave Buyer / Season blank for a budget that applies to the style everywhere.</span>
                </x-slot:title>

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

                        {{-- No leading <i>: the project-wide rule in components.css
                             collapses any .btn carrying one into an icon-only
                             square, and this is the form's committing action. --}}
                        <button type="submit" class="btn btn-primary w-100">Save Budget</button>
                        <div class="form-text">Saving the same Style + Buyer + Season updates the existing budget.</div>
                    </form>
            </x-card>
        </div>

        {{-- Budget list --}}
        <div class="col-12 col-xl-8">
            <x-card>
                <x-slot:title>
                    Configured Budgets
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ $budgets->total() }}</span>
                </x-slot:title>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 sb-table">
                            <thead>
                                {{-- Head styling now comes from .sb-table, matching the
                                     Store tables instead of ad-hoc utility classes. --}}
                                <tr>
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
                                        <td class="text-end fw-bold sb-amount">{{ number_format((float) $budget->budget_amount, 2) }}</td>
                                        <td class="small text-muted">{{ optional($budget->setBy)->name ?? '—' }}</td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                                    data-bs-toggle="modal" data-bs-target="#editBudget{{ $budget->id }}">
                                                Edit
                                            </button>
                                            <form method="POST" action="{{ route('style-budgets.destroy', $budget) }}" class="d-inline"
                                                  onsubmit="return confirm('Remove this style budget?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3"
                                                        aria-label="Delete the budget for {{ $budget->style_name }}" title="Delete">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="p-0">
                                            <div class="sb-empty">
                                                <div class="sb-empty-icon"><i class="bi bi-bar-chart" aria-hidden="true"></i></div>
                                                <div class="sb-empty-title">No style budgets yet</div>
                                                <p class="sb-empty-text mb-0">Set one on the left and it will be checked against every payment request raised for that style.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($budgets->hasPages())
                        <div class="mt-3">{{ $budgets->links() }}</div>
                    @endif
            </x-card>
        </div>
    </div>

    {{-- Edit dialogs live outside the table. They used to sit between the <tr>
         elements inside <tbody>, which is invalid markup — the browser hoists
         them out of the table anyway, so their position here is what was
         actually rendering. Fields, names and actions are unchanged. --}}
    @foreach($budgets as $budget)
        <div class="modal fade" id="editBudget{{ $budget->id }}" tabindex="-1"
             aria-labelledby="editBudgetLabel{{ $budget->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content gx-card">
                    <form method="POST" action="{{ route('style-budgets.update', $budget) }}">
                        @csrf
                        @method('PATCH')
                        <div class="modal-header">
                            <h5 class="modal-title" id="editBudgetLabel{{ $budget->id }}">Edit Style Budget</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <label class="form-label fw-semibold" for="editStyle{{ $budget->id }}">Style <span class="text-danger">*</span></label>
                            <input name="style_name" id="editStyle{{ $budget->id }}" value="{{ $budget->style_name }}" class="form-control mb-3" required>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold" for="editBuyer{{ $budget->id }}">Buyer <span class="text-muted small fw-normal">(optional)</span></label>
                                    <input name="buyer_name" id="editBuyer{{ $budget->id }}" value="{{ $budget->buyer_name }}" class="form-control" placeholder="All buyers">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold" for="editSeason{{ $budget->id }}">Season <span class="text-muted small fw-normal">(optional)</span></label>
                                    <input name="season_name" id="editSeason{{ $budget->id }}" value="{{ $budget->season_name }}" class="form-control" placeholder="All seasons">
                                </div>
                            </div>
                            <label class="form-label fw-semibold" for="editAmount{{ $budget->id }}">Budget Amount (USD) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" name="budget_amount" id="editAmount{{ $budget->id }}" value="{{ $budget->budget_amount }}" class="form-control mb-3" required>
                            <label class="form-label fw-semibold" for="editNote{{ $budget->id }}">Note <span class="text-muted small fw-normal">(optional)</span></label>
                            <textarea name="note" id="editNote{{ $budget->id }}" rows="2" class="form-control" maxlength="1000">{{ $budget->note }}</textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
