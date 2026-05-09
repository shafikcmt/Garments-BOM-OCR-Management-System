<header class="header">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <button class="app-icon-btn d-lg-none" id="sidebarToggle" type="button" aria-label="Open sidebar">
            <i class="bi bi-list fs-5"></i>
        </button>

        <div class="min-w-0">
            <div class="d-flex align-items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-sky-700">OCR</span>
                <span class="text-muted small d-none d-sm-inline">Enterprise Workspace</span>
            </div>
            <h5 class="mb-0 fw-bold text-slate-900 text-truncate" style="letter-spacing:-.03em;">
                Humana Apparels Pvt. Ltd
            </h5>
        </div>
    </div>

    <div class="d-flex align-items-center gap-3">
        @php
            $navNotifications = \App\Models\AppNotification::with('actor')
                ->where('user_id', auth()->id())
                ->latest()
                ->limit(8)
                ->get();

            $unreadNotificationCount = \App\Models\AppNotification::where('user_id', auth()->id())
                ->whereNull('read_at')
                ->count();
        @endphp

        <div class="dropdown">
            <button class="app-icon-btn position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell fs-5"></i>
                @if($unreadNotificationCount > 0)
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger shadow-sm">
                        {{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}
                    </span>
                @endif
            </button>

            <div class="dropdown-menu dropdown-menu-end p-0 border-0 shadow-lg overflow-hidden" style="width:min(380px, calc(100vw - 32px)); border-radius:22px; max-height:460px; overflow-y:auto;">
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
                    <a href="{{ route('notifications.open', $notification->id) }}"
                       class="dropdown-item py-3 px-3 {{ $notification->read_at ? '' : 'bg-sky-50' }}">
                        <div class="d-flex gap-2">
                            <span class="flex-shrink-0 d-inline-flex align-items-center justify-content-center rounded-4 bg-primary text-white" style="width:34px;height:34px;">
                                <i class="bi bi-info-lg"></i>
                            </span>
                            <span class="min-w-0">
                                <span class="d-block fw-bold text-slate-900 text-truncate">{{ $notification->title }}</span>
                                <span class="d-block small text-muted text-wrap">{{ $notification->message }}</span>
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

        <div class="profile-wrapper">
            <img
                src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTxz7qJ9pU6Xj2EJKaRDVz-9Bd0xh2LnMklGw&s"
                class="profile-img"
                alt="User"
            >

            <div class="profile-card">
                <div class="d-flex align-items-center gap-3 border-bottom pb-3 mb-2">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTxz7qJ9pU6Xj2EJKaRDVz-9Bd0xh2LnMklGw&s" class="rounded-4" style="width:46px;height:46px;object-fit:cover;" alt="User">
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
