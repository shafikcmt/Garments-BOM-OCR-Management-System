<x-guest-layout>
    <div class="user-icon">
        <i class="bi bi-person-plus-fill"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">Create a new account.</div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="mb-3 text-start">
            <label for="name" class="form-label">Full Name</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required autofocus autocomplete="name" class="form-control" placeholder="Your name">
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mb-3 text-start">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required autocomplete="username" class="form-control" placeholder="Your email">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mb-3 text-start">
            <label for="password" class="form-label">Password</label>
            <input type="password" name="password" id="password" required autocomplete="new-password" class="form-control" placeholder="Password">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mb-4 text-start">
            <label for="password_confirmation" class="form-label">Confirm Password</label>
            <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password" class="form-control" placeholder="Confirm password">
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="bi bi-person-check me-1"></i> Register
        </button>

        <div class="text-center small">
            <a href="{{ route('login') }}" class="text-decoration-none fw-semibold">Already have an account? Login</a>
        </div>
    </form>
</x-guest-layout>
