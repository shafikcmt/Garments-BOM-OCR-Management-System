<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect(route('login', absolute: false));
});

test('the root sends a guest to the one login screen', function () {
    $this->get('/')->assertRedirect(route('login', absolute: false));

    // And that screen is the current one — there is no second copy.
    $this->get('/login')->assertOk()->assertSee('Email or Employee ID');
    expect(view()->exists('welcome'))->toBeFalse();
});

test('the root sends a signed-in user to their dashboard, never to a login form', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertRedirect(route('dashboard', absolute: false));
});

test('logging out lands on the login screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login', absolute: false));
    $this->get('/login')->assertOk()->assertSee('Email or Employee ID');
});
