<x-guest-layout>
    <div class="user-icon">
        <i class="bi bi-envelope-check-fill" aria-hidden="true"></i>
    </div>
    <div class="brand">Humana Apparels Pvt. Ltd</div>
    <div class="login-subtitle">Verify your email address to continue.</div>

    <p class="text-muted small text-center mb-3">
        A verification link has been sent to your email. Click the link to activate your account.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="alert alert-success rounded-3 small mb-3">
            A new verification link has been sent to your email address.
        </div>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn btn-primary w-100 mb-2">
            <i class="bi bi-send me-1" aria-hidden="true"></i> Resend Verification Email
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary w-100">Log Out</button>
    </form>
</x-guest-layout>
