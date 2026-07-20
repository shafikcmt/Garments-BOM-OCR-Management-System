<x-guest-layout>
    <div class="user-icon">
        <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">Confirm your password to continue.</div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="mb-4 text-start">
            <label for="password" class="form-label">Password</label>
            <div class="position-relative">
                <i class="bi bi-lock position-absolute text-slate-400" style="left:14px;top:50%;transform:translateY(-50%);"></i>
                <input type="password" name="password" id="password" required autocomplete="current-password" class="form-control" style="padding-left:42px;" placeholder="Enter your password">
            </div>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i> Confirm
        </button>
    </form>
</x-guest-layout>
