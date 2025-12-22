<div class="header shadow-sm">
    <div class="d-flex align-items-center gap-3">
        <!-- Sidebar Toggle (Mobile) -->
        <button class="btn btn-outline-secondary d-lg-none" id="sidebarToggle">
            <i class="bi bi-list"></i>
        </button>

        <!-- Logo -->
        <!-- <img src="{{ asset('img/logo.png') }}" alt="Logo" style="height:50px"> -->

        <!-- Title -->
        <h5 class="mb-0 fw-semibold text-uppercase">
            Humana Apparels Pvt. Ltd
        </h5>
    </div>

    <div class="d-flex align-items-center gap-4">
        <a href="#"><i class="bi bi-bell fs-5"></i></a>
        <a href="#"><i class="bi bi-chat-left-text fs-5"></i></a>

        <!-- Profile Dropdown -->
        <div class="profile-wrapper">
            <img
                src="{{ auth()->user()->gender === 'female'
                        ? 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNJryFTSQUV8Zuu_EGw2iUCpMbIIKWHBl2eQ&s'
                        : 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTxz7qJ9pU6Xj2EJKaRDVz-9Bd0xh2LnMklGw&s' }}"
                class="profile-img"
                alt="User"
            >

            <!-- Hover Card -->
            <div class="profile-card">
                <div class="text-center border-bottom pb-2 mb-2">
                    <strong>{{ auth()->user()->designation ?? 'Employee' }}</strong>
                    <p class="mb-0 text-muted small">{{ auth()->user()->name }}</p>
                </div>

                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    <i class="bi bi-person me-2"></i> Profile
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
