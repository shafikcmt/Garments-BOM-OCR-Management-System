<?php

use App\Models\Employee;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The Employee ID box on Admin > Users, and the employees record it links.
 *
 *   - Typing an ID creates the employee record and links it; the same ID
 *     twice is refused; clearing the box unlinks (never deletes) the record;
 *     typing it back reclaims it.
 *   - Only a super admin's form carries the box; a department admin's post
 *     of it is refused, and their ordinary save leaves the link alone.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(function () {
    foreach (['admin', 'merchant', 'production'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->admin = User::factory()->create()->assignRole('admin');
    $this->worker = User::factory()->create()->assignRole('production');
});

function employeePayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'status' => '1',
        'department' => 'production',
        'role' => 'production',
    ], $overrides);
}

it('creates and links an employee record when an ID is typed on edit', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker, ['employee_id' => ' 074047 ']))
        ->assertRedirect(route('admin.users.edit', $this->worker));

    $employee = Employee::where('employee_id', '074047')->first();

    expect($employee)->not->toBeNull()
        ->and($employee->user_id)->toBe($this->worker->id)
        ->and($employee->name)->toBe($this->worker->name)
        ->and($employee->is_active)->toBeTrue()
        ->and($this->worker->fresh()->employee?->employee_id)->toBe('074047');

    // Shown back on the form.
    $this->actingAs($this->admin)
        ->get(route('admin.users.edit', $this->worker))
        ->assertOk()
        ->assertSee('value="074047"', false);
});

it('creates the record on user creation too', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'name' => 'Floor Worker', 'email' => '074048@staff.test', 'password' => 'secret1',
            'status' => '1', 'department' => 'production', 'role' => 'production',
            'employee_id' => '074048',
        ])
        ->assertRedirect(route('admin.users.index'));

    expect(Employee::where('employee_id', '074048')->first()?->user?->email)->toBe('074048@staff.test');
});

it('refuses an ID already linked to another user, and does not create the account', function () {
    Employee::create(['employee_id' => '074047', 'user_id' => $this->worker->id, 'name' => 'x']);
    $other = User::factory()->create()->assignRole('production');

    $this->actingAs($this->admin)
        ->from(route('admin.users.edit', $other))
        ->put(route('admin.users.update', $other), employeePayload($other, ['employee_id' => '074047']))
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($this->admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), [
            'name' => 'Dup', 'email' => 'dup@staff.test', 'password' => 'secret1',
            'status' => '1', 'department' => 'production', 'role' => 'production',
            'employee_id' => '074047',
        ])
        ->assertSessionHasErrors('employee_id');

    expect(User::where('email', 'dup@staff.test')->exists())->toBeFalse()
        ->and(Employee::where('employee_id', '074047')->value('user_id'))->toBe($this->worker->id);
});

it('unlinks rather than deletes when the box is cleared, and reclaims the record when typed back', function () {
    Employee::create(['employee_id' => '074047', 'user_id' => $this->worker->id, 'name' => 'x']);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker, ['employee_id' => '']))
        ->assertRedirect();

    expect(Employee::where('employee_id', '074047')->value('user_id'))->toBeNull()
        ->and($this->worker->fresh()->employee)->toBeNull();

    // Another account may now take the orphaned ID …
    $other = User::factory()->create()->assignRole('production');
    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $other), employeePayload($other, ['employee_id' => '074047']))
        ->assertRedirect();

    expect(Employee::where('employee_id', '074047')->count())->toBe(1)
        ->and(Employee::where('employee_id', '074047')->value('user_id'))->toBe($other->id);
});

it('moves the link when the ID is changed, leaving the old record on the books', function () {
    Employee::create(['employee_id' => '074047', 'user_id' => $this->worker->id, 'name' => 'x']);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker, ['employee_id' => 'HAPL-0231']))
        ->assertRedirect();

    expect(Employee::where('employee_id', '074047')->value('user_id'))->toBeNull()
        ->and(Employee::where('employee_id', 'HAPL-0231')->value('user_id'))->toBe($this->worker->id);
});

it('refuses the field from a department admin and leaves the link alone on their ordinary save', function () {
    Employee::create(['employee_id' => '074047', 'user_id' => $this->worker->id, 'name' => 'x']);
    $deptAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('production');

    $this->actingAs($deptAdmin)
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker, ['employee_id' => '999999']))
        ->assertForbidden();

    $this->actingAs($deptAdmin)
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker))
        ->assertRedirect();

    expect(Employee::where('employee_id', '074047')->value('user_id'))->toBe($this->worker->id);

    $this->actingAs($deptAdmin)
        ->get(route('admin.users.edit', $this->worker))
        ->assertOk()
        ->assertDontSee('name="employee_id"', false);
});

it('saves a phone number with the user on create and edit, and lists the Employee ID', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), [
            'name' => 'Store Keeper', 'email' => 'keeper@staff.test', 'password' => 'secret1',
            'status' => '1', 'department' => 'production', 'role' => 'production',
            'employee_id' => 'HAPL-0231', 'phone' => '+880 1711-000000',
        ])
        ->assertRedirect(route('admin.users.index'));

    $created = User::where('email', 'keeper@staff.test')->first();
    expect($created->phone)->toBe('+880 1711-000000')
        ->and($created->employee?->employee_id)->toBe('HAPL-0231');

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $created), employeePayload($created, ['phone' => '01811000000']))
        ->assertSessionHasNoErrors();

    expect($created->fresh()->phone)->toBe('01811000000');

    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('HAPL-0231');
});

it('refuses a malformed Employee ID or phone on the admin form', function () {
    $this->actingAs($this->admin)
        ->from(route('admin.users.edit', $this->worker))
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker, ['phone' => 'call me']))
        ->assertSessionHasErrors('phone');

    $this->actingAs($this->admin)
        ->from(route('admin.users.edit', $this->worker))
        ->put(route('admin.users.update', $this->worker), employeePayload($this->worker, ['employee_id' => '074 047/A']))
        ->assertSessionHasErrors(['employee_id' => 'Employee ID may contain only letters, numbers and dashes.']);

    expect(Employee::count())->toBe(0);
});

it('stores the Employee ID uppercased and treats a case-variant as the same ID', function () {
    Employee::create(['employee_id' => 'HAPL-0231', 'user_id' => $this->worker->id, 'name' => 'x']);
    $other = User::factory()->create()->assignRole('production');

    $this->actingAs($this->admin)
        ->from(route('admin.users.edit', $other))
        ->put(route('admin.users.update', $other), employeePayload($other, ['employee_id' => 'hapl-0231']))
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $other), employeePayload($other, ['employee_id' => 'hapl-0500']))
        ->assertSessionHasNoErrors();

    expect($other->fresh()->employee->employee_id)->toBe('HAPL-0500');
});
