<x-guest-layout>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
    body {
        min-height: 100vh;
        margin: 0;
        background: linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.55)),
                    url('https://media.licdn.com/dms/image/v2/D5622AQGfw4ASqNhfGg/feedshare-shrink_800/B56Zm8jMevI8Ag-/0/1759804969977?e=2147483647&v=beta&t=K7PQdu5ANALnDAY7XlUONfpm-J3-rab1h8MDKsDUTFM');
        background-size: cover;
        background-position: center;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Segoe UI', sans-serif;
        color: #fff;
        text-align: center;
    }

    .login-card {
        background: rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(12px) saturate(180%);
        -webkit-backdrop-filter: blur(12px) saturate(180%);
        border-radius: 18px;
        padding: 35px;
        width: 100%;
        max-width: 500px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
        border: 1px solid rgba(255, 255, 255, 0.3);
        position: relative;
        overflow: hidden;
        text-align: left; /* label and inputs aligned left */
    }

    .login-card::before {
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

    .brand, .login-title, .login-subtitle, .login-card .form-check-label, .login-card a {
        text-align: center;
    }

    .brand {
        font-size: 20px;
        font-weight: 700;
        color: #ffffff;
        text-shadow: 0 2px 6px rgba(0,0,0,0.6);
        margin-bottom: 5px;
    }

    .login-title {
        font-weight: 700;
        color: #ffffff;
        text-shadow: 0 1px 4px rgba(0,0,0,0.5);
        margin-bottom: 5px;
    }

    .login-subtitle {
        color: #e0e0e0;
        font-size: 14px;
        margin-bottom: 25px;
    }

    .login-card label {
        color: #ffffff;
        font-weight: 500;
    }

    .login-card .form-control {
        background: rgba(255,255,255,0.85);
        border: none;
        color: #212529;
    }

    .login-card .form-control::placeholder {
        color: #6c757d;
        border-radius:5px;
    }

    .login-card .form-check-label, .login-card a {
        color: #f1f1f1;
    }

    .login-card a:hover {
        color: #ffffff;
        text-decoration: underline;
    }
</style>

<div class="login-card">
    <div class="user-icon">
        <i class="bi bi-person-fill"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">OCR Management System</div>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">Email / Username</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus class="form-control">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" required class="form-control">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-decoration-none small">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary w-100 fw-semibold">Login</button>

        @if (Route::has('login'))
            @auth
                <nav class="flex items-center justify-end gap-4 mt-3">
                    <a href="{{ url('/dashboard') }}" class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                        Dashboard
                    </a>
                </nav>
            @endauth
        @endif

        <div class="text-center mt-3 small text-muted">© 2025 Humana Apparels Pvt. Ltd – OCR Management</div>
    </form>
</div>
</x-guest-layout>