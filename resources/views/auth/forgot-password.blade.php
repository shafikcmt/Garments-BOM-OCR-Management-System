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

    .forget-card {
        background: rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(12px) saturate(180%);
        -webkit-backdrop-filter: blur(12px) saturate(180%);
        border-radius: 18px;
        padding: 35px;
        width: 100%;
        max-width: 400px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
        border: 1px solid rgba(255, 255, 255, 0.3);
        position: relative;
        overflow: hidden;
        text-align: left;
        color: #fff;
    }

    .forget-card::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 18px;
        background: linear-gradient(120deg, rgba(255,255,255,0.35), rgba(255,255,255,0.05), rgba(255,255,255,0.25));
        opacity: 0.6;
        pointer-events: none;
    }

    .forget-card label {
        color: #ffffff;
        font-weight: 500;
    }

    .forget-card .form-control {
        background: rgba(255,255,255,0.85);
        border: none;
        color: #212529;
    }

    .forget-card .form-control::placeholder {
        color: #6c757d;
    }

    .forget-card .btn-primary {
        width: 100%;
        font-weight: 600;
    }
</style>

<div class="forget-card">
    <div class="brand text-center mb-3">Humana Apparels Pvt. Ltd</div>
    <div class="login-title text-center mb-2">Forgot Password</div>
    <div class="login-subtitle mb-4">Enter your email address and we will send a password reset link.</div>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" name="email" id="email" required class="form-control" :value="old('email')">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <button type="submit" class="btn btn-primary mb-3">Email Password Reset Link</button>

        <div class="text-center small">
            <a href="{{ route('login') }}" class="text-decoration-none text-light">Back to Login</a>
        </div>
    </form>
</div>
</x-guest-layout>