@php
    $isAdminDashboard = request()->routeIs('admin.dashboard');
    $isAdminWorkspace = request()->routeIs('admin.workspace') || request()->routeIs('uploaded-files.*') || request()->routeIs('admin.headers.*');
    $isAdminUserRole = request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*');
    $isAdminBookingSettings = request()->routeIs('admin.suppliers.*') || request()->routeIs('admin.booking-delivery-destinations.*') || request()->routeIs('admin.booking-instructions.*') || request()->routeIs('admin.po-generate-control.*');
    $isSupplyBooking = request()->routeIs('supply_chain.bookings.*');
    $isSupplyPayment = request()->routeIs('supply_chain.payment_requests.*');
    $supplyBookingUrl = route('supply_chain.bookings.index');
    $supplyPaymentUrl = route('supply_chain.payment_requests.index');
@endphp

<nav class="sidebar" aria-label="Main sidebar navigation">
    <div class="sidebar-inner">
        <div class="sidebar-logo">
            <a href="{{ url('/dashboard') }}" class="sidebar-brand-link">
                <span class="brand-mark"><i class="bi bi-grid-1x2-fill"></i></span>
                <span class="min-w-0">
                    <span class="sidebar-brand-title">HAPL OCR</span>
                    <span class="sidebar-brand-subtitle">Management System</span>
                </span>
            </a>
            <button type="button" class="btn btn-sm d-lg-none sidebar-close-btn" id="sidebarClose" aria-label="Close sidebar">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="sidebar-menu">
            @role('admin')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('admin.dashboard') }}" class="sidebar-nav-link {{ $isAdminDashboard ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span>
                                <span class="sidebar-link-text">Dashboard</span>
                            </span>
                        </a>
                    </li>

                    <li class="sidebar-group {{ $isAdminWorkspace ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminWorkspace ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminWorkspace ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-grid"></i></span>
                                <span class="sidebar-link-text">Workspace</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminWorkspace ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('admin.workspace') }}" class="sidebar-sub-link {{ request()->routeIs('admin.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">Workspace</a>
                            <a href="{{ route('admin.headers.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.headers.*') ? 'is-active' : '' }}">Excel Headers</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminUserRole ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminUserRole ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminUserRole ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-people"></i></span>
                                <span class="sidebar-link-text">Users &amp; Roles</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminUserRole ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('admin.users.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">Users</a>
                            <a href="{{ route('admin.roles.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.roles.*') ? 'is-active' : '' }}">Roles</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminBookingSettings ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminBookingSettings ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminBookingSettings ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-sliders"></i></span>
                                <span class="sidebar-link-text">Booking Setup</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminBookingSettings ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('admin.suppliers.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.suppliers.*') ? 'is-active' : '' }}">Vendors</a>
                            <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.booking-delivery-destinations.*') ? 'is-active' : '' }}">Destinations</a>
                            <a href="{{ route('admin.booking-instructions.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.booking-instructions.*') ? 'is-active' : '' }}">Instructions</a>
                            <a href="{{ route('admin.po-generate-control.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.po-generate-control.*') ? 'is-active' : '' }}">PO Generate Control</a>
                        </div>
                    </li>
                </ul>
            </div>
            @endrole

            @role('merchant')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('merchant.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('merchant.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            @endrole

            @role('account')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('account.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('account.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('account.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('account.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            @endrole

            @role('commercial')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            @endrole

            @role('store')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('store.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('store.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('store.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            @endrole

            @role('supply_chain')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-speedometer2"></i></span>
                                <span class="sidebar-link-text">Dashboard</span>
                            </span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-columns-gap"></i></span>
                                <span class="sidebar-link-text">Workspace</span>
                            </span>
                        </a>
                    </li>
                    <li class="sidebar-group {{ $isSupplyBooking ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isSupplyBooking ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isSupplyBooking ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-file-earmark-plus"></i></span>
                                <span class="sidebar-link-text">PO Generate</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isSupplyBooking ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ $supplyBookingUrl }}#pending-generated-po" data-hash="pending-generated-po" class="sidebar-sub-link {{ $isSupplyBooking ? 'is-active' : '' }}">Booking Preview</a>
                            <a href="{{ $supplyBookingUrl }}#recent-generated-po" data-hash="recent-generated-po" class="sidebar-sub-link">Generated PO List</a>
                        </div>
                    </li>
                    <li class="sidebar-group {{ $isSupplyPayment ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isSupplyPayment ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isSupplyPayment ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-cash-coin"></i></span>
                                <span class="sidebar-link-text">Payment Request</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isSupplyPayment ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ $supplyPaymentUrl }}#pending-pi-payment" data-hash="pending-pi-payment" class="sidebar-sub-link {{ request()->routeIs('supply_chain.payment_requests.index') || request()->routeIs('supply_chain.payment_requests.create') ? 'is-active' : '' }}">Pending PI Payment</a>
                            <a href="{{ $supplyPaymentUrl }}#payment-request-list" data-hash="payment-request-list" class="sidebar-sub-link {{ request()->routeIs('supply_chain.payment_requests.show') ? 'is-active' : '' }}">Payment Request List</a>
                        </div>
                    </li>
                </ul>
            </div>
            @endrole

        </div>
    </div>
</nav>
