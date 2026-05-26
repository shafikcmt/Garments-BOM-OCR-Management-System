<x-guest-layout>
    <div class="user-icon">
        <i class="bi bi-envelope-lock-fill"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">Enter your email to receive a password reset link.</div>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3 text-start">
            <label for="email" class="form-label">Email Address</label>
            <div class="position-relative">
                <i class="bi bi-envelope position-absolute text-slate-400" style="left:14px;top:50%;transform:translateY(-50%);"></i>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus class="form-control" style="padding-left:42px;" placeholder="Enter your email">
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-send me-1"></i> Send Reset Link
        </button>

        <div class="text-center mt-3">
            <a href="{{ route('login') }}" class="small text-decoration-none fw-semibold">Back to Login</a>
        </div>
    </form>
</x-guest-layout>
