<?php

namespace App\Http\Requests\Auth;

use App\Models\Employee;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in with ONE "Login ID" box that takes either a company email or an
 * HR Employee ID (App\Models\Employee).
 *
 * The field is still named `email` — the form, the remembered-login cookie
 * and every existing test post it that way — but it is no longer required
 * to LOOK like an email: "@" present means email and the path is exactly
 * what it always was; otherwise the value is looked up as an Employee ID
 * and the linked account's email is what Auth::attempt() is given, with
 * the password the person typed. The password is required either way.
 *
 * Nothing about a failure says which part was wrong. An unknown ID, an
 * inactive employee record, an ID with no account, a wrong password and a
 * wrong email all produce the same auth.failed message, and every one of
 * them counts against the same throttle key (login id + IP, 5 tries), so
 * guessing IDs is no cheaper than guessing emails.
 */
class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->credentials();

        if ($credentials === null || ! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if ((int) (Auth::user()->status ?? 1) !== 1) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Please contact the administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * What Auth::attempt() is given: the typed email and password as
     * always, or — for an Employee ID — the linked account's email and the
     * typed password. Null when the ID resolves to no account, which the
     * caller treats exactly like a wrong password.
     *
     * @return array{email: string, password: string}|null
     */
    private function credentials(): ?array
    {
        $loginId = trim($this->string('email'));

        if ($this->looksLikeEmail($loginId)) {
            return ['email' => $loginId, 'password' => $this->string('password')];
        }

        $user = Employee::loginUserFor($loginId);

        return $user
            ? ['email' => $user->email, 'password' => $this->string('password')]
            : null;
    }

    private function looksLikeEmail(string $loginId): bool
    {
        return str_contains($loginId, '@');
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request: the login id as
     * typed (email or Employee ID) plus the IP — one bucket per identifier,
     * whichever kind it is.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim($this->string('email'))).'|'.$this->ip());
    }
}
