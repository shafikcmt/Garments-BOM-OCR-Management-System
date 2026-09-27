<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
});

test('profile can be saved with the email address unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertSame('Test User', $user->refresh()->name);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});

test('designation is shown read-only on the profile and cannot be changed from it', function () {
    $user = User::factory()->create(['designation' => 'Senior Merchandiser']);

    // Displayed, but never as an editable input: it is the administrator's
    // to set, like Role and Department.
    $html = $this->actingAs($user)->get('/profile')->assertOk()->getContent();

    expect($html)
        ->toContain('Senior Merchandiser')
        ->not->toContain('name="designation"')
        ->toContain('Role, Department, Designation and Status are managed by the administrator.');

    // Server-side, not just hidden: a posted designation is ignored.
    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'designation' => 'Managing Director',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    expect($user->fresh()->designation)->toBe('Senior Merchandiser');
});
