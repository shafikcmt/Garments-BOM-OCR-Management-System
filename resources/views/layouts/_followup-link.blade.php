{{-- "Follow-ups" in the sidebar, with how many of MY open follow-ups are due today or overdue. --}}
@php
    $followUpDue = \App\Models\FollowUpTask::open()->where('assigned_to', auth()->id())->whereDate('due_date', '<=', now()->toDateString())->count();
@endphp
<li class="sidebar-item">
    <a href="{{ route('follow_ups.index') }}" class="sidebar-nav-link {{ request()->routeIs('follow_ups.*') ? 'is-active' : '' }}">
        <span class="sidebar-link-main">
            <span class="sidebar-icon"><i class="bi bi-check2-square" aria-hidden="true"></i></span>
            <span class="sidebar-link-text">Follow-ups</span>
        </span>
        @if($followUpDue > 0)<span class="badge rounded-pill bg-danger">{{ $followUpDue > 99 ? '99+' : $followUpDue }}</span>@endif
    </a>
</li>
