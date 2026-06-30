<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): View
    {
        return view('auth.login', [
            'rememberedEmail' => $request->cookie('remembered_email'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $request->user()->forceFill(['last_login_at' => now()])->save();

        $response = redirect()->intended(route('dashboard', absolute: false));

        // Remember the email/username for convenience on the next visit.
        // (Staying logged in is handled by Laravel's remember-me token above.)
        if ($request->boolean('remember')) {
            $response->cookie('remembered_email', $request->input('email'), 60 * 24 * 365);
        } else {
            $response->withCookie(Cookie::forget('remembered_email'));
        }

        return $response;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/')->withCookie(Cookie::forget('remembered_email'));
    }
}
