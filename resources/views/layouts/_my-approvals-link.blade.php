{{--
    "My Approvals" in the sidebar, with the count of requisitions waiting for
    this user. The count is the length of the very list the page shows
    (RequisitionWorkflow::waitingForOnce, worked out once per request), so the
    badge can never disagree with the page.

    Expects: $variant — 'nav' (a top-level item) or 'sub' (inside a group).
--}}
@php
    $approvalsCount = app(\App\Services\RequisitionWorkflow::class)->waitingForOnce(auth()->user())->count();
    $approvalsActive = request()->routeIs('store.stock.requisitions.my-approvals');
@endphp
@if(($variant ?? 'nav') === 'sub')
    <a href="{{ route('store.stock.requisitions.my-approvals') }}" class="sidebar-sub-link {{ $approvalsActive ? 'is-active' : '' }}">
        My Approvals
        @if($approvalsCount > 0)<span class="badge rounded-pill bg-danger ms-1">{{ $approvalsCount > 99 ? '99+' : $approvalsCount }}</span>@endif
    </a>
@else
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
