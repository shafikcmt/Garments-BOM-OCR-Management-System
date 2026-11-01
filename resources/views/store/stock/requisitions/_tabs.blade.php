{{--
    The tabs over the requisition screens — one list, three questions:
    "what has been raised", "what is waiting on the Store", and "what is
    waiting on me as a department head".

    The Requested tab shows only to whoever works the Store's side
    (PurchaseRequisition::worksStoreDesk()); the Department Review tab only
    to someone who heads a department — $departmentReviewCount is null for
    everyone else, which is what hides it.

    Expects: $active ('index' | 'requested' | 'requirement' | 'department'), $awaitingCount,
    $departmentReviewCount.
--}}
@php
    $user = auth()->user();
    $seesQueue = \App\Models\PurchaseRequisition::worksStoreDesk($user);
@endphp
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ $active === 'index' && request('level') !== 'final' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.index') }}">{{ \App\Models\PurchaseRequisition::listLabelFor($user) }}</a>
    </li>
    <li class="nav-item">
        @php($approvalsCount = app(\App\Services\RequisitionWorkflow::class)->waitingForOnce($user)->count())
        <a class="nav-link {{ $active === 'approvals' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.my-approvals') }}">
            My Approvals
            @if($approvalsCount > 0)
                <span class="badge bg-danger-subtle text-danger ms-1">{{ number_format($approvalsCount) }}</span>
            @endif
        </a>
    </li>
    @if($seesQueue)
        {{-- Final requisitions are a view of the list, so they have a button in
             the page header rather than a tab of their own. The tab shows only
             while that view is open, so it is clear where you are. --}}
        @if($active === 'index' && request('level') === 'final')
            <li class="nav-item">
                <a class="nav-link active" href="{{ route('store.stock.requisitions.index', ['level' => 'final']) }}">Final Requisitions</a>
            </li>
        @endif
        <li class="nav-item">
            <a class="nav-link {{ $active === 'requested' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.requested') }}">
                Requested
                @if(($awaitingCount ?? 0) > 0)
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ number_format($awaitingCount) }}</span>
                @endif
            </a>
        </li>
    @endif
    {{-- store.requirement_issue.view (and Admin) only. The controller refuses everyone else
         too; hiding the tab is presentation, not the guard. --}}
    @if(\App\Services\RequirementVsIssueService::canView($user))
        <li class="nav-item">
            <a class="nav-link {{ $active === 'requirement' ? 'active' : '' }}" href="{{ route('store.stock.requisitions.requirement-vs-issue') }}">
                Requirement vs Issue
            </a>
        </li>
    @endif
    {{-- Shown only when something is waiting for this department head. --}}
    @if(($departmentReviewCount ?? null) !== null && ($departmentReviewCount > 0 || $active === 'department'))
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
