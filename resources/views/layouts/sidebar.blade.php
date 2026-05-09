<nav class="sidebar" aria-label="Main sidebar navigation">
    <div class="sidebar-logo d-flex align-items-center justify-content-between gap-3">
        <a href="{{ url('/dashboard') }}" class="d-flex align-items-center gap-3 text-white min-w-0">
            <span class="brand-mark"><i class="bi bi-grid-1x2-fill"></i></span>
            <span class="min-w-0">
                <span class="d-block fw-bold fs-6 lh-1">HAPL OCR</span>
                <span class="d-block small text-sky-100 opacity-75 mt-1 text-truncate">Management System</span>
            </span>
        </a>
        <button type="button" class="btn btn-sm btn-outline-light d-lg-none rounded-4" id="sidebarClose" aria-label="Close sidebar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="sidebar-menu">
        <div class="px-2 pb-2 small text-sky-100 text-uppercase fw-bold opacity-75" style="letter-spacing:.08em;">Menu</div>
        <ul class="nav flex-column" id="sidebarMenu">

            @role('admin')
            <li class="nav-item">
                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span>Admin Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.workspace') }}" class="nav-link {{ request()->routeIs('admin.workspace') || request()->routeIs('uploaded-files.*') ? 'active' : '' }}">
                    <i class="bi bi-layout-text-window-reverse"></i>
                    <span>Workspace Control</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span>User Control</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.roles.index') }}" class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                    <i class="bi bi-person-badge"></i>
                    <span>Role Control</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.suppliers.index') }}" class="nav-link {{ request()->routeIs('admin.suppliers.*') ? 'active' : '' }}">
                    <i class="bi bi-building"></i>
                    <span>Vendor Control</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="nav-link {{ request()->routeIs('admin.booking-delivery-destinations.*') ? 'active' : '' }}">
                    <i class="bi bi-geo-alt"></i>
                    <span>Delivery Destination</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.booking-instructions.index') }}" class="nav-link {{ request()->routeIs('admin.booking-instructions.*') ? 'active' : '' }}">
                    <i class="bi bi-card-checklist"></i>
                    <span>Booking Instructions</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.headers.index') }}" class="nav-link {{ request()->routeIs('admin.headers.*') ? 'active' : '' }}">
                    <i class="bi bi-file-earmark-spreadsheet"></i>
                    <span>Excel Header Control</span>
                </a>
            </li>
            @endrole

            @role('merchant')
            <li class="nav-item">
                <a href="{{ route('merchant.dashboard') }}" class="nav-link {{ request()->routeIs('merchant.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span>Merchant Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('merchant.workspace') }}" class="nav-link {{ request()->routeIs('merchant.workspace') || request()->routeIs('uploaded-files.*') ? 'active' : '' }}">
                    <i class="bi bi-layout-text-window-reverse"></i>
                    <span>Merchant Workspace</span>
                </a>
            </li>
            @endrole

            @role('account')
            <li class="nav-item">
                <a href="{{ route('account.dashboard') }}" class="nav-link {{ request()->routeIs('account.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span>Account Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('account.workspace') }}" class="nav-link {{ request()->routeIs('account.workspace') || request()->routeIs('uploaded-files.*') ? 'active' : '' }}">
                    <i class="bi bi-layout-text-window-reverse"></i>
                    <span>Account Workspace</span>
                </a>
            </li>
            @endrole

            @role('commercial')
            <li class="nav-item">
                <a href="{{ route('commercial.dashboard') }}" class="nav-link {{ request()->routeIs('commercial.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span>Commercial Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('commercial.workspace') }}" class="nav-link {{ request()->routeIs('commercial.workspace') || request()->routeIs('uploaded-files.*') ? 'active' : '' }}">
                    <i class="bi bi-layout-text-window-reverse"></i>
                    <span>Commercial Workspace</span>
                </a>
            </li>
            @endrole

            @role('store')
            <li class="nav-item">
                <a href="{{ route('store.dashboard') }}" class="nav-link {{ request()->routeIs('store.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span>Store Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('store.workspace') }}" class="nav-link {{ request()->routeIs('store.workspace') || request()->routeIs('uploaded-files.*') ? 'active' : '' }}">
                    <i class="bi bi-layout-text-window-reverse"></i>
                    <span>Store Workspace</span>
                </a>
            </li>
            @endrole

            @role('supply_chain')
            <li class="nav-item">
                <a href="{{ route('supply_chain.dashboard') }}" class="nav-link {{ request()->routeIs('supply_chain.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span>Supply Chain Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('supply_chain.workspace') }}" class="nav-link {{ request()->routeIs('supply_chain.workspace') || request()->routeIs('uploaded-files.*') ? 'active' : '' }}">
                    <i class="bi bi-layout-text-window-reverse"></i>
                    <span>Supply Chain Workspace</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('supply_chain.bookings.index') }}" class="nav-link {{ request()->routeIs('supply_chain.bookings.*') ? 'active' : '' }}">
                    <i class="bi bi-file-earmark-plus"></i>
                    <span>Booking Preview</span>
                </a>
            </li>
            @endrole
        </ul>

        <div class="mt-4 mx-1 rounded-4 border border-white/10 p-3" style="background:rgba(255,255,255,.07);">
            <div class="d-flex align-items-center gap-2 text-sky-100 fw-bold">
                <i class="bi bi-shield-check"></i>
                Secure workspace
            </div>
            <div class="small text-sky-100 opacity-75 mt-1">Role-based OCR workflow and approvals.</div>
        </div>
    </div>
</nav>
