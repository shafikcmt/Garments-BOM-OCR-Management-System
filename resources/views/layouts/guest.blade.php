<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Humana Apparels Pvt. Ltd | OCR Management' }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- Same bundle as the authenticated layout — the auth screens rely on
         Tailwind utilities, which are now compiled rather than pulled from the
         Tailwind play CDN. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            min-height: 100vh;
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 18% 18%, rgba(56, 189, 248, .28), transparent 25rem),
                radial-gradient(circle at 82% 8%, rgba(37, 99, 235, .20), transparent 28rem),
                linear-gradient(135deg, #ecfeff 0%, #f8fafc 42%, #e0f2fe 100%);
            color: #0f172a;
        }

        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 24px;
            position: relative;
            overflow: hidden;
        }

        .auth-wrapper::before,
        .auth-wrapper::after {
            content: '';
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 999px;
            background: rgba(14, 165, 233, .16);
            filter: blur(3px);
            z-index: 0;
        }

        .auth-wrapper::before { left: -100px; bottom: -90px; }
        .auth-wrapper::after { right: -90px; top: -80px; background: rgba(37, 99, 235, .12); }

        .auth-card {
            position: relative;
            z-index: 1;
            width: min(100%, 460px);
            padding: 34px;
            background: rgba(255, 255, 255, .84);
            border: 1px solid rgba(255, 255, 255, .88);
            border-radius: 30px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, .16);
            backdrop-filter: blur(18px);
            color: #0f172a;
        }

        .user-icon {
            width: 74px;
            height: 74px;
            margin: 0 auto 16px;
            border-radius: 24px;
            background: linear-gradient(135deg, #0ea5e9, #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 18px 34px rgba(37, 99, 235, .28);
            color: #ffffff;
        }

        .user-icon i { font-size: 34px; }
        .brand { font-size: 23px; font-weight: 850; color: #0f172a; text-align: center; letter-spacing: -.04em; }
        .login-subtitle { color: #64748b; font-size: 14px; margin: 6px 0 26px; text-align: center; }

        .form-label {
            color: #334155;
            font-weight: 750;
            text-align: left;
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
        }

        .form-control {
            min-height: 46px;
            background: rgba(255, 255, 255, .92);
            border: 1px solid #dbeafe;
            color: #0f172a;
            border-radius: 16px;
            padding: 11px 13px;
            box-shadow: 0 1px 0 rgba(15, 23, 42, .02);
        }

        .form-control::placeholder { color: #94a3b8; }
        .form-control:focus { border-color: #38bdf8; box-shadow: 0 0 0 .22rem rgba(14, 165, 233, .14); outline: none; }
        .form-check-label, a { color: #0369a1; }
        a:hover { color: #0f172a; text-decoration: underline; }

        .btn-primary {
            min-height: 46px;
            background: linear-gradient(135deg, #0284c7, #2563eb);
            border: none;
            color: white;
            font-weight: 800;
            border-radius: 16px;
            padding: 11px 16px;
            transition: .2s ease;
            box-shadow: 0 16px 32px rgba(37, 99, 235, .22);
        }

        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 20px 36px rgba(37, 99, 235, .30); }
    
        /* Compact polished auth UI */
        body {
            background:
                radial-gradient(circle at 12% 8%, rgba(59,130,246,.16), transparent 24rem),
                radial-gradient(circle at 88% 12%, rgba(14,165,233,.12), transparent 26rem),
                linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%) !important;
        }
        .auth-card {
            width: min(100%, 430px) !important;
            padding: 28px !important;
            border-radius: 24px !important;
            border: 1px solid rgba(226,232,240,.95) !important;
            box-shadow: 0 22px 60px rgba(15, 23, 42, .12) !important;
        }
        .user-icon {
            width: 58px !important;
            height: 58px !important;
            border-radius: 18px !important;
            font-size: 24px !important;
        }
        .auth-card h1, .auth-card h2, .auth-card h3 {
            letter-spacing: -.03em;
            font-weight: 800;
        }
        .form-label { font-size: 12px; font-weight: 750; color: #334155; }
        .form-control {
            min-height: 44px;
            border-radius: 13px !important;
            border-color: #dbe4f0 !important;
            font-size: 13px;
        }
        .form-control:focus { border-color: #60a5fa !important; box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .10) !important; }
        .btn { border-radius: 13px !important; font-weight: 750; }
        .btn-primary { background: linear-gradient(135deg, #2563eb, #1d4ed8) !important; border-color: #2563eb !important; box-shadow: 0 12px 24px rgba(37,99,235,.18); }

    </style>
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
