{{--
    The tabs over the requisition screens — one list, three questions:
    "what has been raised", "what is waiting on the Store", and "what is
    waiting on me as a department head".

    The Requested tab shows only to whoever reads the whole company's
    requisitions (Store / Admin / Management); the Department Review tab only
    to someone who heads a department — $departmentReviewCount is null for
    everyone else, which is what hides it.

    Expects: $active ('index' | 'requested' | 'department'), $awaitingCount,
    $departmentReviewCount.
--}}
@php
    $user = auth()->user();
    $seesQueue = $user && \App\Models\PurchaseRequisition::seesEveryRequisition($user);
@endphp
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ $active === 'index' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.index') }}">{{ \App\Models\PurchaseRequisition::listLabelFor($user) }}</a>
    </li>
    @if($seesQueue)
        <li class="nav-item">
            <a class="nav-link {{ $active === 'requested' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.requested') }}">
                Requested
                @if(($awaitingCount ?? 0) > 0)
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ number_format($awaitingCount) }}</span>
                @endif
            </a>
        </li>
    @endif
    @if(($departmentReviewCount ?? null) !== null)
        <li class="nav-item">
            <a class="nav-link {{ $active === 'department' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.department-review') }}">
                Department Review
                @if($departmentReviewCount > 0)
                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ number_format($departmentReviewCount) }}</span>
                @endif
            </a>
        </li>
    @endif
</ul>
