{{-- "BOM Approvals" in the sidebar, with how many are waiting for ME. --}}
@php($bomWaiting = app(\App\Services\BomApprovals::class)->waitingFor(auth()->user())->count())
<li class="sidebar-item">
    <a href="{{ route('bom_approvals.index') }}" class="sidebar-nav-link {{ request()->routeIs('bom_approvals.*') ? 'is-active' : '' }}">
        <span class="sidebar-link-main">
            <span class="sidebar-icon"><i class="bi bi-patch-check" aria-hidden="true"></i></span>
            <span class="sidebar-link-text">BOM Approvals</span>
        </span>
        @if($bomWaiting > 0)<span class="badge rounded-pill bg-danger">{{ $bomWaiting > 99 ? '99+' : $bomWaiting }}</span>@endif
    </a>
</li>
