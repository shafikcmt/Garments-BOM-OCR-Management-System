<x-guest-layout>
    <div class="user-icon">
        <i class="bi bi-key-fill"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">Set a new password for your account.</div>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3 text-start">
            <label for="email" class="form-label">Email Address</label>
            <input type="email" name="email" id="email" value="{{ old('email', $request->email) }}" required autofocus class="form-control" placeholder="Your email">
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mb-3 text-start">
            <label for="password" class="form-label">New Password</label>
            <input type="password" name="password" id="password" required autocomplete="new-password" class="form-control" placeholder="New password">
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mb-4 text-start">
            <label for="password_confirmation" class="form-label">Confirm Password</label>
            <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password" class="form-control" placeholder="Confirm new password">
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check2-circle me-1"></i> Reset Password
        </button>
    </form>
</x-guest-layout>
