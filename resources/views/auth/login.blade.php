<x-guest-layout>
    <div class="user-icon">
        <i class="bi bi-person-check-fill" aria-hidden="true"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">Sign in to the OCR Management System</div>

    <x-auth-session-status class="mb-3" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4" name="login" id="login-form">
        @csrf

        <div class="text-start">
            <label class="form-label" for="login-email">Email / Username</label>
            <div class="position-relative">
                <i class="bi bi-envelope position-absolute text-slate-400" style="left:14px;top:50%;transform:translateY(-50%);"></i>
                <input type="email" id="login-email" placeholder="Enter your email" name="email" value="{{ old('email', $rememberedEmail ?? '') }}" required autofocus autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" class="form-control" style="padding-left:42px;">
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="text-start">
            <label class="form-label" for="login-password">Password</label>
            <div class="position-relative">
                <i class="bi bi-lock position-absolute text-slate-400" style="left:14px;top:50%;transform:translateY(-50%);"></i>
                <input type="password" id="login-password" placeholder="Enter your password" name="password" required autocomplete="current-password" spellcheck="false" class="form-control" style="padding-left:42px;">
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="d-flex justify-content-between align-items-center gap-3">
            <div class="form-check text-start">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('email', $rememberedEmail ?? '') ? 'checked' : '' }}>
                <label class="form-check-label small fw-semibold" for="remember">Remember me</label>
            </div>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="small text-decoration-none fw-bold">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i> Login
        </button>

        <div class="text-center mt-4 small text-slate-500">
            © {{ date('Y') }} Humana Apparels Pvt. Ltd — OCR Management
        </div>
    </form>
</x-guest-layout>
