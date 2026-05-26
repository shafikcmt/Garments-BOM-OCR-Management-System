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
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui'] }
                }
            }
        };
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --app-sidebar: 260px;
            --app-header: 60px;
            --app-shell-bg: #f3f6fb;
            --app-border: #dbe7f3;
            --app-border-strong: #cad8e7;
            --app-ink: #0f172a;
            --app-muted: #64748b;
            --app-panel: rgba(255,255,255,.92);
            --app-shadow: 0 18px 45px rgba(15, 23, 42, .075);
            --sidebar-bg: radial-gradient(circle at 10% 0%, rgba(96,165,250,.10), transparent 30%), linear-gradient(180deg, #111827 0%, #0f172a 50%, #020617 100%);
            --sidebar-border: rgba(148, 163, 184, .14);
            --sidebar-text: #d7dee8;
            --sidebar-muted: rgba(148, 163, 184, .72);
            --sidebar-soft: rgba(148, 163, 184, .08);
            --sidebar-soft-2: rgba(148, 163, 184, .12);
            --sidebar-active: rgba(30, 41, 59, .92);
            --sidebar-icon-grad: linear-gradient(135deg, #38bdf8 0%, #6366f1 100%);
        }

        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--app-ink);
            background:
                radial-gradient(circle at left top, rgba(96, 165, 250, .12), transparent 26%),
                radial-gradient(circle at right top, rgba(99, 102, 241, .10), transparent 22%),
                linear-gradient(180deg, #f8fbff 0%, var(--app-shell-bg) 100%);
        }

        a { text-decoration: none; }
        .min-w-0 { min-width: 0; }
        .text-slate-900 { color: #0f172a !important; }
        .text-slate-400 { color: #94a3b8 !important; }
        .text-sky-100 { color: #e0f2fe !important; }

        .app-sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, .5);
            backdrop-filter: blur(3px);
            z-index: 1035;
            opacity: 0;
            visibility: hidden;
            transition: .25s ease;
        }
        .app-sidebar-backdrop.show { opacity: 1; visibility: visible; }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--app-sidebar);
            height: 100vh;
            z-index: 1045;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--sidebar-border);
            box-shadow: 22px 0 55px rgba(15, 23, 42, .18);
            overflow: hidden;
        }
        .sidebar::before,
        .sidebar::after {
            content: '';
            position: absolute;
            border-radius: 999px;
            pointer-events: none;
            filter: blur(2px);
        }
        .sidebar::before {
            top: -72px;
            right: -46px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(59, 130, 246, .12) 0%, rgba(59, 130, 246, 0) 72%);
        }
        .sidebar::after {
            bottom: -110px;
            left: -75px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(99, 102, 241, .10) 0%, rgba(99, 102, 241, 0) 70%);
        }

        .sidebar-inner {
            position: relative;
            z-index: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
            padding: 20px 14px 16px;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 6px 8px 18px;
        }
        .sidebar-brand-link {
            display: flex;
            align-items: center;
            gap: 11px;
            color: #fff !important;
            min-width: 0;
            flex: 1 1 auto;
        }
        .brand-mark {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--sidebar-icon-grad);
            color: #fff;
            font-size: 15px;
            box-shadow: 0 12px 28px rgba(59, 130, 246, .24);
            flex: 0 0 30px;
        }
        .sidebar-brand-title {
            display: block;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: -.01em;
            line-height: 1.1;
        }
        .sidebar-brand-subtitle {
            display: block;
            margin-top: 3px;
            color: var(--sidebar-muted);
            font-size: 11px;
            font-weight: 500;
        }

        .sidebar-close-btn {
            width: 40px;
            height: 40px;
            border: 1px solid rgba(255,255,255,.16) !important;
            color: #fff !important;
            background: rgba(255,255,255,.06) !important;
            border-radius: 14px !important;
            box-shadow: none !important;
        }

        .sidebar-menu {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding-right: 4px;
            margin-top: 8px;
        }
        .sidebar-menu::-webkit-scrollbar { width: 8px; }
        .sidebar-menu::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, .10); border-radius: 999px; }

        .sidebar-section + .sidebar-section { margin-top: 18px; }
        .sidebar-section-label {
            padding: 0 10px 10px;
            color: var(--sidebar-muted);
            font-size: 10px;
            font-weight: 600;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .sidebar-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .sidebar-item + .sidebar-item,
        .sidebar-group + .sidebar-item,
        .sidebar-item + .sidebar-group,
        .sidebar-group + .sidebar-group { margin-top: 6px; }

        .sidebar-nav-link,
        .sidebar-group-button {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 42px;
            padding: 7px 10px;
            border-radius: 12px;
            color: var(--sidebar-text) !important;
            font-weight: 600;
            border: 1px solid transparent;
            background: transparent;
            transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
        }
        .sidebar-nav-link:hover,
        .sidebar-group-button:hover,
        .sidebar-nav-link.is-active,
        .sidebar-group.is-open > .sidebar-group-button,
        .sidebar-group-button.is-active {
            background: rgba(30, 41, 59, .78);
            border-color: rgba(148, 163, 184, .18);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 10px 20px rgba(2, 12, 27, .12);
            transform: translateY(-1px);
        }
        .sidebar-nav-link:focus,
        .sidebar-group-button:focus { outline: none; }

        .sidebar-link-main {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            flex: 1 1 auto;
        }
        .sidebar-link-text {
            display: block;
            font-size: 13px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            letter-spacing: -.01em;
        }
        .sidebar-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(148, 163, 184, .10);
            color: #d7f0ff;
            font-size: 14px;
            flex: 0 0 30px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
        }
        .sidebar-nav-link.is-active .sidebar-icon,
        .sidebar-group.is-open > .sidebar-group-button .sidebar-icon,
        .sidebar-group-button.is-active .sidebar-icon {
            background: var(--sidebar-icon-grad);
            color: #fff;
            box-shadow: 0 10px 20px rgba(59, 130, 246, .22);
        }
        .sidebar-chevron {
            width: 24px;
            height: 24px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #b9d6ef;
            background: rgba(148, 163, 184, .10);
            flex: 0 0 24px;
        }
        .sidebar-group-button .sidebar-chevron i {
            transition: transform .22s ease;
            transform: rotate(0deg);
        }
        .sidebar-group.is-open > .sidebar-group-button .sidebar-chevron i { transform: rotate(180deg); }

        .sidebar-submenu {
            position: relative;
            margin: 7px 0 0 15px;
            padding: 1px 0 0 14px;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height .26s ease, opacity .18s ease;
        }
        .sidebar-submenu.is-open {
            max-height: 280px;
            opacity: 1;
        }
        .sidebar-submenu-rail {
            position: absolute;
            left: 6px;
            top: 5px;
            bottom: 8px;
            width: 1px;
            background: linear-gradient(180deg, rgba(125, 211, 252, .34), rgba(125, 211, 252, .08));
        }
        .sidebar-sub-link {
            position: relative;
            display: flex;
            align-items: center;
            min-height: 34px;
            padding: 8px 10px 8px 16px;
            margin: 4px 0;
            border-radius: 10px;
            color: #a9c7df !important;
            font-size: 12.5px;
            font-weight: 600;
            border: 1px solid transparent;
            background: transparent;
            transition: .18s ease;
        }
        .sidebar-sub-link::before {
            content: '';
            position: absolute;
            left: 4px;
            top: 50%;
            width: 6px;
            height: 6px;
            border-radius: 999px;
            transform: translateY(-50%);
            background: rgba(125, 211, 252, .45);
            box-shadow: 0 0 0 4px rgba(125, 211, 252, .08);
        }
        .sidebar-sub-link:hover,
        .sidebar-sub-link.is-active {
            color: #fff !important;
            background: rgba(148, 163, 184, .10);
            border-color: rgba(160, 219, 255, .16);
        }
        .sidebar-sub-link.is-active::before {
            background: #67e8f9;
            box-shadow: 0 0 0 4px rgba(34, 211, 238, .12);
        }

        .sidebar-footer-card {
            margin-top: 18px;
            padding: 16px 15px;
            border-radius: 22px;
            border: 1px solid rgba(255,255,255,.18);
            background: linear-gradient(180deg, rgba(255,255,255,.08) 0%, rgba(255,255,255,.03) 100%);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
            color: #fff;
        }
        .sidebar-footer-icon {
            width: 38px;
            height: 38px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(148, 163, 184, .10);
            color: #e0f2fe;
        }
        .sidebar-footer-card .title {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.01em;
        }
        .sidebar-footer-card .copy {
            margin-top: 6px;
            color: var(--sidebar-muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .header {
            height: var(--app-header);
            position: fixed;
            top: 0;
            left: var(--app-sidebar);
            width: calc(100% - var(--app-sidebar));
            z-index: 1015;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 0 18px;
            background: rgba(255,255,255,.76);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(219, 231, 243, .9);
            box-shadow: 0 14px 32px rgba(15, 23, 42, .06);
        }

        .content {
            min-height: 100vh;
            margin-top: var(--app-header);
            margin-left: var(--app-sidebar);
            padding: 16px;
        }

        .app-page-card {
            background: var(--app-panel);
            border: 1px solid rgba(226, 237, 246, .96);
            border-radius: 24px;
            box-shadow: var(--app-shadow);
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
        .profile-card:hover { opacity: 1; visibility: visible; transform: translateY(0); }
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
        .btn { border-radius: 14px; font-weight: 600; }
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



        /* Compact shadcn-style sidebar refinement */
        .sidebar-brand-title { font-size: 14px; font-weight: 700; }
        .sidebar-brand-subtitle { font-size: 11px; color: rgba(148, 163, 184, .88); }
        .sidebar-section-label { color: rgba(148, 163, 184, .86); }
        .sidebar-nav-link,
        .sidebar-group-button { font-size: 13px; font-weight: 600; letter-spacing: -.01em; }
        .sidebar-nav-link.is-active,
        .sidebar-group.is-open > .sidebar-group-button,
        .sidebar-group-button.is-active {
            background: rgba(30, 41, 59, .82);
            border-color: rgba(148, 163, 184, .18);
        }
        .sidebar-nav-link:hover,
        .sidebar-group-button:hover { background: rgba(30, 41, 59, .66); }
        .sidebar-sub-link { font-size: 12.5px; font-weight: 600; color: rgba(203, 213, 225, .82) !important; }
        .sidebar-menu { padding-bottom: 8px; }

        @media (max-width: 991.98px) {
            :root { --app-sidebar: 272px; --app-header: 70px; }
            .sidebar { transform: translateX(-105%); transition: transform .26s ease; }
            .sidebar.show { transform: translateX(0); }
            .header { left: 0; width: 100%; padding: 0 16px; }
            .content { margin-left: 0; padding: 16px; }
            .app-flash-wrapper { top: 82px; right: 16px; }
        }
        @media (min-width: 992px) {
            .sidebar { transform: none !important; }
        }

        /* Full project polish overrides: compact shadcn-like admin UI */
        .sidebar-inner { padding: 18px 12px 14px; }
        .sidebar-logo { padding: 5px 8px 15px; gap: 10px; }
        .brand-mark { width: 34px; height: 34px; flex-basis: 34px; border-radius: 11px; font-size: 15px; box-shadow: 0 10px 24px rgba(37, 99, 235, .24); }
        .sidebar-brand-title { font-size: 14px; font-weight: 750; letter-spacing: -.015em; }
        .sidebar-brand-subtitle { font-size: 10.5px; font-weight: 600; color: rgba(148, 163, 184, .82); }
        .sidebar-section-label { padding: 0 10px 8px; font-size: 10px; letter-spacing: .16em; color: rgba(148, 163, 184, .76); }
        .sidebar-section + .sidebar-section { margin-top: 14px; }
        .sidebar-item + .sidebar-item,
        .sidebar-group + .sidebar-item,
        .sidebar-item + .sidebar-group,
        .sidebar-group + .sidebar-group { margin-top: 3px; }
        .sidebar-nav-link,
        .sidebar-group-button { min-height: 42px; padding: 7px 9px; border-radius: 12px; font-size: 13px; font-weight: 650; color: #d7dee8 !important; box-shadow: none; }
        .sidebar-nav-link:hover,
        .sidebar-group-button:hover,
        .sidebar-nav-link.is-active,
        .sidebar-group.is-open > .sidebar-group-button,
        .sidebar-group-button.is-active { background: rgba(30, 41, 59, .82); border-color: rgba(148, 163, 184, .16); box-shadow: inset 0 1px 0 rgba(255,255,255,.04); transform: none; }
        .sidebar-icon { width: 28px; height: 28px; flex-basis: 28px; border-radius: 9px; font-size: 13px; background: rgba(148, 163, 184, .10); color: #cbd5e1; }
        .sidebar-nav-link.is-active .sidebar-icon,
        .sidebar-group.is-open > .sidebar-group-button .sidebar-icon,
        .sidebar-group-button.is-active .sidebar-icon { background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 8px 18px rgba(37, 99, 235, .24); }
        .sidebar-chevron { width: 24px; height: 24px; flex-basis: 24px; border-radius: 8px; font-size: 11px; background: rgba(148, 163, 184, .08); }
        .sidebar-submenu { margin: 5px 0 0 14px; padding: 1px 0 0 13px; }
        .sidebar-sub-link { min-height: 34px; margin: 3px 0; padding: 8px 10px 8px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; color: rgba(203, 213, 225, .72) !important; }
        .sidebar-sub-link::before { width: 6px; height: 6px; left: 4px; box-shadow: 0 0 0 3px rgba(96,165,250,.08); }
        .sidebar-sub-link:hover,
        .sidebar-sub-link.is-active { background: rgba(51, 65, 85, .55); color: #f8fafc !important; }
        .sidebar-footer-card { display: none !important; }

        .header { background: rgba(255,255,255,.82); border-bottom: 1px solid rgba(226,232,240,.82); box-shadow: 0 12px 32px rgba(15, 23, 42, .055); }
        .content { padding: 16px; width: auto; max-width: 100%; overflow-x: hidden; }
        .content > .container-fluid,
        .content .container-fluid {
            width: 100%;
            max-width: none !important;
            margin-left: 0;
            margin-right: 0;
            padding-left: clamp(.5rem, 1vw, 1rem);
            padding-right: clamp(.5rem, 1vw, 1rem);
        }

        .card:not(.booking-card) {
            border: 1px solid rgba(226, 232, 240, .92) !important;
            border-radius: 20px !important;
            box-shadow: 0 14px 34px rgba(15, 23, 42, .055) !important;
            background: rgba(255,255,255,.94);
        }
        .card:not(.booking-card) > .card-header {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%) !important;
            border-bottom: 1px solid #e2e8f0 !important;
            border-radius: 20px 20px 0 0 !important;
            font-weight: 700;
        }
        .card:not(.booking-card) .card-body { padding: 1rem; }

        .table:not(.booking-table) { margin-bottom: 0; }
        .table:not(.booking-table) thead th {
            background: #f8fafc !important;
            color: #334155;
            border-bottom: 1px solid #e2e8f0 !important;
            font-size: 12px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: .035em;
            white-space: nowrap;
        }
        .table:not(.booking-table) tbody td { font-size: 13px; color: #334155; }
        .table:not(.booking-table) tbody tr:hover { background: #f8fbff; }

        .btn { border-radius: 11px !important; font-size: 13px; font-weight: 700; }
        .btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8) !important; border-color: #2563eb !important; box-shadow: 0 10px 20px rgba(37, 99, 235, .18); }
        .btn-success { background: linear-gradient(135deg, #16a34a, #15803d) !important; border-color: #16a34a !important; }
        .btn-danger { background: linear-gradient(135deg, #dc2626, #b91c1c) !important; border-color: #dc2626 !important; }
        .btn-outline-primary { border-color: #bfdbfe !important; color: #1d4ed8 !important; background: #fff !important; }
        .btn-outline-primary:hover { background: #eff6ff !important; color: #1e40af !important; }
        .form-label { color: #334155; font-size: 12px; font-weight: 750; letter-spacing: .015em; }
        .form-control, .form-select {
            min-height: 36px;
            border-radius: 12px !important;
            border-color: #dbe4f0 !important;
            background-color: #fff;
            font-size: 13px;
        }
        .form-control:focus, .form-select:focus { border-color: #60a5fa !important; box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10) !important; }
        .nav-tabs { border-bottom: 0; gap: 8px; }
        .nav-tabs .nav-link {
            border: 1px solid #e2e8f0 !important;
            border-radius: 999px !important;
            color: #64748b;
            background: #fff;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
        }
        .nav-tabs .nav-link.active { color: #1d4ed8 !important; background: #eff6ff !important; border-color: #bfdbfe !important; }
        .dropdown-menu { border: 1px solid #e2e8f0 !important; border-radius: 16px !important; box-shadow: 0 18px 45px rgba(15, 23, 42, .14) !important; padding: 8px !important; }
        .dropdown-item { border-radius: 10px; font-size: 13px; font-weight: 600; }
        .dropdown-item:hover { background: #f1f5f9; }

        .app-hero-card {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(226,232,240,.92);
            border-radius: 22px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 58%, #eff6ff 100%);
            box-shadow: 0 18px 45px rgba(15,23,42,.07);
        }
        .app-hero-card::after {
            content: '';
            position: absolute;
            top: -52px;
            right: -52px;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: rgba(37, 99, 235, .10);
        }
        .app-hero-card > * { position: relative; z-index: 1; }
        .app-hero-eyebrow { color: #2563eb; font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        .app-hero-title { margin: 4px 0 4px; color: #0f172a; font-size: clamp(1.1rem, 1.6vw, 1.4rem); font-weight: 800; letter-spacing: -.035em; }
        .app-hero-copy { color: #64748b; font-size: 14px; line-height: 1.6; }
        .app-stat-card { border-radius: 18px; border: 1px solid #e2e8f0; background: #fff; box-shadow: 0 14px 32px rgba(15,23,42,.055); }
        .app-stat-label { color: #64748b; font-size: 12px; font-weight: 700; }
        .app-stat-value { color: #0f172a; font-size: 1.6rem; font-weight: 850; letter-spacing: -.04em; }
        .app-stat-icon { width: 38px; height: 38px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center; background: #eff6ff; color: #2563eb; }

        .app-hero-layout,
        .app-hero-main,
        .app-stat-card,
        .card,
        .table-card,
        .filters-card {
            min-width: 0;
        }
        .app-hero-layout {
            width: 100%;
        }
        .app-hero-main {
            flex: 1 1 320px;
        }
        .app-hero-action {
            flex: 0 0 auto;
        }
        .row > [class*="col-"] {
            min-width: 0;
        }


        /* Project-wide attractive data tables */
        .content .card > .card-body.table-responsive {
            padding: 14px !important;
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .content .card > .card-body.table-responsive::-webkit-scrollbar { height: 8px; width: 8px; }
        .content .card > .card-body.table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .content .card > .card-body.table-responsive::-webkit-scrollbar-track { background: transparent; }

        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) {
            --bs-table-bg: transparent;
            --bs-table-hover-bg: transparent;
            min-width: 760px;
            border-collapse: separate !important;
            border-spacing: 0 10px !important;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table).table-bordered > :not(caption) > * {
            border-width: 0 !important;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table).table-bordered > :not(caption) > * > * {
            border-width: 0 !important;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) thead th {
            padding: 10px 12px !important;
            border: 0 !important;
            background: linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%) !important;
            color: #475569 !important;
            font-size: 11px !important;
            font-weight: 850 !important;
            letter-spacing: .07em !important;
            text-transform: uppercase;
            vertical-align: middle;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) thead th:first-child {
            border-radius: 15px 0 0 15px;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) thead th:last-child {
            border-radius: 0 15px 15px 0;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody tr {
            position: relative;
            transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody td {
            padding: 10px !important;
            border-top: 1px solid #e6eef8 !important;
            border-bottom: 1px solid #e6eef8 !important;
            background: #ffffff !important;
            color: #334155 !important;
            font-size: 13px !important;
            line-height: 1.45;
            vertical-align: middle;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .035);
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody td:first-child {
            border-left: 1px solid #e6eef8 !important;
            border-radius: 16px 0 0 16px;
            color: #2563eb !important;
            font-weight: 800;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody td:last-child {
            border-right: 1px solid #e6eef8 !important;
            border-radius: 0 16px 16px 0;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody tr:hover td {
            background: #f8fbff !important;
            border-color: #bfdbfe !important;
            box-shadow: 0 16px 34px rgba(37, 99, 235, .08);
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody tr:hover {
            transform: translateY(-1px);
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) tbody tr:has(td[colspan]) td {
            border-radius: 16px !important;
            text-align: center;
            color: #64748b !important;
            font-weight: 700;
            box-shadow: none;
            background: #f8fafc !important;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) .badge {
            min-height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 10.5px;
            font-weight: 850;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.32);
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) td:last-child .d-flex,
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) td:last-child form.d-inline {
            align-items: center;
            gap: 8px !important;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) td:last-child form {
            margin: 0;
        }
        .content .table:not(.booking-table):not(.bf-top-table):not(.bf-info-table):not(.bf-consignee-table):not(.bf-sign-table):not(.po-change-table):not(.booking-change-table) td:last-child .btn-sm {
            min-width: 34px;
            height: 34px;
            border-radius: 12px !important;
            box-shadow: 0 10px 18px rgba(15, 23, 42, .08);
        }
        .content .pagination { gap: 6px; }
        .content .page-link {
            border: 1px solid #dbeafe;
            border-radius: 11px !important;
            color: #2563eb;
            font-size: 12px;
            font-weight: 800;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .04);
        }
        .content .page-item.active .page-link {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-color: #2563eb;
        }


        /* Project-wide compact icon actions: any button/link that already has a Bootstrap icon
           is rendered as an attractive square icon button. The original text remains available
           for title/aria-label through the script below, but no longer breaks layouts at zoom. */
        .content :is(a, button).btn:has(> i.bi),
        .content .generated-po-action:has(> i.bi) {
            width: 38px !important;
            min-width: 38px !important;
            height: 38px !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0 !important;
            border-radius: 13px !important;
            font-size: 0 !important;
            line-height: 1 !important;
            white-space: nowrap !important;
            box-shadow: 0 10px 22px rgba(15, 23, 42, .08);
            overflow: hidden;
        }
        .content :is(a, button).btn.btn-sm:has(> i.bi),
        .content .generated-po-action:has(> i.bi) {
            width: 34px !important;
            min-width: 34px !important;
            height: 34px !important;
            border-radius: 11px !important;
        }
        .content :is(a, button).btn:has(> i.bi) > i.bi,
        .content .generated-po-action:has(> i.bi) > i.bi {
            margin: 0 !important;
            font-size: 15px !important;
            line-height: 1 !important;
        }
        .content :is(a, button).btn.btn-sm:has(> i.bi) > i.bi,
        .content .generated-po-action:has(> i.bi) > i.bi {
            font-size: 14px !important;
        }
        .content :is(a, button).btn:has(> i.bi) > span,
        .content .generated-po-action:has(> i.bi) > span {
            display: none !important;
        }
        .content :is(a, button).btn:has(> i.bi):hover,
        .content .generated-po-action:has(> i.bi):hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 26px rgba(15, 23, 42, .12);
        }

        @media (max-width: 991.98px) {
            :root { --app-sidebar: 260px; --app-header: 70px; }
            .content { padding: 16px; }
            .sidebar-inner { padding: 16px 10px 12px; }
        }
        @media (max-width: 575.98px) {
            .header { padding: 0 10px; gap: 8px; }
            .content { padding: 12px; }
            .content > .container-fluid,
            .content .container-fluid { padding-left: 0; padding-right: 0; }
            .app-hero-card { border-radius: 18px; }
            .app-hero-main { align-items: flex-start !important; flex-basis: 100%; }
            .app-hero-action { margin-left: auto; }
            .app-hero-card::after { width: 130px; height: 130px; top: -42px; right: -48px; }
            .card:not(.booking-card) .card-body { padding: 1rem; }
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

            document.querySelectorAll('[data-sidebar-group-toggle]').forEach(function (toggle) {
                toggle.addEventListener('click', function () {
                    const group = this.closest('.sidebar-group');
                    const submenu = group?.querySelector('.sidebar-submenu');
                    if (!group || !submenu) return;

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
