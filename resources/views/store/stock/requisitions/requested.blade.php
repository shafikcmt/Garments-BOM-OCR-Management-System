@extends('layouts.app')

@section('title', 'General Stock — Requested Requisitions')

@php
    $hasFilters = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

    // Same badge set as the main list, so one status never reads two ways.
    $badges = [
        'draft' => ['bg-secondary-subtle text-secondary-emphasis', 'bi-pencil'],
        'submitted' => ['bg-primary-subtle text-primary', 'bi-send'],
        'store_reviewed' => ['bg-info-subtle text-info-emphasis', 'bi-clipboard-check'],
        'approved' => ['bg-success-subtle text-success', 'bi-check2-circle'],
        'purchase_action_taken' => ['bg-dark-subtle text-dark', 'bi-cart-check'],
        'cancelled' => ['bg-danger-subtle text-danger', 'bi-x-circle'],
    ];
@endphp

@section('content')
<div class="container-fluid gx-stock-scope">
    <x-breadcrumb :items="[
        ['label' => 'Store', 'url' => \App\Models\PurchaseRequisition::seesEveryRequisition(auth()->user()) ? route('store.dashboard') : null],
        ['label' => 'General Stock'],
        ['label' => 'Purchase Requisition', 'url' => route('store.stock.requisitions.index')],
        ['label' => 'Requested'],
    ]" />

    @include('store.stock._stock-ui')

    <x-page-header icon="inbox" eyebrow="General Stock" title="Requested Requisitions"
                   copy="Requests sent to Store, oldest first. Receive them, then merge each month's into the final requisition.">
        <x-slot:actions>
            <a href="{{ route('store.stock.requisitions.index') }}" class="btn btn-outline-secondary">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ \App\Models\PurchaseRequisition::listLabelFor(auth()->user()) }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('store._flash')

    {{-- Two tabs over one list. The queue is a separate screen rather than a
         status filter because it answers a different question: not "what has
         been raised" but "what is waiting on me". --}}
    @include('store.stock.requisitions._tabs', ['active' => 'requested'])

    <div class="card gx-stock-card">
        <div class="gx-stock-card-body">
            <div class="gx-stock-card-head">
                <h5 class="mb-0">
                    From the departments
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ $requisitions->total() }}</span>
                </h5>
                @if($readyCount > 0 || ($filters['ready'] ?? false))
                    <a href="{{ ($filters['ready'] ?? false) ? route('store.stock.requisitions.requested') : route('store.stock.requisitions.requested', ['ready' => 1]) }}"
                       class="btn btn-sm {{ ($filters['ready'] ?? false) ? 'btn-info' : 'btn-outline-info' }}">
                        <i class="bi bi-intersect me-1" aria-hidden="true"></i>Ready to Merge
                        <span class="badge bg-white text-info-emphasis ms-1">{{ number_format($readyCount) }}</span>
                    </a>
                @endif
            </div>

            <form method="GET" class="row g-3 gx-stock-filter mb-4">
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="month">Month</label>
                    <select name="month" id="month" class="form-select">
                        <option value="">All months</option>
                        @foreach($monthOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['month'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="statusFilter">Status</label>
                    <select name="status" id="statusFilter" class="form-select">
                        <option value="">All</option>
                        @foreach($statusLabels as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label" for="sectionFilter">Section</label>
                    <select name="section" id="sectionFilter" class="form-select js-searchable">
                        <option value="">All</option>
                        @foreach($sections as $s)
                            <option value="{{ $s->id }}" @selected(($filters['section'] ?? null) == $s->id)>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="search">Search</label>
                    <input type="text" name="search" id="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Requisition no, requester, SKU or item">
                </div>
                <div class="col-12 col-xl-2 gx-stock-filter-actions">
                    @if($filters['ready'] ?? false)
                        <input type="hidden" name="ready" value="1">
                    @endif
                    <button class="btn btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button>
                    @if($hasFilters)
                        <a href="{{ route('store.stock.requisitions.requested') }}" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Clear</a>
                    @endif
                </div>
            </form>

            {{-- Step 4: tick received sheets of ONE month and merge them into
                 the final requisition (a draft the Store checks, then
                 submits for Store Head → Finance → Operation Head).
                 The checkboxes sit in the table and post this form through
                 their form="" attribute — the rows already hold forms of
                 their own, and forms cannot nest. --}}
            @php($mergeable = $canMerge ? $requisitions->filter(fn ($r) => $r->isReadyToMerge()) : collect())
            @if($mergeable->isNotEmpty())
                <form id="mergeForm" method="GET" action="{{ route('store.stock.requisitions.merge-review') }}"
                      class="d-flex flex-wrap align-items-center gap-2 mb-3 px-3 py-2 border rounded bg-info-subtle">
                    <i class="bi bi-intersect text-info-emphasis" aria-hidden="true"></i>
                    <span class="small"><strong>Merge:</strong> tick the received requisitions of one month, then review them before the final requisition is made.</span>
                    <span class="small text-muted ms-auto" data-merge-count>0 selected</span>
                    <button type="submit" class="btn btn-sm btn-info" data-merge-submit disabled>
                        <i class="bi bi-intersect me-1" aria-hidden="true"></i>Merge into Final Requisition
                    </button>
                </form>
            @endif
            @error('ids')
                <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>{{ $message }}</div>
            @enderror

            <div class="table-responsive">
                <table class="table align-middle gx-stock-table">
                    <thead>
                        <tr>
                            @if($mergeable->isNotEmpty())
                                <th style="width:40px;"><input type="checkbox" class="form-check-input" data-merge-all aria-label="Select all requisitions ready to merge"></th>
                            @endif
                            <th style="min-width:240px;">Requisition No</th>
                            <th style="min-width:110px;">Date</th>
                            <th style="min-width:140px;">Section</th>
                            <th style="min-width:150px;">Raised By</th>
                            <th class="text-end" style="min-width:80px;">Items</th>
                            <th class="text-end" style="min-width:130px;">Total Amount</th>
                            <th style="min-width:150px;">Status</th>
                            <th class="text-end gx-stock-actions" style="min-width:280px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requisitions as $r)
                            <tr>
                                @if($mergeable->isNotEmpty())
                                    <td>
                                        @if($r->isReadyToMerge())
                                            <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $r->id }}" form="mergeForm"
                                                   data-merge-row data-month="{{ optional($r->requisition_date)->format('Y-m') }}"
                                                   aria-label="Select {{ $r->requisition_no }} to merge">
                                        @endif
                                    </td>
                                @endif
                                <td>
                                    <div class="fw-bold text-slate-900">{{ $r->requisition_no }} @if($r->isIndividual())<span class="badge bg-primary-subtle text-primary ms-1">Individual</span>@endif</div>
                                    @if($r->store_ack_at)
                                        <div class="gx-stock-spec">received {{ $r->store_ack_at->local()->format('d-M-Y') }}</div>
                                    @endif
                                    @if($r->mergedInto)
                                        <div class="gx-stock-spec">merged into
                                            <a href="{{ route('store.stock.requisitions.show', $r->mergedInto) }}">{{ $r->mergedInto->requisition_no }}</a>
                                        </div>
                                    @endif
                                </td>
                                <td class="small">{{ optional($r->requisition_date)->format('d-M-Y') ?? '—' }}</td>
                                <td class="small">{{ optional($r->indentSection)->name ?: 'All' }}</td>
                                <td class="small">
                                    {{ optional($r->createdBy)->name ?: '—' }}
                                    @if($r->department_role)
                                        <div class="gx-stock-spec">{{ $r->departmentLabel() }}@if($r->department_checked_at) · head checked {{ $r->department_checked_at->local()->format('d-M-Y') }}@endif</div>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">{{ $r->items_count }}</td>
                                <td class="text-end fw-semibold">@include('store.stock.requisitions._amount', ['amount' => $r->items_sum_amount])</td>
                                <td>
                                    @php([$class, $icon] = $badges[$r->status] ?? ['bg-light text-dark', 'bi-dot'])
                                    <span class="badge {{ $class }}"><i class="bi {{ $icon }} me-1" aria-hidden="true"></i>{{ $r->progressLabel() }}</span>
                                </td>
                                <td class="text-end gx-stock-actions">
                                    <a href="{{ route('store.stock.requisitions.show', $r) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1" aria-hidden="true"></i>View
                                    </a>
                                    {{-- Print and download are the existing
                                         single-requisition routes, reachable
                                         from the queue as well as the list. --}}
                                    <a href="{{ route('store.stock.requisitions.pdf', $r) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                                    </a>
                                    {{-- Correct the sheet before it goes any
                                         further. Opens the ordinary requisition
                                         edit form — there is no second edit UI —
                                         and is offered only while the Store's
                                         correction window is open and only to
                                         someone holding the correction
                                         permission, which is the same pair the
                                         controller checks on the way in. --}}
                                    @if($canEdit && $r->isOpenToStoreCorrection())
                                        <a href="{{ route('store.stock.requisitions.edit', $r) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit
                                        </a>
                                    @endif
                                    @include('store.stock.requisitions._review-actions', ['requisition' => $r, 'small' => true])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $mergeable->isNotEmpty() ? 9 : 8 }}" class="gx-stock-empty">
                                <span class="gx-stock-empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                                <div class="gx-stock-empty-title">{{ $hasFilters ? 'Nothing matches this filter' : 'No department requests yet' }}</div>
                                <div class="gx-stock-empty-hint">{{ $hasFilters ? 'Try a different month or status.' : 'Requisitions raised outside the Store appear here as soon as they are submitted.' }}</div>
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $requisitions->links() }}</div>
        </div>
    </div>

    @include('store.stock._searchable')
</div>

@if($mergeable->isNotEmpty())
<script>
    // Merge selection: one month at a time — ticking a sheet greys out the
    // other months' rows, so the server's one-month rule is never a surprise.
    (function () {
        const rows = Array.from(document.querySelectorAll('[data-merge-row]'));
        const all = document.querySelector('[data-merge-all]');
        const count = document.querySelector('[data-merge-count]');
        const submit = document.querySelector('[data-merge-submit]');

        function refresh() {
            const ticked = rows.filter(r => r.checked);
            const month = ticked.length ? ticked[0].dataset.month : null;
            rows.forEach(r => { r.disabled = month !== null && r.dataset.month !== month; });
            count.textContent = ticked.length + ' selected';
            submit.disabled = ticked.length === 0;
            if (all) all.checked = ticked.length > 0 && ticked.length === rows.filter(r => !r.disabled).length;
        }

        rows.forEach(r => r.addEventListener('change', refresh));
        all?.addEventListener('change', () => {
            const month = (rows.find(r => r.checked) || rows[0])?.dataset.month;
            rows.forEach(r => { if (r.dataset.month === month) r.checked = all.checked; });
            refresh();
        });
        refresh();
    })();
</script>
@endif
@endsection
