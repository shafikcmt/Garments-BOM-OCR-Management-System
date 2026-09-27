{{-- The two views of the departmental chain: what is waiting on me, and
     everything I have touched. An admin has no step of their own, so they
     see only the second. The module's own segmented switch. --}}
@php
    $showQueue = auth()->user()?->can('pra.departmental.act') && ! auth()->user()?->hasRole('admin');
@endphp
<div class="po-switch mb-3">
    @if($showQueue)
        <a href="{{ route('pra_departmental.index') }}" class="po-switch-btn {{ request()->routeIs('pra_departmental.index') ? 'is-active' : '' }}">
            <i class="bi bi-inbox" aria-hidden="true"></i>My Step
        </a>
    @endif
    <a href="{{ route('pra_departmental.all') }}" class="po-switch-btn {{ request()->routeIs('pra_departmental.all') ? 'is-active' : '' }}">
        <i class="bi bi-collection" aria-hidden="true"></i>All PRAs
    </a>
</div>
