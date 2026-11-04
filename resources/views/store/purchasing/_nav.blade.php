{{-- The three screens of General Stock purchasing, in the order the work flows. --}}
@php($u = auth()->user())
<ul class="nav nav-pills gap-2 mb-3">
    @if($u->can('store.purchase_cs.view'))
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('store.purchasing.queue') ? 'active' : '' }}" href="{{ route('store.purchasing.queue') }}">
                <i class="bi bi-cart-plus me-1" aria-hidden="true"></i>To Purchase
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('store.purchasing.cs.*') ? 'active' : '' }}" href="{{ route('store.purchasing.cs.index') }}">
                <i class="bi bi-columns-gap me-1" aria-hidden="true"></i>Comparative Statements
            </a>
        </li>
    @endif
    @if($u->can('store.purchase_po.view'))
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('store.purchasing.po.*') ? 'active' : '' }}" href="{{ route('store.purchasing.po.index') }}">
                <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Internal POs
            </a>
        </li>
    @endif
</ul>
