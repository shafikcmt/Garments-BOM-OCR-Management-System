<header class="header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <button class="app-icon-btn d-lg-none" id="sidebarToggle" type="button" aria-label="Open sidebar" title="Open menu">
            <i class="bi bi-list fs-5"></i>
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
            <button class="app-icon-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell fs-6"></i>
                @if($unreadNotificationCount > 0)
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm">
                        {{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}
                    </span>
                @endif
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
                        <i class="bi bi-bell-slash fs-3 d-block mb-2 text-slate-400"></i>
                        No notifications
                    </div>
                @endforelse
            </div>
        </div>

        @php $navAvatarUrl = auth()->user()->avatarUrl(); @endphp
        <div class="profile-wrapper">
            <button type="button" class="d-flex align-items-center gap-2 border-0 bg-transparent p-0">
                @if($navAvatarUrl)
                    <img src="{{ $navAvatarUrl }}" class="profile-img" alt="{{ auth()->user()->name }}">
                @else
                    <span class="profile-img d-inline-flex align-items-center justify-content-center bg-primary text-white fw-bold" style="font-size:13px;">{{ auth()->user()->initials() }}</span>
                @endif
                <span class="d-none d-md-block text-start min-w-0">
                    <span class="d-block fw-bold text-slate-900 text-truncate" style="font-size:13px;max-width:150px;">{{ auth()->user()->name }}</span>
                    <span class="d-block text-muted text-truncate" style="font-size:11px;max-width:150px;">{{ auth()->user()->email }}</span>
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
                    </div>
                </div>

                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    <i class="bi bi-person me-2 text-primary"></i> Profile settings
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger w-100 text-start">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
