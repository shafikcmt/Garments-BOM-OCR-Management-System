{{--
    Two label fixes live here rather than in components.css because this file is
    the only one in scope for them.

    .app-icon-btn is a fixed 42x42 square, which fits an icon and nothing else.
    The labelled variant relaxes it into a pill. Scoped to the modifier class so
    the sidebar toggle, which is not part of this change, keeps its current look.
--}}
<style>
    .app-icon-btn.app-icon-btn-labelled {
        width: auto;
        min-width: 42px;
        padding: 0 12px;
        gap: 8px;
        font-size: 12.5px;
        font-weight: 650;
        white-space: nowrap;
    }
    .app-icon-btn-text { line-height: 1; }

    /* The account name and email stay on screen at every width. Where the
       header runs out of room they narrow and ellipsis rather than vanish —
       clamp() does that without a breakpoint that could hide them outright. */
    .app-account-text > span { max-width: clamp(78px, 17vw, 150px); }

    /* One of these two is always rendered, so the account is never unlabelled.
       Below 390px the full name is swapped for its first token to give the
       company name back the room it needs. */
    .app-account-name-short { display: none; }
    @media (max-width: 390px) {
        .app-account-name-full { display: none; }
        .app-account-name-short { display: inline; }
    }
</style>

<header class="header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        {{-- Carries a visible label, so it needs the pill variant — the plain
             42px square is sized for an icon alone and the text spilled past
             its border. aria-label starts with the visible word for the same
             reason as the Alerts button below; the title repeated it, so it is
             gone. --}}
        <button class="app-icon-btn app-icon-btn-labelled d-lg-none" id="sidebarToggle" type="button"
                aria-label="Menu, open sidebar">
            <i class="bi bi-list fs-5" aria-hidden="true"></i><span class="app-icon-btn-text">Menu</span>
        </button>

        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-[.14em] text-blue-700 border border-blue-100">OCR</span>
                <span class="text-muted small d-none d-sm-inline">Operations Workspace</span>
            </div>
            <h5 class="mb-0 fw-bold text-slate-900 text-truncate" style="font-size:1rem;letter-spacing:-.025em;">
                Humana Apparels Pvt. Ltd
            </h5>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2 gap-sm-3">
        @php
            $navNotifications = \App\Models\AppNotification::with('actor')
                ->where('user_id', auth()->id())
                ->visibleTo(auth()->user())
                ->latest()
                ->limit(8)
                ->get();

            $unreadNotificationCount = \App\Models\AppNotification::where('user_id', auth()->id())
                ->visibleTo(auth()->user())
                ->whereNull('read_at')
                ->count();
        @endphp

        <div class="dropdown">
            {{-- The visible word is "Alerts", so the accessible name starts with
                 it too: a speech-input user can only say what they can read.
                 The count stays in that label as well, which tells a
                 screen-reader user how many are waiting without opening the
                 menu. The old title="" is gone — it is not announced reliably,
                 is invisible on touch, and the visible label now does its job. --}}
            <button class="app-icon-btn app-icon-btn-labelled" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                    aria-label="Alerts{{ $unreadNotificationCount > 0 ? ', '.$unreadNotificationCount.' unread' : '' }}">
                {{-- The badge is anchored to the bell rather than to the button
                     so it stays on the icon now that the button is a wide pill. --}}
                <span class="position-relative d-inline-flex">
                    <i class="bi bi-bell fs-6" aria-hidden="true"></i>
                    @if($unreadNotificationCount > 0)
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm">
                            {{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}
                        </span>
                    @endif
                </span>
                <span class="app-icon-btn-text">Alerts</span>
            </button>

            <div class="dropdown-menu dropdown-menu-end p-0 border-0 shadow-lg overflow-hidden" style="width:min(380px, calc(100vw - 32px)); border-radius:20px; max-height:460px; overflow-y:auto;">
                <div class="px-3 py-3 border-bottom bg-light d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <strong class="text-slate-900">Notifications</strong>
                        <div class="small text-muted">Latest system updates</div>
                    </div>

                    @if($unreadNotificationCount > 0)
                        <form method="POST" action="{{ route('notifications.read-all') }}">
                            @csrf
                            <button class="btn btn-sm btn-link text-decoration-none fw-bold">Mark all read</button>
                        </form>
                    @endif
                </div>

                @forelse($navNotifications as $notification)
                    @php
                        $isPiMissingAlert = $notification->type === 'pi_missing_alert';
                        $notificationBg = $notification->read_at
                            ? ''
                            : ($isPiMissingAlert ? 'bg-danger-subtle' : 'bg-sky-50');
                        $notificationIconClass = $isPiMissingAlert ? 'bg-danger text-white' : 'bg-primary text-white';
                        $notificationIcon = $isPiMissingAlert ? 'bi-exclamation-octagon-fill' : 'bi-info-lg';
                    @endphp
                    <a href="{{ route('notifications.open', $notification->id) }}"
                       class="dropdown-item py-3 px-3 {{ $notificationBg }}">
                        <div class="d-flex gap-2">
                            <span class="flex-shrink-0 d-inline-flex align-items-center justify-content-center rounded-4 {{ $notificationIconClass }}" style="width:32px;height:32px;">
                                <i class="bi {{ $notificationIcon }}"></i>
                            </span>
                            <span class="min-w-0">
                                <span class="d-block fw-bold {{ $isPiMissingAlert ? 'text-danger' : 'text-slate-900' }} text-truncate">{{ $notification->title }}</span>
                                <span class="d-block small {{ $isPiMissingAlert ? 'text-danger-emphasis' : 'text-muted' }} text-wrap">{{ $notification->message }}</span>
                                <span class="d-block small text-muted mt-1">{{ $notification->created_at->diffForHumans() }}</span>
                            </span>
                        </div>
                    </a>
                @empty
                    <div class="p-4 text-center text-muted small">
                        <i class="bi bi-bell-slash fs-3 d-block mb-2 text-slate-400" aria-hidden="true"></i>
                        No notifications
                    </div>
                @endforelse
            </div>
        </div>

        @php
            $navAvatarUrl = auth()->user()->avatarUrl();
            // Role drives what this user can see, so it is worth surfacing —
            // it also makes "why can't I see X?" support questions quicker.
            $navRole = auth()->user()->getRoleNames()->map(
                fn ($role) => \Illuminate\Support\Str::headline($role)
            )->implode(', ');
        @endphp
        <div class="profile-wrapper">
            {{-- Opens on hover for pointer users and on focus for keyboard
                 users (see .profile-wrapper:focus-within) — Logout lives in
                 here, so it must be reachable without a mouse. --}}
            <button type="button" class="d-flex align-items-center gap-2 border-0 bg-transparent p-0"
                    aria-haspopup="menu" aria-label="Account menu for {{ auth()->user()->name }}">
                @if($navAvatarUrl)
                    <img src="{{ $navAvatarUrl }}" class="profile-img" alt="{{ auth()->user()->name }}">
                @else
                    <span class="profile-img d-inline-flex align-items-center justify-content-center bg-primary text-white fw-bold" style="font-size:13px;">{{ auth()->user()->initials() }}</span>
                @endif
                {{-- Visible at every width. Where the header is tight the text
                     truncates instead of disappearing — a narrow label is still
                     a label, an avatar on its own is not. --}}
                <span class="d-block text-start min-w-0 app-account-text">
                    {{-- Below 390px the full name would squeeze the company name
                         out of the header, so the first token stands in. Still a
                         readable label at every width — one of the two is always
                         on screen, never neither. --}}
                    <span class="d-block fw-bold text-slate-900 text-truncate" style="font-size:13px;">
                        <span class="app-account-name-full">{{ auth()->user()->name }}</span>
                        <span class="app-account-name-short">{{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</span>
                    </span>
                    <span class="d-block text-muted text-truncate" style="font-size:11px;">{{ auth()->user()->email }}</span>
                </span>
            </button>

            <div class="profile-card">
                <div class="d-flex align-items-center gap-3 border-bottom pb-3 mb-2">
                    @if($navAvatarUrl)
                        <img src="{{ $navAvatarUrl }}" class="rounded-4" style="width:44px;height:44px;object-fit:cover;" alt="{{ auth()->user()->name }}">
                    @else
                        <span class="rounded-4 d-inline-flex align-items-center justify-content-center bg-primary text-white fw-bold" style="width:44px;height:44px;font-size:16px;">{{ auth()->user()->initials() }}</span>
                    @endif
                    <div class="min-w-0">
                        <strong class="d-block text-truncate">{{ auth()->user()->name }}</strong>
                        <p class="mb-0 text-muted small text-truncate">{{ auth()->user()->email }}</p>
                        @if($navRole !== '')
                            <span class="badge bg-primary-subtle text-primary mt-1">{{ $navRole }}</span>
                        @endif
                    </div>
                </div>

                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    <i class="bi bi-person me-2 text-primary" aria-hidden="true"></i> Profile settings
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger w-100 text-start">
                        <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
