<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Department-scoped User Management.
 *
 * A Store Admin runs the same screen a super admin does, over Store users
 * only. Everything worth testing here is a refusal, and each one is tested by
 * posting the thing the form does not offer — because the form not offering it
 * is not the control. The control is the 403.
 *
 * These run on the in-memory sqlite database configured in phpunit.xml, so no
 * real user record is touched by any of it.
 */
beforeEach(function () {
    foreach (['admin', 'store', 'store_general_stock', 'commercial'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    foreach (['store.view', 'store.edit', 'users.delete', 'payments.approve'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    // What a Store Admin holds, and therefore the most they can pass on.
    Role::findByName('store')->givePermissionTo(['store.view', 'store.edit']);
});

/** A Store Admin: the flag, plus a role that maps to the Store department. */
function scopedStoreAdmin(): User
{
    $user = User::factory()->create(['status' => 1, 'is_department_admin' => true]);

    return $user->assignRole('store');
}

function scopedSuperAdmin(): User
{
    return User::factory()->create(['status' => 1])->assignRole('admin');
}

function scopedStoreMember(): User
{
    return User::factory()->create(['status' => 1])->assignRole('store_general_stock');
}

function scopedCommercialMember(): User
{
    return User::factory()->create(['status' => 1])->assignRole('commercial');
}

/** The fields the form always posts, so each test varies only what it is about. */
function scopedUserPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Throwaway Person',
        'email' => 'throwaway'.uniqid().'@example.test',
        'password' => 'secret123',
        'status' => 1,
        'department' => 'store',
        'role' => 'store_general_stock',
    ], $overrides);
}

it('lets a store admin create a store user', function () {
    $payload = scopedUserPayload();

    $this->actingAs(scopedStoreAdmin())
        ->post(route('admin.users.store'), $payload)
        ->assertRedirect(route('admin.users.index'));

    $created = User::where('email', $payload['email'])->firstOrFail();

    expect($created->getRoleNames()->all())->toBe(['store_general_stock'])
        // Department is not a column — it is the role, and the role is Store's.
        ->and($created->departmentKey())->toBe('store')
        ->and($created->is_department_admin)->toBeFalse();
});

it('refuses a store admin creating a user in another department', function () {
    $this->actingAs(scopedStoreAdmin())
        ->post(route('admin.users.store'), scopedUserPayload([
            'department' => 'commercial',
            'role' => 'commercial',
        ]))
        ->assertForbidden();

    expect(User::where('name', 'Throwaway Person')->exists())->toBeFalse();
});

it('refuses a store admin assigning a role outside their department', function () {
    // The department field still says store — only the role reaches out. This
    // is the post the filtered dropdown cannot produce.
    $this->actingAs(scopedStoreAdmin())
        ->post(route('admin.users.store'), scopedUserPayload(['role' => 'admin']))
        ->assertForbidden();

    expect(User::where('name', 'Throwaway Person')->exists())->toBeFalse();
});

it('refuses a store admin granting a permission they do not hold', function () {
    $admin = scopedStoreAdmin();

    expect($admin->can('payments.approve'))->toBeFalse();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), scopedUserPayload([
            'permissions' => ['store.view', 'payments.approve'],
        ]))
        ->assertForbidden();

    // Nothing partially applied: the whole request is refused before any write.
    expect(User::where('name', 'Throwaway Person')->exists())->toBeFalse();
});

it('lets a store admin grant a permission they do hold', function () {
    $payload = scopedUserPayload(['permissions' => ['store.edit']]);

    $this->actingAs(scopedStoreAdmin())
        ->post(route('admin.users.store'), $payload)
        ->assertRedirect();

    $created = User::where('email', $payload['email'])->firstOrFail();

    expect($created->getDirectPermissions()->pluck('name')->all())->toBe(['store.edit']);
});

it('refuses a store admin setting the department admin flag on anyone', function () {
    $admin = scopedStoreAdmin();
    $target = scopedStoreMember();

    // On someone else, at creation...
    $this->actingAs($admin)
        ->post(route('admin.users.store'), scopedUserPayload(['is_department_admin' => '1']))
        ->assertForbidden();

    // ...and on an existing user, on update.
    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), scopedUserPayload([
            'email' => $target->email,
            'is_department_admin' => '1',
        ]))
        ->assertForbidden();

    expect($target->fresh()->is_department_admin)->toBeFalse();
});

it('refuses a store admin promoting themselves', function () {
    $admin = scopedStoreAdmin();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $admin), scopedUserPayload([
            'email' => $admin->email,
            'role' => 'store',
            'department_admin_control' => '1',
            'is_department_admin' => '1',
        ]))
        ->assertForbidden();
});

it('refuses a store admin reaching another department user by direct url', function () {
    $admin = scopedStoreAdmin();
    $outsider = scopedCommercialMember();

    $this->actingAs($admin)->get(route('admin.users.edit', $outsider))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.users.show', $outsider))->assertForbidden();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $outsider), scopedUserPayload(['email' => $outsider->email]))
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(route('admin.users.reset-password', $outsider), [
            'new_password' => 'newsecret123',
            'new_password_confirmation' => 'newsecret123',
        ])
        ->assertForbidden();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $outsider))->assertForbidden();

    expect($outsider->fresh()->name)->toBe($outsider->name);
});

it('refuses a store admin managing a super admin or another department admin', function () {
    $admin = scopedStoreAdmin();

    // A peer inside their own department, and a super admin outside it.
    $peer = User::factory()->create(['is_department_admin' => true])->assignRole('store');

    $this->actingAs($admin)->get(route('admin.users.edit', $peer))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.users.edit', scopedSuperAdmin()))->assertForbidden();
});

it('shows a store admin only their own department', function () {
    $admin = scopedStoreAdmin();
    $mine = scopedStoreMember();
    $theirs = scopedCommercialMember();
    $roleless = User::factory()->create();

    $users = $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->viewData('users');

    $ids = $users->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->toContain($admin->id)
        ->and($ids)->not->toContain($theirs->id)
        // A user belonging to no department is nobody's to manage but the
        // super admin's — otherwise "no department" would match every one.
        ->and($ids)->not->toContain($roleless->id);
});

it('renders the create and edit screens for a store admin, offering store roles only', function () {
    $admin = scopedStoreAdmin();
    $target = scopedStoreMember();

    $create = $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();

    // One department, no choice to make, and no promotion control.
    expect($create->viewData('departments'))->toBe(['store' => 'Store']);
    // Every Store role and no other — the three the department map lists.
    expect($create->viewData('roles')->pluck('name')->all())
        ->toBe(['store', 'store_general_stock', 'store_material_stock']);
    // The promotion control itself, not the words — "Department Admin" also
    // appears in the role dropdown's hint text, which is fine to show them.
    $create->assertDontSee('name="is_department_admin"', false)
        ->assertDontSee('name="department_admin_control"', false);

    // The matrix offers what this admin holds and nothing else — a module they
    // have no rights in is not on the page for them to tick.
    $edit = $this->actingAs($admin)->get(route('admin.users.edit', $target))->assertOk();

    $held = $admin->getAllPermissions()->pluck('name');

    foreach ([$create, $edit] as $screen) {
        $offered = collect($screen->viewData('permissionGroups'))
            ->flatMap(fn (array $group) => collect($group['rows'])->flatMap(
                fn (array $row) => collect($row['actions'])->merge($row['extra'])->pluck('name')
            ));

        expect($offered)->not->toBeEmpty()
            ->and($offered->diff($held))->toBeEmpty();
        // Nothing is drawn locked-for-reach any more, because nothing out of
        // reach is drawn at all.
        $screen->assertDontSee('not yours to grant');
    }

    // The breadcrumb must not point at the admin dashboard, which is still
    // role:admin and would 403 them straight back out.
    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertDontSee(route('admin.dashboard'), false);
});

it('never renders the department admin control to a department admin', function () {
    $admin = scopedStoreAdmin();
    $target = scopedStoreMember();

    // Not on create, not on edit, and not as any stray occurrence of the field
    // name anywhere in the markup — a disabled or hidden input would still be
    // a field, and this asserts there is none to find.
    foreach ([route('admin.users.create'), route('admin.users.edit', $target)] as $url) {
        $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('is_department_admin')
            ->and($html)->not->toContain('department_admin_control');
    }

    // A super admin, on the same screens, does get it.
    $html = $this->actingAs(scopedSuperAdmin())
        ->get(route('admin.users.edit', $target))->assertOk()->getContent();

    expect($html)->toContain('name="is_department_admin"');
});

it('puts Team Management in the store sidebar and leaves Users & Roles to the admin', function () {
    $admin = scopedStoreAdmin();

    // Same underlying route, a different door and a different label.
    $storeSidebar = $this->actingAs($admin)
        ->get(route('store.dashboard'))->assertOk()->getContent();

    expect($storeSidebar)->toContain('Team Management')
        ->and($storeSidebar)->toContain(route('admin.users.index'))
        ->and($storeSidebar)->not->toContain('Users &amp; Roles');

    // A plain store user has no reason to see it, and does not.
    $plain = $this->actingAs(scopedStoreMember())
        ->get(route('store.dashboard'))->assertOk()->getContent();

    expect($plain)->not->toContain('Team Management');

    $adminSidebar = $this->actingAs(scopedSuperAdmin())
        ->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($adminSidebar)->toContain('Users &amp; Roles')
        ->and($adminSidebar)->toContain(route('admin.users.index'))
        // No second link to the same screen for the super admin.
        ->and($adminSidebar)->not->toContain('Team Management');
});

/**
 * The flag that decides Team Management was never Store-specific, but for a
 * while the only <li> reading it was — so a Merchant department admin held
 * access to the screen with no way to reach it. The link belongs wherever the
 * flag is true.
 */
it('puts Team Management in the merchant sidebar too', function () {
    Role::findOrCreate('merchant', 'web');

    $merchantAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('merchant');

    $sidebar = $this->actingAs($merchantAdmin)
        ->get(route('merchant.dashboard'))->assertOk()->getContent();

    expect($sidebar)->toContain('Team Management')
        ->and($sidebar)->toContain(route('admin.users.index'));

    // A plain merchant holds no flag and is offered nothing.
    $plain = User::factory()->create()->assignRole('merchant');

    expect($this->actingAs($plain)->get(route('merchant.dashboard'))->assertOk()->getContent())
        ->not->toContain('Team Management');
});

/**
 * Ticking Department Admin is only half the setup for Merchandising — the
 * buyer is assigned on a different screen. An empty section read as "nothing
 * to do here", so the unassigned state says so and points at the screen.
 */
it('tells a super admin when a department admin still has no buyer', function () {
    Role::findOrCreate('merchant', 'web');

    $unassigned = User::factory()->create(['is_department_admin' => true])->assignRole('merchant');

    $html = $this->actingAs(scopedSuperAdmin())
        ->get(route('admin.users.edit', $unassigned))->assertOk()->getContent();

    expect($html)->toContain('Not assigned yet')
        ->and($html)->toContain(route('admin.buyers.index'));

    // A plain user has nothing to assign and is told nothing.
    $plain = User::factory()->create()->assignRole('merchant');

    expect($this->actingAs(scopedSuperAdmin())->get(route('admin.users.edit', $plain))->getContent())
        ->not->toContain('Not assigned yet');
});

it('keeps a plain store user out of the screen entirely', function () {
    // Same department, same role family, no flag — the flag is the capability.
    $this->actingAs(scopedStoreMember())->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs(scopedStoreMember())->get(route('admin.users.create'))->assertForbidden();
});

it('does not let a store admin quietly strip a grant they cannot see', function () {
    $admin = scopedStoreAdmin();
    $target = scopedStoreMember();

    // Given by a super admin, and outside the Store Admin's own rights.
    $target->givePermissionTo('payments.approve');

    // Their form posts only the boxes they can reach; the locked one is absent.
    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), scopedUserPayload([
            'email' => $target->email,
            'permissions' => ['store.edit'],
        ]))
        ->assertRedirect();

    $direct = $target->fresh()->getDirectPermissions()->pluck('name');

    expect($direct)->toContain('payments.approve')
        ->and($direct)->toContain('store.edit');
});

it('still lets a store admin revoke a grant that is within their reach', function () {
    // The other side of the preservation rule: it must keep what they cannot
    // reach without freezing what they can, or unticking a box would do nothing.
    $admin = scopedStoreAdmin();
    $target = scopedStoreMember();

    $target->givePermissionTo(['store.edit', 'payments.approve']);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), scopedUserPayload([
            'email' => $target->email,
            'permissions' => [],   // store.edit unticked
        ]))
        ->assertRedirect();

    $direct = $target->fresh()->getDirectPermissions()->pluck('name');

    expect($direct)->not->toContain('store.edit')
        ->and($direct)->toContain('payments.approve');
});

it('leaves the super admin screen exactly as it was', function () {
    $admin = scopedSuperAdmin();
    $payload = scopedUserPayload([
        'department' => 'commercial',
        'role' => 'commercial',
        // Everything a department admin is refused, in one request.
        'permissions' => ['payments.approve', 'users.delete'],
        'department_admin_control' => '1',
        'is_department_admin' => '1',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.users.store'), $payload)
        ->assertRedirect(route('admin.users.index'));

    $created = User::where('email', $payload['email'])->firstOrFail();

    expect($created->getRoleNames()->all())->toBe(['commercial'])
        ->and($created->is_department_admin)->toBeTrue()
        ->and($created->getDirectPermissions()->pluck('name')->sort()->values()->all())
            ->toBe(['payments.approve', 'users.delete']);

    // And they still see everybody, including the departments and the roleless.
    $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
    $this->actingAs($admin)->get(route('admin.users.edit', scopedCommercialMember()))->assertOk();
});

it('still offers a super admin the whole permission catalogue', function () {
    $admin = scopedSuperAdmin();

    $groups = $this->actingAs($admin)->get(route('admin.users.create'))
        ->assertOk()
        ->viewData('permissionGroups');

    $offered = collect($groups)->flatMap(fn (array $group) => collect($group['rows'])->flatMap(
        fn (array $row) => collect($row['actions'])->merge($row['extra'])->pluck('name')
    ));

    expect($offered->sort()->values()->all())
        ->toBe(Permission::orderBy('name')->pluck('name')->all());
});

it('still reports a user\'s full access on the read-only profile', function () {
    $admin = scopedStoreAdmin();
    $target = scopedStoreMember();

    // Granting is one thing, reporting is another: trimming this screen to the
    // viewer's own rights would under-report what the user actually holds.
    $groups = $this->actingAs($admin)->get(route('admin.users.show', $target))
        ->assertOk()
        ->viewData('permissionGroups');

    $offered = collect($groups)->flatMap(fn (array $group) => collect($group['rows'])->flatMap(
        fn (array $row) => collect($row['actions'])->merge($row['extra'])->pluck('name')
    ));

    expect($offered->diff($admin->getAllPermissions()->pluck('name')))->not->toBeEmpty();
});

it('lets a super admin demote a department admin but never loses the flag on an unrelated save', function () {
    $admin = scopedSuperAdmin();
    $deptAdmin = scopedStoreAdmin();

    // A save from a form that does show the control, with the box unticked.
    $this->actingAs($admin)
        ->put(route('admin.users.update', $deptAdmin), scopedUserPayload([
            'email' => $deptAdmin->email,
            'role' => 'store',
            'department_admin_control' => '1',
        ]))
        ->assertRedirect();

    expect($deptAdmin->fresh()->is_department_admin)->toBeFalse();
});
