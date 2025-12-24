<nav class="sidebar shadow" style="background-color:#151E3F; color:#fff;">
    {{-- Logo --}}
    <div class="sidebar-logo d-flex align-items-center justify-content-center p-3 border-bottom">
        <img src="{{ asset('images/logo.png') }}" height="37">
    </div>

    {{-- Menu --}}
    <div class="sidebar-menu" style="overflow-y:auto;height:calc(100vh - 70px);">
        <ul class="nav flex-column" id="sidebarMenu">

            {{-- ================= DASHBOARD ================= --}}
            <li class="nav-item">
                <a class="nav-link d-flex align-items-center text-white"
                   data-bs-toggle="collapse"
                   href="#dashboardMenu">
                    <span>
                        <i class="bi bi-speedometer2 me-2"></i> Dashboard
                    </span>
                    <span class="ms-auto"><i class="bi bi-chevron-down"></i></span>
                </a>

                <div class="collapse" id="dashboardMenu" data-bs-parent="#sidebarMenu">
                    <ul class="nav flex-column ps-4">
                        <li class="nav-item"><span class="nav-link text-white">Overview</span></li>
                        <li class="nav-item"><span class="nav-link text-white">Reports</span></li>
                    </ul>
                </div>
            </li>

            {{-- ================= OCR MANAGEMENT ================= --}}
            @role('merchant|commercial|admin')
            <li class="nav-item">

                {{-- OCR Parent --}}
                <a class="nav-link d-flex align-items-center text-white"
                   data-bs-toggle="collapse"
                   href="#ocrMenu">
                    <span>
                        <i class="bi bi-file-earmark-text me-2"></i> OCR Management
                    </span>
                    <span class="ms-auto"><i class="bi bi-chevron-down"></i></span>
                </a>

                {{-- OCR Sub Menu --}}
                <div class="collapse {{ request()->routeIs(
                        'merchant.orders.*',
                        'admin.orders.*',
                        'admin.fields.*'
                    ) ? 'show' : '' }}"
                     id="ocrMenu"
                     data-bs-parent="#sidebarMenu">

                    <ul class="nav flex-column ps-3">

                        {{-- MERCHANT --}}
                        @role('merchant')
                        <li class="nav-item">
                            <a class="nav-link text-white"
                               href="{{ route('merchant.orders.index') }}">
                               <i class="bi bi-shop-window me-2"></i> Marketing Plan
                            </a>
                        </li>
                         
                        @endrole

                        {{-- COMMERCIAL --}}
                        @role('commercial')
                        <li class="nav-item">
                            <a class="nav-link text-white"
                               href="{{ route('merchant.orders.index') }}">
                                <i class="bi bi-bag-check me-2"></i> Orders
                            </a>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link text-white">
                                <i class="bi bi-bar-chart-line me-2"></i> Reports
                            </span>
                        </li>
                        @endrole

                        {{-- ADMIN --}}
                        @role('admin')
                        <li class="nav-item">
                            <a class="nav-link text-white"
                               href="{{ route('admin.orders.index') }}">
                                <i class="bi bi-bag-check me-2"></i> All Orders
                            </a>
                        </li>
                         <li class="nav-item">
                                        <a class="nav-link text-white"
                                           href="{{ route('admin.fields.index') }}">
                                            <i class="bi bi-list-check me-2"></i> Sections List
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link text-white"
                                           href="{{ route('admin.fields.create') }}">
                                            <i class="bi bi-list-check me-2"></i> Add New Section
                                        </a>
                                    </li>

                        
                        @endrole

                    </ul>
                </div>
            </li>
            @endrole

            {{-- ================= OTHER MODULES ================= --}}
            @role('admin')
            <li class="nav-item">
                <span class="nav-link text-white">
                    <i class="bi bi-gear me-2"></i> System Settings
                </span>
            </li>
            @endrole

        </ul>
    </div>
</nav>
