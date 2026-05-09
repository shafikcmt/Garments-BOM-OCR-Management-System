<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Humana Apparels Pvt. Ltd | OCR Management')</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            corePlugins: { preflight: false },
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] },
                    colors: {
                        brand: {
                            50: '#eff8ff',
                            100: '#dff1ff',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            900: '#0b3158'
                        }
                    }
                }
            }
        };
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --app-sidebar: 280px;
            --app-header: 76px;
            --app-primary: #0b6f9e;
            --app-primary-dark: #084d70;
            --app-bg: #f5f9fc;
            --app-border: #e2edf6;
            --app-text: #0f172a;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(14, 165, 233, .14), transparent 34rem),
                linear-gradient(180deg, #f8fbff 0%, var(--app-bg) 45%, #eef6fb 100%);
            color: var(--app-text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 13px;
        }

        a { text-decoration: none; }

        .app-sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .42);
            backdrop-filter: blur(2px);
            opacity: 0;
            visibility: hidden;
            z-index: 1015;
            transition: .2s ease;
        }

        .app-sidebar-backdrop.show {
            opacity: 1;
            visibility: visible;
        }

        .sidebar {
            width: var(--app-sidebar);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow: hidden;
            z-index: 1020;
            color: #fff;
            background:
                radial-gradient(circle at 20% 5%, rgba(56, 189, 248, .26), transparent 14rem),
                linear-gradient(180deg, #082f49 0%, #0f172a 100%);
            border-right: 1px solid rgba(255,255,255,.10);
            box-shadow: 18px 0 45px rgba(8, 47, 73, .22);
            transition: transform .28s ease;
        }

        .sidebar-logo {
            min-height: 76px;
            padding: 16px 18px;
            border-bottom: 1px solid rgba(255,255,255,.10) !important;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            background: linear-gradient(135deg, #38bdf8, #2563eb);
            color: #fff;
            box-shadow: 0 16px 30px rgba(37, 99, 235, .32);
        }

        .sidebar-menu {
            height: calc(100vh - 76px);
            overflow-y: auto;
            padding: 14px 12px 26px;
        }

        .sidebar-menu::-webkit-scrollbar { width: 7px; }
        .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, .35); border-radius: 999px; }

        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            min-height: 43px;
            margin: 4px 0;
            padding: 11px 13px;
            border-radius: 14px;
            color: rgba(226, 232, 240, .86) !important;
            font-weight: 650;
            letter-spacing: -.01em;
            border: 1px solid transparent;
            transition: .2s ease;
        }

        .sidebar .nav-link i {
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 11px;
            background: rgba(255,255,255,.08);
            color: #bae6fd;
        }

        .sidebar .nav-link span {
            flex: 1 1 auto;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #fff !important;
            background: rgba(255,255,255,.12);
            border-color: rgba(255,255,255,.14);
            transform: translateX(3px);
        }

        .sidebar .nav-link.active i {
            background: linear-gradient(135deg, #38bdf8, #2563eb);
            color: #fff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, .26);
        }

        .header {
            height: var(--app-header);
            position: fixed;
            top: 0;
            left: var(--app-sidebar);
            width: calc(100% - var(--app-sidebar));
            z-index: 1010;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 0 24px;
            background: rgba(255,255,255,.82);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(226, 237, 246, .95);
            box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
        }

        .content {
            min-height: 100vh;
            margin-top: var(--app-header);
            margin-left: var(--app-sidebar);
            padding: 22px;
        }

        .app-page-card {
            background: rgba(255, 255, 255, .88);
            border: 1px solid rgba(226, 237, 246, .96);
            border-radius: 24px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .07);
        }

        .app-icon-btn {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            border: 1px solid #dbeafe;
            background: #fff;
            color: #0369a1;
            transition: .2s ease;
        }

        .app-icon-btn:hover {
            background: #eff6ff;
            color: #075985;
            transform: translateY(-1px);
        }

        .profile-wrapper { position: relative; }

        .profile-img {
            height: 44px;
            width: 44px;
            object-fit: cover;
            border-radius: 16px;
            cursor: pointer;
            border: 2px solid #fff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .16);
            transition: .2s ease;
        }

        .profile-img:hover { transform: translateY(-1px) scale(1.02); }

        .profile-card {
            position: absolute;
            right: 0;
            top: 58px;
            width: 260px;
            background: #fff;
            color: #334155;
            border: 1px solid #e2edf6;
            border-radius: 22px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .18);
            padding: 12px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(8px);
            transition: .2s ease;
            z-index: 2000;
        }

        .profile-wrapper:hover .profile-card,
        .profile-card:hover {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .profile-card .dropdown-item {
            padding: 10px 12px;
            border-radius: 14px;
            font-size: 13px;
            color: #334155;
            font-weight: 600;
        }

        .profile-card .dropdown-item:hover { background: #f1f5f9; color: #0f172a; }

        .table {
            --bs-table-color: #0f172a;
            --bs-table-border-color: #e5eef7;
        }

        .table th { font-weight: 800; letter-spacing: -.01em; }
        .table td { vertical-align: middle; }
        .form-control, .form-select { border-color: #d7e4ef; border-radius: 14px; }
        .form-control:focus, .form-select:focus { border-color: #38bdf8; box-shadow: 0 0 0 .2rem rgba(14, 165, 233, .12); }
        .btn { border-radius: 14px; font-weight: 700; }
        .badge { letter-spacing: -.01em; }

        .app-flash-wrapper {
            position: fixed;
            top: 92px;
            right: 24px;
            width: min(430px, calc(100vw - 32px));
            z-index: 9999;
        }

        .app-flash-alert {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px 16px;
            border-radius: 20px;
            border: 1px solid transparent;
            background: #fff;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.16);
            overflow: hidden;
            animation: appFlashIn .32s ease-out;
        }

        .app-flash-alert + .app-flash-alert { margin-top: 10px; }
        .app-flash-alert::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; }

        .app-flash-icon {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            color: #fff;
            font-size: 18px;
            font-weight: 800;
        }

        .app-flash-content { flex: 1; min-width: 0; }
        .app-flash-title { margin: 0 0 3px; font-size: 15px; font-weight: 800; line-height: 1.25; }
        .app-flash-text { margin: 0; font-size: 13px; line-height: 1.5; }
        .app-flash-close { border: 0; background: transparent; width: 28px; height: 28px; border-radius: 10px; font-size: 20px; line-height: 1; display: inline-flex; align-items: center; justify-content: center; transition: .2s ease; }
        .app-flash-success { border-color: #bbf7d0; background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); }
        .app-flash-success::before { background: linear-gradient(180deg, #22c55e, #10b981); }
        .app-flash-success .app-flash-icon { background: linear-gradient(135deg, #22c55e, #10b981); }
        .app-flash-success .app-flash-title { color: #065f46; }
        .app-flash-success .app-flash-text { color: #166534; }
        .app-flash-success .app-flash-close { color: #047857; }
        .app-flash-success .app-flash-close:hover { background: rgba(34, 197, 94, .12); }
        .app-flash-error { border-color: #fecaca; background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%); }
        .app-flash-error::before { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .app-flash-error .app-flash-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .app-flash-error .app-flash-title { color: #991b1b; }
        .app-flash-error .app-flash-text { color: #b91c1c; }
        .app-flash-error .app-flash-close { color: #b91c1c; }
        .app-flash-error .app-flash-close:hover { background: rgba(239, 68, 68, .12); }
        .app-flash-progress { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: rgba(148, 163, 184, .15); }
        .app-flash-progress span { display: block; height: 100%; width: 100%; transform-origin: left center; animation: appFlashTimer 5s linear forwards; }
        .app-flash-success .app-flash-progress span { background: linear-gradient(90deg, #22c55e, #10b981); }
        .app-flash-error .app-flash-progress span { background: linear-gradient(90deg, #ef4444, #dc2626); }

        @keyframes appFlashIn { from { opacity: 0; transform: translateX(20px) scale(.98); } to { opacity: 1; transform: translateX(0) scale(1); } }
        @keyframes appFlashOut { from { opacity: 1; transform: translateX(0) scale(1); } to { opacity: 0; transform: translateX(20px) scale(.98); } }
        @keyframes appFlashTimer { from { transform: scaleX(1); } to { transform: scaleX(0); } }

        @media (max-width: 991.98px) {
            :root { --app-sidebar: 280px; --app-header: 70px; }
            .sidebar { transform: translateX(-105%); }
            .sidebar.show { transform: translateX(0); }
            .header { left: 0; width: 100%; padding: 0 16px; }
            .content { margin-left: 0; padding: 16px; }
            .app-flash-wrapper { top: 82px; right: 16px; }
        }
    </style>
    @yield('styles')
</head>
<body>
    <div class="app-sidebar-backdrop" id="sidebarBackdrop"></div>

    @include('layouts.sidebar')
    @include('layouts.navbar')

    <main class="content">
        <div class="app-flash-wrapper">
            @if(session('success'))
                <div class="app-flash-alert app-flash-success" role="alert">
                    <div class="app-flash-icon"><i class="bi bi-check-lg"></i></div>
                    <div class="app-flash-content">
                        <h6 class="app-flash-title">Success</h6>
                        <p class="app-flash-text">{!! session('success') !!}</p>
                    </div>
                    <button type="button" class="app-flash-close" aria-label="Close message">&times;</button>
                    <div class="app-flash-progress"><span></span></div>
                </div>
            @endif

            @if(session('error'))
                <div class="app-flash-alert app-flash-error" role="alert">
                    <div class="app-flash-icon"><i class="bi bi-exclamation-lg"></i></div>
                    <div class="app-flash-content">
                        <h6 class="app-flash-title">Error</h6>
                        <p class="app-flash-text">{!! session('error') !!}</p>
                    </div>
                    <button type="button" class="app-flash-close" aria-label="Close message">&times;</button>
                    <div class="app-flash-progress"><span></span></div>
                </div>
            @endif

            @if(session('import_errors'))
                <div class="app-flash-alert app-flash-error" role="alert">
                    <div class="app-flash-icon"><i class="bi bi-exclamation-lg"></i></div>
                    <div class="app-flash-content">
                        <h6 class="app-flash-title">Import Error</h6>
                        <div class="app-flash-text">
                            <ul class="mb-0 ps-3">
                                @foreach(session('import_errors') as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="app-flash-close" aria-label="Close message">&times;</button>
                    <div class="app-flash-progress"><span></span></div>
                </div>
            @endif
        </div>

        @yield('content')
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.querySelector('.sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarClose = document.getElementById('sidebarClose');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');

            function openSidebar() {
                sidebar?.classList.add('show');
                sidebarBackdrop?.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            function closeSidebar() {
                sidebar?.classList.remove('show');
                sidebarBackdrop?.classList.remove('show');
                document.body.style.overflow = '';
            }

            sidebarToggle?.addEventListener('click', openSidebar);
            sidebarClose?.addEventListener('click', closeSidebar);
            sidebarBackdrop?.addEventListener('click', closeSidebar);

            document.querySelectorAll('#sidebarMenu a[data-bs-toggle="collapse"]').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (!target) return;
                    document.querySelectorAll('#sidebarMenu .collapse').forEach(collapse => {
                        if (collapse !== target) collapse.classList.remove('show');
                    });
                    target.classList.toggle('show');
                });
            });

            document.querySelectorAll('.app-flash-alert').forEach(function (flash) {
                const closeBtn = flash.querySelector('.app-flash-close');
                function hideFlash() {
                    flash.style.animation = 'appFlashOut .3s ease forwards';
                    setTimeout(() => flash.remove(), 280);
                }
                const timer = setTimeout(hideFlash, 5000);
                closeBtn?.addEventListener('click', function () {
                    clearTimeout(timer);
                    hideFlash();
                });
            });
        });
    </script>
    @yield('scripts')
</body>
</html>
