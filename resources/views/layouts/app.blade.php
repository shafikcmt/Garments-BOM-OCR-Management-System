<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Humana Apparels Pvt. Ltd | OCR Management')</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- Bundled by Vite: Tailwind (preflight off), Bootstrap Icons, design
         tokens and the application component styles. Loaded after Bootstrap so
         the component styles keep overriding it. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @yield('styles')
</head>
<body>
    {{-- First thing a keyboard user reaches, so they can jump past the
         sidebar's nav links instead of tabbing through all of them. --}}
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="app-sidebar-backdrop" id="sidebarBackdrop"></div>

    @include('layouts.sidebar')
    @include('layouts.navbar')

    <main class="content" id="main-content" tabindex="-1">
        <div class="app-flash-wrapper">
            @if(session('success'))
                <div class="app-flash-alert app-flash-success" role="alert">
                    <div class="app-flash-icon"><i class="bi bi-check-lg" aria-hidden="true"></i></div>
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
                    <div class="app-flash-icon"><i class="bi bi-exclamation-lg" aria-hidden="true"></i></div>
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
                    <div class="app-flash-icon"><i class="bi bi-exclamation-lg" aria-hidden="true"></i></div>
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

        @include('layouts.footer')
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

            // --- Collapsible sidebar (desktop) ---------------------------------
            // Only the body class is toggled; every width in the shell already
            // derives from --app-sidebar, so the layout follows on its own.
            const COLLAPSE_KEY = 'sidebarCollapsed';
            const collapseToggle = document.getElementById('sidebarCollapseToggle');
            const isDesktop = () => window.matchMedia('(min-width: 992px)').matches;

            // Hover labels for the collapsed rail, taken from each item's own
            // text. Reading the DOM means role-filtered menus label themselves
            // and no list has to be maintained alongside the Blade.
            document.querySelectorAll('.sidebar-nav-link, .sidebar-group-button').forEach(function (item) {
                const label = item.querySelector('.sidebar-link-text')?.textContent.trim();
                if (label) item.setAttribute('data-tip', label);
            });

            function applyCollapsed(collapsed) {
                document.body.classList.toggle('sidebar-collapsed', collapsed);

                if (!collapseToggle) return;
                collapseToggle.setAttribute('aria-expanded', String(!collapsed));
                collapseToggle.querySelector('.sidebar-link-text').textContent =
                    collapsed ? 'Expand Menu' : 'Collapse Menu';
                collapseToggle.setAttribute('data-tip', 'Expand Menu');
            }

            function setCollapsed(collapsed) {
                applyCollapsed(collapsed);
                try {
                    localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
                } catch (e) {
                    // Private mode / quota. The choice simply will not persist.
                }
            }

            function storedCollapsed() {
                try {
                    return localStorage.getItem(COLLAPSE_KEY) === '1';
                } catch (e) {
                    return false;
                }
            }

            // Restore the saved choice, but never on a narrow screen where the
            // sidebar is a slide-over instead.
            if (isDesktop() && storedCollapsed()) applyCollapsed(true);

            collapseToggle?.addEventListener('click', function () {
                setCollapsed(!document.body.classList.contains('sidebar-collapsed'));
            });

            // Crossing the lg boundary: the rail only exists on desktop, so the
            // class is dropped below it and restored on the way back up.
            window.matchMedia('(min-width: 992px)').addEventListener('change', function (e) {
                applyCollapsed(e.matches && storedCollapsed());
            });

            document.querySelectorAll('[data-sidebar-group-toggle]').forEach(function (toggle) {
                toggle.addEventListener('click', function () {
                    const group = this.closest('.sidebar-group');
                    const submenu = group?.querySelector('.sidebar-submenu');
                    if (!group || !submenu) return;

                    // A submenu cannot open inside the collapsed rail, so the
                    // first click expands the sidebar and opens the group there
                    // rather than appearing to do nothing.
                    if (document.body.classList.contains('sidebar-collapsed')) {
                        setCollapsed(false);
                        group.classList.add('is-open');
                        submenu.classList.add('is-open');
                        this.setAttribute('aria-expanded', 'true');
                        return;
                    }

                    const isOpen = group.classList.contains('is-open');

                    document.querySelectorAll('.sidebar-group').forEach(function (item) {
                        if (item === group) return;
                        item.classList.remove('is-open');
                        item.querySelector('.sidebar-submenu')?.classList.remove('is-open');
                        item.querySelector('[data-sidebar-group-toggle]')?.setAttribute('aria-expanded', 'false');
                    });

                    group.classList.toggle('is-open', !isOpen);
                    submenu.classList.toggle('is-open', !isOpen);
                    this.setAttribute('aria-expanded', String(!isOpen));
                });
            });

            function syncSidebarHashActive() {
                const currentHash = window.location.hash.replace('#', '');
                const hashLinks = document.querySelectorAll('.sidebar-sub-link[data-hash]');
                if (!hashLinks.length) return;

                hashLinks.forEach(function (link) {
                    link.classList.remove('is-active', 'hash-active');
                });

                let matched = false;
                hashLinks.forEach(function (link) {
                    const targetHash = link.dataset.hash;
                    const linkUrl = new URL(link.href, window.location.origin);
                    const samePath = linkUrl.pathname === window.location.pathname;
                    if (samePath && currentHash && targetHash === currentHash) {
                        matched = true;
                        link.classList.add('is-active', 'hash-active');
                        const group = link.closest('.sidebar-group');
                        group?.classList.add('is-open');
                        group?.querySelector('.sidebar-submenu')?.classList.add('is-open');
                        group?.querySelector('[data-sidebar-group-toggle]')?.setAttribute('aria-expanded', 'true');
                    }
                });

                if (!matched && !currentHash) {
                    const currentPath = window.location.pathname;
                    const defaultLink = Array.from(hashLinks).find(function (link) {
                        const linkUrl = new URL(link.href, window.location.origin);
                        return linkUrl.pathname === currentPath && link.dataset.hash === 'pending-generated-po';
                    });
                    defaultLink?.classList.add('is-active');
                }
            }

            syncSidebarHashActive();
            window.addEventListener('hashchange', syncSidebarHashActive);

            document.querySelectorAll('.content a.btn, .content button.btn, .content .generated-po-action').forEach(function (action) {
                let hasDirectIcon = Array.from(action.children).some(function (child) {
                    return child.classList && child.classList.contains('bi');
                });
                const label = (action.textContent || '').replace(/\s+/g, ' ').trim();
                const normalizedLabel = label.toLowerCase();

                if (!hasDirectIcon && (normalizedLabel === 'edit' || normalizedLabel === 'delete')) {
                    const icon = document.createElement('i');
                    icon.className = normalizedLabel === 'edit' ? 'bi bi-pencil-square' : 'bi bi-trash';
                    action.prepend(icon);
                    hasDirectIcon = true;
                }

                if (!hasDirectIcon) return;

                if (label && !action.getAttribute('title')) {
                    action.setAttribute('title', label);
                }
                if (label && !action.getAttribute('aria-label')) {
                    action.setAttribute('aria-label', label);
                }
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
