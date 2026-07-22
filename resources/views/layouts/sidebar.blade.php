@php
    $isAdminDashboard = request()->routeIs('admin.dashboard');
    $isAdminWorkspace = request()->routeIs('admin.workspace') || request()->routeIs('uploaded-files.*') || request()->routeIs('admin.headers.*');
    $isAdminUserRole = request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*');
    $isAdminBookingSettings = request()->routeIs('admin.suppliers.*') || request()->routeIs('admin.booking-delivery-destinations.*') || request()->routeIs('admin.booking-instructions.*') || request()->routeIs('admin.po-generate-control.*');
    $isAdminSettings = request()->routeIs('admin.alert-settings.*') || request()->routeIs('admin.payment-settings.*') || request()->routeIs('admin.email-templates.*');
    // Store screens Admin shares with the store role (Bulk Issuing corrections,
    // reports). Store's own sidebar block lists these separately.
    $isAdminStore = request()->routeIs('store.material.bulk-issues.*') || request()->routeIs('store.reports.*');
    $isSupplyBooking = request()->routeIs('supply_chain.bookings.*');
    $isSupplyPayment = request()->routeIs('supply_chain.payment_requests.*');
    $isSupplyEmails = request()->routeIs('supply_chain.sent_emails.*');
    $supplyBookingUrl = route('supply_chain.bookings.index');
    $supplyPaymentUrl = route('supply_chain.payment_requests.index');
@endphp

<nav class="sidebar" aria-label="Main sidebar navigation">
    <div class="sidebar-inner">
        <div class="sidebar-logo">
            <a href="{{ url('/dashboard') }}" class="sidebar-brand-link">
                <span class="brand-mark"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i></span>
                <span class="min-w-0">
                    <span class="sidebar-brand-title">HAPL OCR</span>
                    <span class="sidebar-brand-subtitle">Management System</span>
                </span>
            </a>
            <button type="button" class="btn btn-sm d-lg-none sidebar-close-btn" id="sidebarClose" aria-label="Close sidebar" title="Close menu">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
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
                                <span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Dashboard</span>
                            </span>
                        </a>
                    </li>

                    <li class="sidebar-group {{ $isAdminStore ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminStore ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminStore ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Store</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminStore ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('store.material.bulk-issues.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.material.bulk-issues.*') ? 'is-active' : '' }}">Bulk Issuing</a>
                            <a href="{{ route('store.reports.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">Store Reports</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminWorkspace ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminWorkspace ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminWorkspace ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Workspace</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
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
                                <span class="sidebar-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Users &amp; Roles</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
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
                                <span class="sidebar-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Booking Setup</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminBookingSettings ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('admin.suppliers.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.suppliers.*') ? 'is-active' : '' }}">Vendors</a>
                            <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.booking-delivery-destinations.*') ? 'is-active' : '' }}">Destinations</a>
                            <a href="{{ route('admin.booking-instructions.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.booking-instructions.*') ? 'is-active' : '' }}">Instructions</a>
                            <a href="{{ route('admin.po-generate-control.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.po-generate-control.*') ? 'is-active' : '' }}">PO Generate Control</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminSettings ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminSettings ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminSettings ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-gear" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Settings</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminSettings ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('admin.alert-settings.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.alert-settings.*') ? 'is-active' : '' }}">Alert Settings</a>
                            <a href="{{ route('admin.payment-settings.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.payment-settings.*') ? 'is-active' : '' }}">PI / PRA Settings</a>
                            <a href="{{ route('admin.email-templates.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.email-templates.*') ? 'is-active' : '' }}">Email Templates</a>
                            <a href="{{ route('admin.pra-approvers.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.pra-approvers.*') || request()->routeIs('admin.pra-approvals.*') ? 'is-active' : '' }}">PRA Approvers</a>
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
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('merchant.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Store Reports</span></span>
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
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('account.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('account.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
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
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
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
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('store.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Reports</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-label">General Stock</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.ledger') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.ledger') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span><span class="sidebar-link-text">Monthly Ledger</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.items.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.items.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span class="sidebar-link-text">Items</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.purchases.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.purchases.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-truck" aria-hidden="true"></i></span><span class="sidebar-link-text">Purchases</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.issues.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.issues.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span><span class="sidebar-link-text">Issues</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-label">Buyer / Style Stock</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.ledger') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.ledger') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span><span class="sidebar-link-text">Closing Stock</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.receivings.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.receivings.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i></span><span class="sidebar-link-text">Receiving</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.bulk-issues.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.bulk-issues.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span><span class="sidebar-link-text">Bulk Issuing</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.requisitions.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.requisitions.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span><span class="sidebar-link-text">Requisitions</span></span>
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
                                <span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Dashboard</span>
                            </span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-columns-gap" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Workspace</span>
                            </span>
                        </a>
                    </li>
                    <li class="sidebar-group {{ $isSupplyBooking ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isSupplyBooking ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isSupplyBooking ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">PO Generate</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
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
                                <span class="sidebar-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Payment Request</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isSupplyPayment ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ $supplyPaymentUrl }}#pending-pi-payment" data-hash="pending-pi-payment" class="sidebar-sub-link {{ request()->routeIs('supply_chain.payment_requests.index') || request()->routeIs('supply_chain.payment_requests.create') ? 'is-active' : '' }}">Pending PI Payment</a>
                            <a href="{{ $supplyPaymentUrl }}#payment-request-list" data-hash="payment-request-list" class="sidebar-sub-link {{ request()->routeIs('supply_chain.payment_requests.show') ? 'is-active' : '' }}">Payment Request List</a>
                            <a href="{{ route('supply_chain.payment_requests.my_status') }}" class="sidebar-sub-link {{ request()->routeIs('supply_chain.payment_requests.my_status') ? 'is-active' : '' }}">My PRA Status</a>
                        </div>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.sent_emails.index') }}" class="sidebar-nav-link {{ $isSupplyEmails ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-envelope-paper" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Sent Emails</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>
            @endrole

            @role('management')
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('management.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('management.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Dashboard</span>
                            </span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Store Reports</span>
                            </span>
                        </a>
                    </li>
                    {{-- Management records no issues; the link is here so they can
                         review and correct what Store recorded. --}}
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.bulk-issues.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.bulk-issues.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Bulk Issuing</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>
            @endrole

            @can('approve-pra')
                @php
                    $praUid = auth()->id();
                    $praPendingCount = \App\Models\PaymentRequest::where(function ($q) use ($praUid) {
                            $q->where(function ($qq) use ($praUid) {
                                $qq->where('status', 'pending_check')
                                   ->whereHas('approvals', fn ($a) => $a->where('approver_id', $praUid)->where('stage', 'check')->where('status', 'pending'));
                            })->orWhere(function ($qq) use ($praUid) {
                                $qq->where('status', 'pending_approval')
                                   ->whereHas('approvals', fn ($a) => $a->where('approver_id', $praUid)->where('stage', 'approve')->where('status', 'pending'));
                            });
                        })->count();
                @endphp
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Approvals</div>
                    <ul class="sidebar-list">
                        <li class="sidebar-item">
                            <a href="{{ route('pra_approvals.index') }}" class="sidebar-nav-link {{ request()->routeIs('pra_approvals.*') ? 'is-active' : '' }}">
                                <span class="sidebar-link-main">
                                    <span class="sidebar-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                                    <span class="sidebar-link-text">PRA Approvals</span>
                                </span>
                                @if($praPendingCount > 0)
                                    <span class="badge rounded-pill bg-danger">{{ $praPendingCount > 99 ? '99+' : $praPendingCount }}</span>
                                @endif
                            </a>
                        </li>
                    </ul>
                </div>
            @endcan

            @can('manage-style-budgets')
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Planning</div>
                    <ul class="sidebar-list">
                        <li class="sidebar-item">
                            <a href="{{ route('style-budgets.index') }}" class="sidebar-nav-link {{ request()->routeIs('style-budgets.*') ? 'is-active' : '' }}">
                                <span class="sidebar-link-main">
                                    <span class="sidebar-icon"><i class="bi bi-bar-chart" aria-hidden="true"></i></span>
                                    <span class="sidebar-link-text">Style Budgets</span>
                                </span>
                            </a>
                        </li>
                    </ul>
                </div>
            @endcan

        </div>
    </div>
</nav>
