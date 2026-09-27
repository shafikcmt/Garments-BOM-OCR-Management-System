<?php

use App\Models\Employee;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Employee ID and Phone on My Profile.
 *
 *   - Phone: always editable by the user, same rules as Admin > Users.
 *   - Employee ID: self-set once while none is linked; then read-only, and
 *     a posted value is ignored. An ID that exists on ANY employee record
 *     (linked or not) is refused, so nobody can claim another person's ID.
 *
 * In-memory sqlite. No real record touched.
 */
function profilePayload(User $user, array $overrides = []): array
{
    return array_merge(['name' => $user->name, 'email' => $user->email], $overrides);
}

it('lets every role save a phone number from the profile', function (string $role) {
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create()->assignRole($role);

    $this->actingAs($user)
        ->patch('/profile', profilePayload($user, ['phone' => '+880 1711-000000']))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    expect($user->fresh()->phone)->toBe('+880 1711-000000');

    $this->actingAs($user)->get('/profile')->assertOk()->assertSee('value="+880 1711-000000"', false);
})->with(['admin', 'merchant', 'store', 'commercial', 'accounts']);

it('clears the phone when the box is emptied', function () {
    $user = User::factory()->create(['phone' => '01711000000']);

    $this->actingAs($user)->patch('/profile', profilePayload($user, ['phone' => '']))->assertSessionHasNoErrors();

    expect($user->fresh()->phone)->toBeNull();
});

it('refuses a malformed phone number', function (string $phone) {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')
        ->patch('/profile', profilePayload($user, ['phone' => $phone]))
        ->assertSessionHasErrors('phone');
})->with(['abc123', '12345', '+880 1711 000000 123456']);

it('lets a user without an Employee ID add one, then shows it read-only', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')->assertOk()->assertSee('name="employee_id"', false);

    $this->actingAs($user)
        ->patch('/profile', profilePayload($user, ['employee_id' => 'HAPL-0231']))
        ->assertSessionHasNoErrors();

    $employee = $user->fresh()->employee;
    expect($employee?->employee_id)->toBe('HAPL-0231')
        ->and($employee->is_active)->toBeTrue()
        ->and($employee->name)->toBe($user->name);

    $html = $this->actingAs($user->fresh())->get('/profile')->assertOk()->getContent();
    expect($html)
        ->toContain('value="HAPL-0231"')
        ->not->toContain('name="employee_id"')
        ->toContain('Employee ID, Role, Department, Designation and Status are managed by the administrator.');

    // A posted change is ignored, not applied.
    $this->actingAs($user->fresh())
        ->patch('/profile', profilePayload($user, ['employee_id' => 'HAPL-9999']))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->employee->employee_id)->toBe('HAPL-0231')
        ->and(Employee::where('employee_id', 'HAPL-9999')->exists())->toBeFalse();
});

it('signs in with an Employee ID the user set on their profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patch('/profile', profilePayload($user, ['employee_id' => '074099']));
    auth()->logout();

    $this->post('/login', ['email' => '074099', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('refuses an Employee ID already used by another user or an unlinked HR record', function (bool $linked) {
    $other = User::factory()->create();
    Employee::create(['employee_id' => '074047', 'user_id' => $linked ? $other->id : null, 'name' => 'HR Person']);
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')
        ->patch('/profile', profilePayload($user, ['employee_id' => '074047']))
        ->assertSessionHasErrors(['employee_id' => 'This Employee ID is already in use. Please contact the administrator.']);

    expect($user->fresh()->employee)->toBeNull();
})->with(['linked to another user' => true, 'unlinked HR record' => false]);

it('refuses an Employee ID with characters other than letters, numbers and dashes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')
        ->patch('/profile', profilePayload($user, ['employee_id' => '074 047/A']))
        ->assertSessionHasErrors(['employee_id' => 'Employee ID may contain only letters, numbers and dashes.']);
});

it('stores a self-set Employee ID uppercased and refuses a case-variant of an existing one', function () {
    Employee::create(['employee_id' => 'HAPL-0231', 'user_id' => null, 'name' => 'HR Person']);
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')
        ->patch('/profile', profilePayload($user, ['employee_id' => ' hapl-0231 ']))
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($user)
        ->patch('/profile', profilePayload($user, ['employee_id' => 'hapl-0999']))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->employee->employee_id)->toBe('HAPL-0999');
});
