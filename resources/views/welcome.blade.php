<x-guest-layout>
    <!-- User Icon + Brand -->
    <div class="user-icon">
        <i class="bi bi-person-fill"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">OCR Management System</div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-3" :status="session('status')" />

    <!-- Login Form -->
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3 text-start">
            <label class="form-label">Email / Username</label>
            <input placeholder="Email / Username"  type="email" name="email" value="{{ old('email') }}" required autofocus class="form-control">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mb-3 text-start">
            <label class="form-label">Password</label>
            <input type="password" name="password" placeholder="Password" required class="form-control">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="form-check text-start">
                <input class="form-check-input" type="checkbox" name="remember" id="remember">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="small text-decoration-none">Forgot password?</a>
            @endif
        </div>

        <button type="submit" class="btn btn-primary w-100">Login</button>

        <div class="text-center mt-3 small text-muted">© 2025 Humana Apparels Pvt. Ltd – OCR Management</div>
    </form>
</x-guest-layout>
