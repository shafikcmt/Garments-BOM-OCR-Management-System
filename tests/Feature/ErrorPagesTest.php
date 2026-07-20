<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Custom error pages.
 *
 * The 403 matters most here: this app aborts 403 both for a role that does not
 * cover a screen and for a BOM file an admin has locked, and those need
 * different follow-up. Laravel's default page says neither.
 *
 * The pages are self-contained by design — layouts.app renders the sidebar and
 * queries notifications for auth()->user(), so an error page extending it
 * could fail while rendering the explanation of a failure.
 */
it('renders a branded 404 rather than the framework default', function () {
    $response = $this->get('/a-route-that-does-not-exist')->assertNotFound();

    $response->assertSee('That page does not exist')
        ->assertSee('Sign in');
});

it('offers a signed-in user a way back into the app', function () {
    Role::findOrCreate('store', 'web');
    $user = User::factory()->create()->assignRole('store');

    $this->actingAs($user)
        ->get('/a-route-that-does-not-exist')
        ->assertNotFound()
        ->assertSee('Back to dashboard')
        ->assertSee($user->name);
});

it('explains the two things that actually cause a 403 here', function () {
    Role::findOrCreate('store', 'web');
    $user = User::factory()->create()->assignRole('store');

    // A store user reaching for an admin-only screen.
    $response = $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();

    $response->assertSee('You do not have access to this')
        ->assertSee('locked');
});

it('renders the error page without the app shell', function () {
    Role::findOrCreate('store', 'web');
    $user = User::factory()->create()->assignRole('store');

    $response = $this->actingAs($user)
        ->get('/a-route-that-does-not-exist')
        ->assertNotFound();

    // No sidebar and no notification bell — those are what would make an error
    // page depend on the very thing that may have just failed.
    $response->assertDontSee('sidebar-nav-link', false)
        ->assertDontSee('bi-bell', false);
});

it('renders for a guest without touching the session user', function () {
    $this->get('/a-route-that-does-not-exist')
        ->assertNotFound()
        ->assertSee('Sign in')
        ->assertDontSee('Signed in as');
});
