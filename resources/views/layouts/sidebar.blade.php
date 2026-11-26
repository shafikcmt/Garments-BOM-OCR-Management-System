@php
    $isAdminMerch = request()->routeIs('merchant.status.*') || request()->routeIs('merchant.ocr.*') || request()->routeIs('merchant.samples.*') || request()->routeIs('merchant.inquiries.*') || request()->routeIs('merchant.meetings.*') || request()->routeIs('merchant.analytics.*') || request()->routeIs('merchant.consumption.*') || request()->routeIs('merchant.costing_templates.*') || request()->routeIs('merchant.reports.*');
    $isAdminSupply = request()->routeIs('store.purchasing.*') || request()->routeIs('admin.cs-signatures.*') || request()->routeIs('supply_chain.supplier_delivery') || request()->routeIs('supply_chain.reports.*');
    $isAdminCommercial = request()->routeIs('commercial.shipments.*') || request()->routeIs('commercial.reports.*');
    $isAdminRequisitionSetup = request()->routeIs('admin.departments.*') || request()->routeIs('admin.requisition-signatures.*') || request()->routeIs('store.stock.requisitions.window.*') || request()->routeIs('admin.item-sku.*');
    $isMerchDesk = request()->routeIs('merchant.inquiries.*') || request()->routeIs('merchant.samples.*') || request()->routeIs('merchant.meetings.*');
    $isMerchCosting = request()->routeIs('merchant.consumption.*') || request()->routeIs('merchant.costing_templates.*') || request()->routeIs('merchant.vendors.*');
    $isMerchReports = request()->routeIs('merchant.status.*') || request()->routeIs('merchant.reports.*') || request()->routeIs('merchant.analytics.*') || request()->routeIs('merchant.ocr.*') || request()->routeIs('store.reports.*');
    $isAdminDashboard = request()->routeIs('admin.dashboard');
    $isAdminWorkspace = request()->routeIs('admin.workspace') || request()->routeIs('uploaded-files.*') || request()->routeIs('admin.headers.*') || request()->routeIs('admin.unmapped.*');
    $isAdminUserRole = request()->routeIs('admin.users.*') || request()->routeIs('admin.roles.*');
    $isAdminBookingSettings = request()->routeIs('admin.suppliers.*') || request()->routeIs('admin.supplier-options.*') || request()->routeIs('admin.buyers.*') || request()->routeIs('admin.booking-delivery-destinations.*') || request()->routeIs('admin.booking-instructions.*') || request()->routeIs('admin.po-generate-control.*');
    $isAdminSettings = request()->routeIs('admin.alert-settings.*') || request()->routeIs('admin.payment-settings.*') || request()->routeIs('admin.email-templates.*') || request()->routeIs('admin.pra-approvers.*') || request()->routeIs('admin.pra-approvals.*') || request()->routeIs('admin.pra-routing.*') || request()->routeIs('admin.pra-steps.*');
    // Store screens Admin shares with the store role: Bulk Issuing corrections,
    // reports, and the General Stock purchase / setup screens Admin owns the
    // edit and delete rights for. Store's own sidebar block lists these
    // separately. Keeping the list here is what makes the Store group open and
    // highlight itself when one of its pages is showing.
    $isAdminStore = request()->routeIs('store.material.bulk-issues.*')
        || request()->routeIs('store.reports.*')
        || request()->routeIs('store.stock.*');
    $isSupplyBooking = request()->routeIs('supply_chain.bookings.*');
    $isSupplyPayment = request()->routeIs('supply_chain.payment_requests.*');
    $isSupplyEmails = request()->routeIs('supply_chain.sent_emails.*');
    $supplyBookingUrl = route('supply_chain.bookings.index');
    $supplyPaymentUrl = route('supply_chain.payment_requests.index');

    /*
     * Store menu visibility.
     *
     * The Store block used to hang off @role('store') alone, so a user on a
     * narrow role — Store — General Stock, say — matched nothing and was shown
     * an empty sidebar, even though every screen behind it would have let them
     * in.
     *
     * Each item now asks the same question its own route guard asks: the store
     * role still passes everything exactly as before, and a permission opens
     * the one item it covers. Never wider than the guard, so no link is offered
     * that would answer 403 — Store Reports is the case that matters there,
     * since its route is still role-only and has no permission to grant.
     *
     * Only the store role's block is affected. Admin, Management and the other
     * departments keep their own blocks unchanged.
     */
    $storeUser = auth()->user();
    $isStoreRole = $storeUser?->hasRole('store') ?? false;

    // role OR the permission — the same shape as role_or_perm on the routes.
    $storeSees = fn (string $permission) => $isStoreRole || ($storeUser?->can($permission) ?? false);

    $seeStockReport = $storeSees('store.stock_report.view');
    $seeItems = $storeSees('store.items.view');
    $seeGsReceiving = $storeSees('store.receiving.view');
    $seeIssues = $storeSees('store.issues.view');
    $seeRequisition = $storeSees('store.requisition.view');
    $seeSetup = $storeSees('store.setup.view');

    $seeClosingStock = $storeSees('material.closing_stock.view');
    $seeBsReceiving = $storeSees('material.receiving.view');
    $seeBulkIssue = $storeSees('material.bulk_issue.view');
    $seeBsRequisitions = $storeSees('material.requisitions.view');

    $seeGeneralStock = $seeStockReport || $seeItems || $seeGsReceiving || $seeIssues || $seeRequisition || $seeSetup;
    $seeMaterialStock = $seeClosingStock || $seeBsReceiving || $seeBulkIssue || $seeBsRequisitions;

    // Dashboard and Workspace sit behind the store area's entry guard, which
    // admits anyone holding any one of the module permissions.
    $seeStoreHome = $isStoreRole || $seeGeneralStock || $seeMaterialStock;

    // The Dashboard link itself asks the dashboard route's own question —
    // every General Stock right EXCEPT the requisition one — so a user given
    // only Purchase Requisition (the requisition_raiser role, or a clerk
    // granted that screen alone) is not offered a link that would answer 403.
    $seeStoreDashboard = $isStoreRole || $seeStockReport || $seeItems || $seeGsReceiving || $seeIssues || $seeSetup || $seeMaterialStock;

    // Store Reports is guarded by role only — no permission exists for it, so a
    // permission-only user must not be offered it.
    $seeStoreReports = $isStoreRole;

    /*
     * Roles that already have a sidebar block of their own further up. Admin and
     * Management list the Store screens inside their own group, and Gate::before
     * answers true to every permission for admin — so without this the Store
     * block would render for them as well and they would carry two sets of
     * navigation to the same screens. Store is deliberately absent from the
     * list: this block is its own.
     */
    $hasOwnSidebarBlock = $storeUser?->hasAnyRole(['admin', 'merchant', 'account', 'commercial', 'supply_chain', 'management']) ?? false;

    $seeStoreArea = ! $hasOwnSidebarBlock && ($seeStoreHome || $seeStoreReports);

    // Recomputed below once Workspace has been resolved, so somebody granted
    // Workspace and nothing else still gets a menu to reach it from.

    /*
     * Team Management — the scoped User Management screen, for the head of the
     * department rather than everyone in it. The flag is what decides it, not
     * the role: every Store user holds a Store role, and only one or two of
     * them should be creating accounts.
     *
     * Admin keeps its own "Users & Roles" entry further up and must not gain a
     * second link to the same screen, which is why this sits inside the Store
     * block — a block admin never renders.
     */
    $seeTeamManagement = $storeUser?->isDepartmentAdmin() ?? false;

    /*
     * Workspace asks its own question now.
     *
     * It used to sit under $seeStoreHome beside Dashboard, so any single
     * General Stock permission put BOM files in the menu. It is the one Store
     * screen with its own permission, and this is the only thing that decides
     * whether the link appears — the route refuses anyone it would mislead.
     */
    $seeWorkspace = $storeUser?->can('store.workspace.view') ?? false;

    $seeStoreArea = $seeStoreArea || (! $hasOwnSidebarBlock && $seeWorkspace);

    // General Stock purchasing (To Purchase, Comparative Statement, Internal PO).
    // Permission-driven, so the live role name does not matter. Admin lists these
    // inside its own Store group instead, hence the own-block exclusion.
    $seePurchaseCs = $storeUser?->can('store.purchase_cs.view') ?? false;
    $seePurchasePo = $storeUser?->can('store.purchase_po.view') ?? false;
    // Only Admin is excluded: its own Store group already lists these screens. Every
    // other role (Merchant, Account, Commercial, Supply Chain, Management) has a block of
    // its own that does NOT, so a person given a purchasing right there needs this section.
    $seePurchasing = ! ($storeUser?->hasRole('admin') ?? false) && ($seePurchaseCs || $seePurchasePo);

    // What is waiting on THIS person, shown as a badge so nobody has to open each list.
    $csWaiting = 0;
    $poWaiting = 0;
    if ($seePurchasing && $storeUser) {
        $csWaiting = \App\Models\GsComparativeStatement::query()->where('prepared_by', '<>', $storeUser->id)
            ->where(fn ($q) => $q
                ->when($storeUser->can('store.purchase_cs.review'), fn ($w) => $w->orWhere('status', \App\Models\GsComparativeStatement::STATUS_SUBMITTED))
                ->when($storeUser->can('store.purchase_cs.approve'), fn ($w) => $w->orWhere('status', \App\Models\GsComparativeStatement::STATUS_CHECKED)))
            ->when(! $storeUser->can('store.purchase_cs.review') && ! $storeUser->can('store.purchase_cs.approve'), fn ($q) => $q->whereRaw('1 = 0'))
            ->count();
        $poWaiting = \App\Models\GsPurchaseOrder::query()
            ->where(fn ($q) => $q
                ->when($storeUser->can('store.purchase_po.review'), fn ($w) => $w->orWhere(fn ($d) => $d->where('status', \App\Models\GsPurchaseOrder::STATUS_DRAFT)->where('created_by', '<>', $storeUser->id)))
                ->when($storeUser->can('store.purchase_po.approve'), fn ($w) => $w->orWhere(fn ($c) => $c->where('status', \App\Models\GsPurchaseOrder::STATUS_CHECKED)->where('created_by', '<>', $storeUser->id)->where('checked_by', '<>', $storeUser->id))))
            ->when(! $storeUser->can('store.purchase_po.review') && ! $storeUser->can('store.purchase_po.approve'), fn ($q) => $q->whereRaw('1 = 0'))
            ->count();
    }
@endphp

<nav class="sidebar" id="appSidebar" aria-label="Main sidebar navigation">
    <div class="sidebar-inner">
        <div class="sidebar-logo">
            <a href="{{ url('/dashboard') }}" class="sidebar-brand-link">
                <span class="brand-mark"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i></span>
                <span class="min-w-0">
                    <span class="sidebar-brand-title">HAPL Threadloop</span>
                    <span class="sidebar-brand-subtitle">Workflow System</span>
                </span>
            </a>
            <button type="button" class="btn btn-sm d-lg-none sidebar-close-btn" id="sidebarClose" aria-label="Close sidebar" >
                <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Close
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
                    @include('layouts._my-approvals-link', [])
                    @include('layouts._followup-link')
                    @include('layouts._bom-approval-link')

                    {{-- Each department's own report. Admin is one of the three
                         roles those routes admit, so they need a way in that is
                         not a hand-typed URL. --}}
                </ul>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-label">Departments</div>
                <ul class="sidebar-list">
                    <li class="sidebar-group {{ $isAdminMerch ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminMerch ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminMerch ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-bag" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Merchandising</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminMerch ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('merchant.status.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.status.*') ? 'is-active' : '' }}">Style Status</a>
                            <a href="{{ route('merchant.ocr.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.ocr.*') ? 'is-active' : '' }}">OCR Summary</a>
                            <a href="{{ route('merchant.samples.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.samples.*') ? 'is-active' : '' }}">Samples</a>
                            <a href="{{ route('merchant.inquiries.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.inquiries.*') ? 'is-active' : '' }}">Inquiries</a>
                            <a href="{{ route('merchant.meetings.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.meetings.*') ? 'is-active' : '' }}">Meetings</a>
                            <a href="{{ route('merchant.analytics.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.analytics.*') ? 'is-active' : '' }}">Analytics</a>
                            <a href="{{ route('merchant.consumption.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.consumption.*') ? 'is-active' : '' }}">Consumption Master</a>
                            <a href="{{ route('merchant.costing_templates.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.costing_templates.*') ? 'is-active' : '' }}">Costing Templates</a>
                            <a href="{{ route('merchant.reports.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.reports.*') ? 'is-active' : '' }}">Merchandising Reports</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminSupply ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminSupply ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminSupply ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Supply Chain</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminSupply ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('store.purchasing.queue') }}" class="sidebar-sub-link {{ request()->routeIs('store.purchasing.queue') ? 'is-active' : '' }}">To Purchase</a>
                            <a href="{{ route('store.purchasing.cs.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.purchasing.cs.*') ? 'is-active' : '' }}">Comparative Statement</a>
                            <a href="{{ route('store.purchasing.po.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.purchasing.po.*') ? 'is-active' : '' }}">Internal PO</a>
                            <a href="{{ route('supply_chain.supplier_delivery') }}" class="sidebar-sub-link {{ request()->routeIs('supply_chain.supplier_delivery') ? 'is-active' : '' }}">Supplier Delivery</a>
                            <a href="{{ route('supply_chain.reports.index') }}" class="sidebar-sub-link {{ request()->routeIs('supply_chain.reports.*') ? 'is-active' : '' }}">Supply Chain Reports</a>
                            <a href="{{ route('admin.cs-signatures.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.cs-signatures.*') ? 'is-active' : '' }}">CS Signatures</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminCommercial ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminCommercial ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminCommercial ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-globe2" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Commercial</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminCommercial ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('commercial.shipments.index') }}" class="sidebar-sub-link {{ request()->routeIs('commercial.shipments.*') ? 'is-active' : '' }}">Inbound Shipments</a>
                            <a href="{{ route('commercial.reports.index') }}" class="sidebar-sub-link {{ request()->routeIs('commercial.reports.*') ? 'is-active' : '' }}">Commercial Reports</a>
                        </div>
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
                            {{-- General Stock screens Admin holds the edit / delete
                                 rights for. Reachable by route before this, but with
                                 no way to navigate to them. Listed in the same order
                                 as the store role's own General Stock block, so the
                                 two sidebars read alike: the work in process order
                                 (Requisition → Receiving → Issues), then the
                                 report, then the master data. --}}
                            {{-- Named in full to keep it distinct from the
                                 material Requisitions under Buyer/Style Stock. --}}
                            <a href="{{ route('store.stock.requisitions.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.requisitions.*') && ! request()->routeIs('store.stock.requisitions.my-approvals') ? 'is-active' : '' }}">Purchase Requisition</a>
                            <a href="{{ route('store.stock.purchases.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.purchases.*') ? 'is-active' : '' }}">Receiving</a>
                            <a href="{{ route('store.stock.issues.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.issues.*') ? 'is-active' : '' }}">Issues</a>
                            <a href="{{ route('store.stock.ledger') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.ledger*') ? 'is-active' : '' }}">Stock Report</a>
                            <a href="{{ route('store.stock.items.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.items.*') ? 'is-active' : '' }}">Items</a>
                            {{-- Purchase Setup and Issue Setup are one screen now.
                                 Their old routes redirect here, so both spellings
                                 still highlight this entry. --}}
                            <a href="{{ route('store.stock.setup') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.setup') || request()->routeIs('store.stock.purchase-setup.*') || request()->routeIs('store.stock.issue-setup.*') ? 'is-active' : '' }}">Setup</a>
                        </div>
                    </li>

                </ul>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-label">Administration</div>
                <ul class="sidebar-list">
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
                            <a href="{{ route('admin.unmapped.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.unmapped.*') ? 'is-active' : '' }}">Unmapped Columns</a>
                            <a href="{{ route('admin.tna.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.tna.*') ? 'is-active' : '' }}">TnA Templates</a>
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
                            <a href="{{ route('admin.supplier-options.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.supplier-options.*') ? 'is-active' : '' }}">Vendor Options</a>
                            <a href="{{ route('admin.buyers.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.buyers.*') ? 'is-active' : '' }}">Buyers</a>
                            <a href="{{ route('admin.booking-delivery-destinations.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.booking-delivery-destinations.*') ? 'is-active' : '' }}">Destinations</a>
                            <a href="{{ route('admin.booking-instructions.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.booking-instructions.*') ? 'is-active' : '' }}">Instructions</a>
                            <a href="{{ route('admin.po-generate-control.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.po-generate-control.*') ? 'is-active' : '' }}">PO Generate Control</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isAdminRequisitionSetup ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isAdminRequisitionSetup ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isAdminRequisitionSetup ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Requisition Setup</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isAdminRequisitionSetup ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('admin.departments.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.departments.*') ? 'is-active' : '' }}">Departments</a>
                            <a href="{{ route('admin.requisition-signatures.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.requisition-signatures.*') ? 'is-active' : '' }}">Requisition Signatures</a>
                            <a href="{{ route('store.stock.requisitions.window.edit') }}" class="sidebar-sub-link {{ request()->routeIs('store.stock.requisitions.window.*') ? 'is-active' : '' }}">Monthly Requisition Window</a>
                            <a href="{{ route('admin.item-sku.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.item-sku.*') ? 'is-active' : '' }}">Item SKU</a>
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
                            @php
                                // The approver pool belongs to the OLD check-and-approve process. It is
                                // listed only while it can matter: somebody is in the pool, new PRAs
                                // are set to use the old process, or an old-process PRA is still open.
                                try {
                                    $showPraPool = \App\Models\PraApprover::where('is_active', true)->exists()
                                        || ! \App\Support\PaymentRequestSettings::departmentalFlowEnabled()
                                        || \App\Models\PaymentRequest::where('flow_version', \App\Models\PaymentRequest::FLOW_LEGACY)
                                            ->whereIn('status', ['pending_check', 'pending_approval', 'rejected'])->exists()
                                        || request()->routeIs('admin.pra-approvers.*');
                                } catch (\Throwable $e) {
                                    $showPraPool = true;
                                }
                            @endphp
                            @if($showPraPool)
                                <a href="{{ route('admin.pra-approvers.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.pra-approvers.*') ? 'is-active' : '' }}">PRA Approvers</a>
                            @endif
                            <a href="{{ route('admin.pra-steps.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.pra-steps.*') || request()->routeIs('admin.pra-approvals.*') ? 'is-active' : '' }}">PRA Approval Steps</a>
                            {{-- Who approves each step, for all buyers or one buyer: its own menu entry. --}}
                            <a href="{{ route('admin.pra-routing.index') }}" class="sidebar-sub-link {{ request()->routeIs('admin.pra-routing.*') ? 'is-active' : '' }}">PRA Approval Routing</a>
                            <a href="{{ route('admin.email-templates.edit') }}" class="sidebar-sub-link {{ request()->routeIs('admin.email-templates.*') ? 'is-active' : '' }}">Email Templates</a>
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
                    @include('layouts._my-approvals-link', ['gate' => $seeRequisition])
                    @include('layouts._followup-link')
                    @include('layouts._bom-approval-link')
                    <li class="sidebar-item">
                        <a href="{{ route('merchant.styles.index') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.styles.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-diagram-3" aria-hidden="true"></i></span><span class="sidebar-link-text">Style Library</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('merchant.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                    <li class="sidebar-group {{ $isMerchDesk ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isMerchDesk ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isMerchDesk ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-chat-left-text" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Buyer Desk</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isMerchDesk ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('merchant.inquiries.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.inquiries.*') ? 'is-active' : '' }}">Inquiries</a>
                            <a href="{{ route('merchant.samples.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.samples.*') ? 'is-active' : '' }}">Samples</a>
                            <a href="{{ route('merchant.meetings.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.meetings.*') ? 'is-active' : '' }}">Meetings</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isMerchCosting ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isMerchCosting ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isMerchCosting ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-calculator" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Costing &amp; Materials</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isMerchCosting ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('merchant.consumption.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.consumption.*') ? 'is-active' : '' }}">Consumption Master</a>
                            <a href="{{ route('merchant.costing_templates.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.costing_templates.*') ? 'is-active' : '' }}">Costing Templates</a>
                            <a href="{{ route('merchant.vendors.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.vendors.*') ? 'is-active' : '' }}">Vendors</a>
                        </div>
                    </li>

                    <li class="sidebar-group {{ $isMerchReports ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $isMerchReports ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $isMerchReports ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Reports &amp; Status</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $isMerchReports ? 'is-open' : '' }}">
                            <span class="sidebar-submenu-rail"></span>
                            <a href="{{ route('merchant.status.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.status.*') ? 'is-active' : '' }}">Style Status</a>
                            <a href="{{ route('merchant.reports.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.reports.*') ? 'is-active' : '' }}">Reports</a>
                            <a href="{{ route('merchant.analytics.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.analytics.*') ? 'is-active' : '' }}">Analytics</a>
                            <a href="{{ route('merchant.ocr.index') }}" class="sidebar-sub-link {{ request()->routeIs('merchant.ocr.*') ? 'is-active' : '' }}">OCR Summary</a>
                            <a href="{{ route('store.reports.index') }}" class="sidebar-sub-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">Store Reports</a>
                        </div>
                    </li>

                    @include('layouts._requisition-nav-item')
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
                    @include('layouts._my-approvals-link', ['gate' => $seeRequisition])
                    @include('layouts._followup-link')
                    <li class="sidebar-item">
                        <a href="{{ route('account.payments.index') }}" class="sidebar-nav-link {{ request()->routeIs('account.payments.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><span class="sidebar-link-text">Payments</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('account.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('account.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                    @include('layouts._requisition-nav-item')
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
                    @include('layouts._my-approvals-link', ['gate' => $seeRequisition])
                    @include('layouts._followup-link')
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.shipments.index') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.shipments.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-truck" aria-hidden="true"></i></span><span class="sidebar-link-text">Inbound Shipments</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Reports</span></span>
                        </a>
                    </li>
                    @include('layouts._requisition-nav-item')
                </ul>
            </div>
            @endrole

            @if($seeStoreArea)
            {{-- No empty "Main Menu" heading for a requisition-only user, who
                 has neither link under it. --}}
            @if($seeStoreDashboard || $seeWorkspace)
            <div class="sidebar-section">
                <div class="sidebar-section-label">Main Menu</div>
                <ul class="sidebar-list">
                    @if($seeStoreDashboard)
                    <li class="sidebar-item">
                        <a href="{{ route('store.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('store.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span><span class="sidebar-link-text">Dashboard</span></span>
                        </a>
                    </li>
                    @endif
                    @include('layouts._my-approvals-link', ['gate' => $seeRequisition])
                    @if($seeWorkspace)
                    <li class="sidebar-item">
                        <a href="{{ route('store.workspace') }}" class="sidebar-nav-link {{ request()->routeIs('store.workspace') || request()->routeIs('uploaded-files.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-layout-text-window-reverse" aria-hidden="true"></i></span><span class="sidebar-link-text">Workspace</span></span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
            @if($seeGeneralStock)
            <div class="sidebar-section">
                <div class="sidebar-section-label">General Stock</div>
                <ul class="sidebar-list">
                    {{-- A requisition-only user has no Dashboard to sit under, so
                         My Approvals leads this list instead — still top level. --}}
                    @unless($seeStoreDashboard || $seeWorkspace)
                    @include('layouts._my-approvals-link', ['gate' => $seeRequisition])
                    @endunless
                    @if($seeRequisition)
                    {{-- Named in full to keep it distinct from the material
                         Requisitions under Buyer/Style Stock. --}}
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.requisitions.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.requisitions.*') && ! request()->routeIs('store.stock.requisitions.my-approvals') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span><span class="sidebar-link-text">Purchase Requisition</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeGsReceiving)
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.purchases.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.purchases.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-truck" aria-hidden="true"></i></span><span class="sidebar-link-text">Receiving</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeIssues)
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.issues.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.issues.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span><span class="sidebar-link-text">Issues</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeStockReport)
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.ledger') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.ledger*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span><span class="sidebar-link-text">Stock Report</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeItems)
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.items.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.items.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span class="sidebar-link-text">Items</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeSetup)
                    {{-- Purchase Setup and Issue Setup are one screen now. Their
                         old routes redirect here, so both spellings still
                         highlight this entry. --}}
                    <li class="sidebar-item">
                        <a href="{{ route('store.stock.setup') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.setup') || request()->routeIs('store.stock.purchase-setup.*') || request()->routeIs('store.stock.issue-setup.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span><span class="sidebar-link-text">Setup</span></span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
            @if($seeMaterialStock || $seeStoreReports)
            <div class="sidebar-section">
                <div class="sidebar-section-label">Buyer / Style Stock</div>
                <ul class="sidebar-list">
                    @if($seeClosingStock)
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.ledger') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.ledger') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></span><span class="sidebar-link-text">Closing Stock</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeBsReceiving)
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.receivings.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.receivings.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i></span><span class="sidebar-link-text">Receiving</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeBulkIssue)
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.bulk-issues.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.bulk-issues.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></span><span class="sidebar-link-text">Bulk Issuing</span></span>
                        </a>
                    </li>
                    @endif
                    @if($seeBsRequisitions)
                    <li class="sidebar-item">
                        <a href="{{ route('store.material.requisitions.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.material.requisitions.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span><span class="sidebar-link-text">Requisitions</span></span>
                        </a>
                    </li>
                    @endif
                    {{-- Stock Reports is style-wise stock data, so it belongs with
                         the other Buyer / Style screens rather than Main Menu. --}}
                    @if($seeStoreReports)
                    <li class="sidebar-item">
                        <a href="{{ route('store.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Reports</span></span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
            @endif

            {{-- General Stock purchasing desk: its own short menu, in the order the work flows. --}}
            {{-- Management approves Comparative Statements and Internal POs but never
                 raises them: no To Purchase, and the two lists only appear while
                 something is waiting for approval. --}}
            @php
                $purchasingForApprovalOnly = $storeUser?->hasRole('management') ?? false;
                $showCs = $seePurchaseCs && (! $purchasingForApprovalOnly || $csWaiting > 0 || request()->routeIs('store.purchasing.cs.*'));
                $showPo = $seePurchasePo && (! $purchasingForApprovalOnly || $poWaiting > 0 || request()->routeIs('store.purchasing.po.*'));
            @endphp
            @if($seePurchasing && ($showCs || $showPo))
            <div class="sidebar-section">
                <div class="sidebar-section-label">{{ $purchasingForApprovalOnly ? 'Waiting for Approval' : 'General Stock Purchasing' }}</div>
                <ul class="sidebar-list">
                    @if($seePurchaseCs && ! $purchasingForApprovalOnly)
                    <li class="sidebar-item">
                        <a href="{{ route('store.purchasing.queue') }}" class="sidebar-nav-link {{ request()->routeIs('store.purchasing.queue') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-cart-plus" aria-hidden="true"></i></span><span class="sidebar-link-text">To Purchase</span></span>
                        </a>
                    </li>
                    @endif
                    @if($showCs)
                    <li class="sidebar-item">
                        <a href="{{ route('store.purchasing.cs.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.purchasing.cs.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-columns-gap" aria-hidden="true"></i></span><span class="sidebar-link-text">Comparative Statement</span></span>
                            @if($csWaiting > 0)<span class="badge rounded-pill bg-danger">{{ $csWaiting > 99 ? '99+' : $csWaiting }}</span>@endif
                        </a>
                    </li>
                    @endif
                    @if($showPo)
                    <li class="sidebar-item">
                        <a href="{{ route('store.purchasing.po.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.purchasing.po.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span><span class="sidebar-link-text">Internal PO</span></span>
                            @if($poWaiting > 0)<span class="badge rounded-pill bg-danger">{{ $poWaiting > 99 ? '99+' : $poWaiting }}</span>@endif
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
            @endif

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
                    @include('layouts._my-approvals-link', ['gate' => $seeRequisition])
                    @include('layouts._followup-link')
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
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.supplier_delivery') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.supplier_delivery') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><span class="sidebar-link-text">Supplier Delivery</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Reports</span>
                            </span>
                        </a>
                    </li>
                    @include('layouts._requisition-nav-item')
                </ul>
            </div>
            @endrole

            {{-- MANAGEMENT. Same links as before, grouped by what a manager does:
                 decide, read reports, monitor, and (rarely) look into a
                 department's working screens. Groups open by themselves when
                 one of their pages is showing. --}}
            @role('management')
            @php
                $mgMonitor = request()->routeIs('merchant.status.*') || request()->routeIs('merchant.ocr.*') || request()->routeIs('merchant.analytics.*') || request()->routeIs('supply_chain.supplier_delivery') || request()->routeIs('commercial.shipments.*') || request()->routeIs('store.stock.ledger*');
            @endphp
            <div class="sidebar-section">
                <div class="sidebar-section-label">Overview &amp; Approvals</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('management.dashboard') }}" class="sidebar-nav-link {{ request()->routeIs('management.dashboard') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Dashboard</span>
                            </span>
                        </a>
                    </li>
                    @include('layouts._my-approvals-link', [])
                    @include('layouts._bom-approval-link')
                    @include('layouts._followup-link')
                </ul>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-label">Reports</div>
                <ul class="sidebar-list">
                    <li class="sidebar-item">
                        <a href="{{ route('merchant.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Merchandising Reports</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('supply_chain.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Supply Chain Reports</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('commercial.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Commercial Reports</span></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="{{ route('store.reports.index') }}" class="sidebar-nav-link {{ request()->routeIs('store.reports.*') ? 'is-active' : '' }}">
                            <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span><span class="sidebar-link-text">Store Reports</span></span>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="sidebar-section">
                <div class="sidebar-section-label">Monitor</div>
                <ul class="sidebar-list">
                    <li class="sidebar-group {{ $mgMonitor ? 'is-open' : '' }}">
                        <button type="button" class="sidebar-group-button {{ $mgMonitor ? 'is-active' : '' }}" data-sidebar-group-toggle aria-expanded="{{ $mgMonitor ? 'true' : 'false' }}">
                            <span class="sidebar-link-main">
                                <span class="sidebar-icon"><i class="bi bi-activity" aria-hidden="true"></i></span>
                                <span class="sidebar-link-text">Order &amp; Delivery Status</span>
                            </span>
                            <span class="sidebar-chevron"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </button>
                        <div class="sidebar-submenu {{ $mgMonitor ? 'is-open' : '' }}">
                            <ul class="sidebar-list">
                            <li class="sidebar-item">
                                <a href="{{ route('merchant.status.index') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.status.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-bar-chart-steps" aria-hidden="true"></i></span><span class="sidebar-link-text">Style Status</span></span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a href="{{ route('merchant.ocr.index') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.ocr.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-table" aria-hidden="true"></i></span><span class="sidebar-link-text">OCR Summary</span></span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a href="{{ route('merchant.analytics.index') }}" class="sidebar-nav-link {{ request()->routeIs('merchant.analytics.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span><span class="sidebar-link-text">Analytics</span></span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a href="{{ route('supply_chain.supplier_delivery') }}" class="sidebar-nav-link {{ request()->routeIs('supply_chain.supplier_delivery') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><span class="sidebar-link-text">Supplier Delivery</span></span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a href="{{ route('commercial.shipments.index') }}" class="sidebar-nav-link {{ request()->routeIs('commercial.shipments.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-truck" aria-hidden="true"></i></span><span class="sidebar-link-text">Inbound Shipments</span></span>
                                </a>
                            </li>
                            <li class="sidebar-item">
                                <a href="{{ route('store.stock.ledger') }}" class="sidebar-nav-link {{ request()->routeIs('store.stock.ledger*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-journal-text" aria-hidden="true"></i></span><span class="sidebar-link-text">Stock Report</span></span>
                                </a>
                            </li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </div>
            @endrole

            {{-- TEAM MANAGEMENT — ONE ENTRY, OUTSIDE EVERY ROLE BLOCK.
                 It used to be an <li> copied inside the Merchant block and
                 again inside the Store block, which meant the flag was right
                 and the link was still unreachable for everyone else: a Supply
                 Chain, Accounts or Commercial department admin renders neither
                 of those blocks and so never saw it, however plainly
                 is_department_admin said they should. Adding a third copy
                 would have fixed one department and left the other two.

                 So it sits here instead, beside Approvals — the other entry
                 that belongs to a person rather than to a department.

                 Admin is excluded on purpose: it already has "Users & Roles"
                 pointing at this very screen, and burying the link inside role
                 blocks was the original way of keeping it out of that menu. --}}
            @if($seeTeamManagement && ! ($storeUser?->hasRole('admin') ?? false))
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Team</div>
                    <ul class="sidebar-list">
                        <li class="sidebar-item">
                            <a href="{{ route('admin.users.index') }}" class="sidebar-nav-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
                                <span class="sidebar-link-main"><span class="sidebar-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span class="sidebar-link-text">Team Management</span></span>
                            </a>
                        </li>
                    </ul>
                </div>
            @endif

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
                    // The OLD check-and-approve queue is shown only to someone who has (or ever
                    // had) a PRA on that flow. Everybody else would see two entries both
                    // called "PRA Approvals" - this one and the departmental queue below -
                    // for a flow that no new PRA uses.
                    $showLegacyPraQueue = $praPendingCount > 0
                        || \App\Models\PraApproval::where('approver_id', $praUid)->where('sequence', \App\Models\PraApproval::SEQUENCE_LEGACY)->exists();
                @endphp
                @if($showLegacyPraQueue || auth()->user()->hasRole('admin'))
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Approvals</div>
                    <ul class="sidebar-list">
                        @if($showLegacyPraQueue)
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
                        @endif
                        {{-- The admin's oversight of the departmental chain: every
                             flow-2 PRA, read-only. Admin has no step of its own,
                             so it gets this list and not the My Step queue. --}}
                        @role('admin')
                            <li class="sidebar-item">
                                <a href="{{ route('pra_departmental.all') }}" class="sidebar-nav-link {{ request()->routeIs('pra_departmental.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main">
                                        <span class="sidebar-icon"><i class="bi bi-collection" aria-hidden="true"></i></span>
                                        <span class="sidebar-link-text">All Departmental PRAs</span>
                                    </span>
                                </a>
                            </li>
                        @endrole
                    </ul>
                </div>
                @endif
            @endcan

            {{-- The same oversight link for an admin who does not hold approve-pra
                 (and so has no Approvals section above to carry it). --}}
            @if(auth()->user()?->hasRole('admin') && ! auth()->user()->can('approve-pra'))
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Approvals</div>
                    <ul class="sidebar-list">
                        <li class="sidebar-item">
                            <a href="{{ route('pra_departmental.all') }}" class="sidebar-nav-link {{ request()->routeIs('pra_departmental.*') ? 'is-active' : '' }}">
                                <span class="sidebar-link-main">
                                    <span class="sidebar-icon"><i class="bi bi-collection" aria-hidden="true"></i></span>
                                    <span class="sidebar-link-text">All Departmental PRAs</span>
                                </span>
                            </a>
                        </li>
                    </ul>
                </div>
            @endif

            {{-- The departmental chain's own queue. A SEPARATE ENTRY from the
                 legacy one above, matching the separate permission: the two
                 flows have different access rules and merging the links would
                 imply they are one list. Admin is excluded because it holds both
                 permissions and would otherwise see two inboxes.

                 The badge is PraQueueCount: exactly the PRAs waiting on this user
                 (the same test as the queue), cached for 30 seconds per user so
                 it is cheap on every page. --}}
            @if(auth()->user() && ! auth()->user()->hasRole('admin') && auth()->user()->can('pra.departmental.act'))
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Approvals</div>
                    <ul class="sidebar-list">
                        <li class="sidebar-item">
                            @php $praQueueCount = \App\Support\PraQueueCount::forUser(auth()->user()); @endphp
                            <a href="{{ route('pra_departmental.index') }}" class="sidebar-nav-link {{ request()->routeIs('pra_departmental.*') ? 'is-active' : '' }}">
                                <span class="sidebar-link-main">
                                    <span class="sidebar-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span>
                                    <span class="sidebar-link-text">PRA Approvals</span>
                                </span>
                                {{-- Same badge as the other approval queues: how many PRAs are
                                     waiting on this person at their step. --}}
                                @if($praQueueCount > 0)
                                    <span class="badge rounded-pill bg-danger">{{ $praQueueCount > 99 ? '99+' : $praQueueCount }}</span>
                                @endif
                            </a>
                        </li>
                    </ul>
                </div>
            @endif

            {{-- Template maintenance, for whoever holds the permission without
                 being an admin — today the Supply Chain role. Admin is excluded
                 here because Booking Setup > Instructions and Settings > Email
                 Templates already carry these two links in the admin block
                 above, and Gate::before would otherwise draw them twice. --}}
            @if(auth()->user() && ! auth()->user()->hasRole('admin') && auth()->user()->canAny(['booking.instructions.manage', 'email.templates.edit']))
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Templates</div>
                    <ul class="sidebar-list">
                        @can('booking.instructions.manage')
                            <li class="sidebar-item">
                                <a href="{{ route('admin.booking-instructions.index') }}" class="sidebar-nav-link {{ request()->routeIs('admin.booking-instructions.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main">
                                        <span class="sidebar-icon"><i class="bi bi-card-list" aria-hidden="true"></i></span>
                                        <span class="sidebar-link-text">PO Instructions</span>
                                    </span>
                                </a>
                            </li>
                        @endcan
                        @can('email.templates.edit')
                            <li class="sidebar-item">
                                <a href="{{ route('admin.email-templates.edit') }}" class="sidebar-nav-link {{ request()->routeIs('admin.email-templates.*') ? 'is-active' : '' }}">
                                    <span class="sidebar-link-main">
                                        <span class="sidebar-icon"><i class="bi bi-envelope-paper" aria-hidden="true"></i></span>
                                        <span class="sidebar-link-text">Email Templates</span>
                                    </span>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </div>
            @endif

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

        {{-- Collapse control. Desktop only: below lg the sidebar is already a
             slide-over with its own close button, and two competing hide
             mechanisms would fight each other.

             Carries a visible "Collapse Menu" label while expanded. Once
             collapsed there is no room for text, so it falls back to the same
             hover-label the icon rail uses — the established convention for a
             collapsed rail, not a bare unlabelled action button. --}}
        <div class="sidebar-footer d-none d-lg-block">
            <button type="button" class="sidebar-collapse-btn" id="sidebarCollapseToggle"
                    data-tip="Expand Menu" aria-expanded="true" aria-controls="appSidebar">
                <span class="sidebar-icon"><i class="bi bi-chevron-double-left" aria-hidden="true"></i></span>
                <span class="sidebar-link-text">Collapse Menu</span>
            </button>
        </div>
    </div>
</nav>
