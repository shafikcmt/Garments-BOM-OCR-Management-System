<nav class="navbar navbar-expand-lg navbar-light bg-light shadow-sm fixed-top">
    <div class="container-fluid">
        <!-- Logo & Title -->
        <a class="navbar-brand d-flex align-items-center" href="#">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" style="height:40px;" class="me-2">
            
        </a>

        <!-- Mobile toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topNavbar" aria-controls="topNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Nav items -->
        <div class="collapse navbar-collapse" id="topNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <!-- Dashboard -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="dashboardDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="dashboardDropdown">
                        <li><span class="dropdown-item"><i class="bi bi-bar-chart-line me-1"></i> Overview</span></li>
                        <li><span class="dropdown-item"><i class="bi bi-exclamation-triangle me-1"></i> Alerts & Exceptions</span></li>
                        <li><span class="dropdown-item"><i class="bi bi-file-earmark-bar-graph me-1"></i> Reports & Analytics</span></li>
                    </ul>
                </li>

                <!-- Orders -->
                @role('merchant|admin')
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="ordersDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bag-check me-1"></i> Orders
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="ordersDropdown">
                        <li><span class="dropdown-item">Buyer Orders</span></li>
                        <li><span class="dropdown-item">Styles & Seasons</span></li>
                        <li><span class="dropdown-item">Production Status</span></li>
                    </ul>
                </li>
                @endrole

                <!-- Requisition -->
                @role('supply_chain|admin')
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="materialsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-box-seam me-1"></i> Requisition Management
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="materialsDropdown">
                        <li><span class="dropdown-item">Material Requisition</span></li>
                        <li><span class="dropdown-item">Vendors & PI</span></li>
                        <li><span class="dropdown-item">Consumption</span></li>
                        <li><span class="dropdown-item">Inbound Shipments</span></li>
                    </ul>
                </li>
                @endrole

                <!-- OCR -->
                @role('merchant|commercial|admin')
                <li class="nav-item">
                    <span class="nav-link d-flex align-items-center">
                        <i class="bi bi-file-earmark-text me-1"></i> OCR Management
                    </span>
                </li>
                @endrole

                <!-- Accounts -->
                @role('account|admin')
                <li class="nav-item">
                    <span class="nav-link d-flex align-items-center">
                        <i class="bi bi-cash-stack me-1"></i> Accounts & Finance
                    </span>
                </li>
                @endrole

                <!-- Store -->
                @role('store|admin')
                <li class="nav-item">
                    <span class="nav-link d-flex align-items-center">
                        <i class="bi bi-shop me-1"></i> Store & Inventory
                    </span>
                </li>
                @endrole

                <!-- HR -->
                @role('admin')
                <li class="nav-item">
                    <span class="nav-link d-flex align-items-center">
                        <i class="bi bi-people me-1"></i> HR & Leave Management
                    </span>
                </li>
                @endrole

            </ul>

            <!-- Right side profile -->
            <div class="d-flex align-items-center">
                <a href="#" class="me-3"><i class="bi bi-bell fs-5"></i></a>
                <a href="#" class="me-3"><i class="bi bi-chat-left-text fs-5"></i></a>

                <div class="dropdown profile-wrapper">
                    <img src="{{ auth()->user()->gender=='male' ? 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTxz7qJ9pU6Xj2EJKaRDVz-9Bd0xh2LnMklGw&s' : 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNJryFTSQUV8Zuu_EGw2iUCpMbIIKWHBl2eQ&s' }}" alt="Profile" class="rounded-circle" style="height:42px; width:42px; cursor:pointer;" data-bs-toggle="dropdown">
                    
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item">Designation: {{ auth()->user()->designation ?? 'N/A' }}</span></li>
                        <li><span class="dropdown-item">Name: {{ auth()->user()->name }}</span></li>
                        <li><a class="dropdown-item" href="#">Profile</a></li>
                        <li><a class="dropdown-item" href="{{ route('logout') }}">Logout</a></li>
                    </ul>
                </div>

            </div>
        </div>
    </div>
</nav>
