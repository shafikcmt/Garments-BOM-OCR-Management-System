<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Signing in with an HR Employee ID in the same box as the email.
 *
 *   - The linked account signs in with its Employee ID + its password.
 *   - Wrong password, unknown ID, inactive employee record and an ID with
 *     no account all fail with the SAME generic message, and each counts
 *     against the same throttle as a wrong email would.
 *   - Email login is untouched: same field, same message, same lockout.
 */
beforeEach(function () {
    RateLimiter::clear('074047|127.0.0.1');
});

function employeeWithLogin(array $employee = [], array $user = []): User
{
    $account = User::factory()->create($user + ['email' => '074047@staff.test']);

    Employee::create($employee + [
        'employee_id' => '074047',
        'user_id' => $account->id,
        'name' => $account->name,
        'is_active' => true,
    ]);

    return $account;
}

test('an employee signs in with their Employee ID and password', function () {
    $account = employeeWithLogin();

    $response = $this->post('/login', ['email' => ' 074047 ', 'password' => 'password']);

    $this->assertAuthenticatedAs($account);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the same account still signs in with its email', function () {
    $account = employeeWithLogin();

    $this->post('/login', ['email' => $account->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($account);
});

test('a wrong password with a valid Employee ID fails with the generic message', function () {
    employeeWithLogin();

    $this->from('/login')
        ->post('/login', ['email' => '074047', 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('an unknown Employee ID fails with the generic message, not a hint', function () {
    employeeWithLogin();

    $this->from('/login')
        ->post('/login', ['email' => '999999', 'password' => 'password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('an inactive employee record cannot sign in by ID, though its email still can', function () {
    $account = employeeWithLogin(['is_active' => false]);

    $this->from('/login')
        ->post('/login', ['email' => '074047', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();

    // HR inactive is not the same as the account being disabled.
    $this->post('/login', ['email' => $account->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($account);
});

test('an Employee ID with no linked account fails with the generic message', function () {
    Employee::create(['employee_id' => '074047', 'user_id' => null, 'name' => 'Nobody', 'is_active' => true]);

    $this->from('/login')
        ->post('/login', ['email' => '074047', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();
});

test('a deactivated user account is refused by Employee ID exactly as by email', function () {
    employeeWithLogin([], ['status' => 0]);

    $this->from('/login')
        ->post('/login', ['email' => '074047', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('wrong Employee ID attempts count against the same lockout as wrong emails', function () {
    employeeWithLogin();

    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => '074047', 'password' => 'wrong-password']);
    }

    // Sixth try — even with the right password — is locked out.
    $this->from('/login')
        ->post('/login', ['email' => '074047', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toContain('Too many login attempts');
});

test('a value with an @ is never looked up as an Employee ID', function () {
    // An employee record whose ID happens to look like an email must not
    // let its account be reached by typing that string as an "email".
    $account = User::factory()->create(['email' => 'real@company.test']);
    Employee::create(['employee_id' => 'ghost@id.test', 'user_id' => $account->id, 'name' => 'x']);

    $this->post('/login', ['email' => 'ghost@id.test', 'password' => 'password']);

    $this->assertGuest();
});

test('the login screen offers one box for either identifier', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Email or Employee ID')
        ->assertSee('type="text" id="login-email"', false)
        ->assertSee('autocomplete="username"', false)
        ->assertDontSee('type="email"', false);
});

test('an Employee ID signs in whatever case it is typed in', function () {
    RateLimiter::clear('hapl-0231|127.0.0.1');
    $account = employeeWithLogin(['employee_id' => 'HAPL-0231']);

    $this->post('/login', ['email' => 'hapl-0231', 'password' => 'password']);

    $this->assertAuthenticatedAs($account);
});
