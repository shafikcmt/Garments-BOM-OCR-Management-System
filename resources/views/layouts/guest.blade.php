<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Humana Apparels Pvt. Ltd | OCR Management' }}</title>

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
