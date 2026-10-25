{{--
    "My Approvals" in the sidebar, with the count of requisitions waiting for
    this user. The count is the length of the very list the page shows
    (RequisitionWorkflow::waitingForOnce, worked out once per request), so the
    badge can never disagree with the page.

    Always a top-level item directly under Dashboard, so the badge is seen
    without opening any group. $gate (optional) is the permission rule of the
    block it sits in ($seeRequisition); Admin and Management pass none.
--}}
@if($gate ?? true)
@php
    $approvalsCount = app(\App\Services\RequisitionWorkflow::class)->waitingForOnce(auth()->user())->count();
    $approvalsActive = request()->routeIs('store.stock.requisitions.my-approvals');
@endphp
    <li class="sidebar-item">
        <a href="{{ route('store.stock.requisitions.my-approvals') }}" class="sidebar-nav-link {{ $approvalsActive ? 'is-active' : '' }}">
            <span class="sidebar-link-main">
                <span class="sidebar-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                <span class="sidebar-link-text">My Approvals</span>
            </span>
            @if($approvalsCount > 0)<span class="badge rounded-pill bg-danger">{{ $approvalsCount > 99 ? '99+' : $approvalsCount }}</span>@endif
        </a>
    </li>
@endif
