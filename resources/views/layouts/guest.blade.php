<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Humana Apparels Pvt. Ltd | OCR Management' }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Laravel Assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.55)),
                        url('https://media.licdn.com/dms/image/v2/D5622AQGfw4ASqNhfGg/feedshare-shrink_800/B56Zm8jMevI8Ag-/0/1759804969977?e=2147483647&v=beta&t=K7PQdu5ANALnDAY7XlUONfpm-J3-rab1h8MDKsDUTFM');
            background-size: cover;
            background-position: center;
            font-family: 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 20px;
        }

        /* Glass / Frosted Auth Card */
        .auth-card {
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(12px) saturate(180%);
            -webkit-backdrop-filter: blur(12px) saturate(180%);
            border-radius: 18px;
            padding: 35px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.45);
            border: 1px solid rgba(255,255,255,0.3);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .auth-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 18px;
            background: linear-gradient(120deg, rgba(255,255,255,0.35), rgba(255,255,255,0.05), rgba(255,255,255,0.25));
            opacity: 0.6;
            pointer-events: none;
        }

        .user-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px rgba(0,0,0,0.4);
            border: 1px solid rgba(255,255,255,0.4);
        }

        .user-icon i {
            font-size: 38px;
            color: #ffffff;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0 2px 6px rgba(0,0,0,0.6);
            margin-bottom: 5px;
        }

        .login-subtitle {
            color: #e0e0e0;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .form-label {
            color: #ffffff;
            font-weight: 500;
        }

        .form-control {
            background: rgba(255,255,255,0.85);
            border: none;
            color: #212529;
            border-radius: 5px;
        }

        .form-control::placeholder {
            color: #6c757d;
        }

        .form-check-label, a {
            color: #f1f1f1;
        }

        a:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .btn-primary {
            font-weight: 600;
        }
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
