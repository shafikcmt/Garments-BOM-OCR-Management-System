{{--
    Purchase Requisition, for a department that has its own sidebar block.

    Merchandising, Accounts, Commercial and Supply Chain each render their own
    block instead of the permission-driven Store block, so granting one of their
    users store.requisition.view used to let them through the route and give
    them no link to reach it by. This is that link, and it asks exactly the
    question the route guard asks — $seeRequisition, computed once at the top of
    the sidebar — so it is never offered to somebody who would be refused.

    One partial, included by each of those blocks, so the entry cannot drift
    into four slightly different spellings.
--}}
@if($seeRequisition)
    <li class="sidebar-item">
        <a href="{{ route('store.stock.requisitions.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.requisitions.*') && ! request()->routeIs('store.stock.requisitions.my-approvals') ? 'is-active' : '' }}">
            <span class="sidebar-link-main">
                <span class="sidebar-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                <span class="sidebar-link-text">Purchase Requisition</span>
            </span>
        </a>
    </li>
    @include('layouts._my-approvals-link', ['variant' => 'nav'])
@endif
