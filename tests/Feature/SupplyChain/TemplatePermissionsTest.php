<?php

use App\Models\BookingInstruction;
use App\Models\EmailTemplate;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Supply Chain maintaining the PO instruction library and the email templates.
 *
 * THE LINE THIS PINS is not "can Supply Chain reach the screen" — that is one
 * route guard and it would be obvious if it broke. It is the narrower half:
 * two fields on those screens stay administrator-only, and the enforcement has
 * to live on the server, because the only thing stopping the browser is a
 * disabled input.
 *
 *   is_default            promotes an instruction to something that prints on
 *                         every matching PO with nobody asking for it.
 *   default_to / cc       the standing recipient list. Editing it silently
 *                         redirects future mail.
 *
 * So every test below that matters POSTS THE FORBIDDEN VALUE DIRECTLY rather
 * than driving the form, which is exactly what a stale page or a hand-made
 * request would do. A test that only checked the rendered HTML would pass
 * against a controller that enforces nothing at all.
 *
 * In-memory sqlite. No real record touched.
 */

/**
 * The three roles plus the migration that grants the permissions.
 *
 * The roles come from RoleSeeder, not from a migration, so on the test suite's
 * sqlite they do not exist when the migration runs and its grants are skipped.
 * Creating them here and re-running up() is what the other permission tests in
 * this project do, and it has the useful side effect that the grant under test
 * is the real migration rather than a givePermissionTo() written by the test.
 */
beforeEach(function () {
    foreach (['admin', 'supply_chain', 'store'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $migration = require database_path('migrations/2026_09_06_000001_seed_supply_chain_template_permissions.php');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A Supply Chain user, holding only what the role gives. */
function scTemplateUser(): User
{
    return User::factory()->create()->assignRole('supply_chain')->fresh();
}

function adminTemplateUser(): User
{
    return User::factory()->create()->assignRole('admin')->fresh();
}

function suggestionRow(array $overrides = []): BookingInstruction
{
    return BookingInstruction::create(array_merge([
        'instruction' => 'Please share the test report before shipment.',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 10,
    ], $overrides));
}

/* ------------------------------------------------------------------ *
 |  The grant itself
 * ------------------------------------------------------------------ */

it('grants both template permissions to the supply chain role', function () {
    $user = User::factory()->create()->assignRole('supply_chain');

    expect($user->fresh()->can('booking.instructions.manage'))->toBeTrue()
        ->and($user->fresh()->can('email.templates.edit'))->toBeTrue();
});

it('leaves other roles without the template permissions', function () {
    $user = User::factory()->create()->assignRole('store');

    expect($user->fresh()->can('booking.instructions.manage'))->toBeFalse()
        ->and($user->fresh()->can('email.templates.edit'))->toBeFalse();
});

/* ------------------------------------------------------------------ *
 |  Booking instructions — what Supply Chain MAY do
 * ------------------------------------------------------------------ */

it('lets a permission holder open the instruction library', function () {
    $this->actingAs(scTemplateUser())
        ->get(route('admin.booking-instructions.index'))
        ->assertOk();
});

it('lets a permission holder create a suggestion', function () {
    $this->actingAs(scTemplateUser())
        ->post(route('admin.booking-instructions.store'), [
            'instruction' => 'Attach the packing list with every carton.',
            'instruction_type' => 'suggestion',
            'sort_order' => 20,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.booking-instructions.index'));

    $row = BookingInstruction::where('instruction', 'Attach the packing list with every carton.')->first();

    expect($row)->not->toBeNull()
        ->and($row->is_default)->toBeFalse();
});

it('lets a permission holder edit and delete a suggestion', function () {
    $user = scTemplateUser();
    $row = suggestionRow();

    $this->actingAs($user)
        ->put(route('admin.booking-instructions.update', $row), [
            'instruction' => 'Reworded suggestion.',
            'instruction_type' => 'suggestion',
            'sort_order' => 10,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.booking-instructions.index'));

    expect($row->fresh()->instruction)->toBe('Reworded suggestion.');

    $this->actingAs($user)
        ->delete(route('admin.booking-instructions.destroy', $row))
        ->assertRedirect(route('admin.booking-instructions.index'));

    expect(BookingInstruction::find($row->id))->toBeNull();
});

/* ------------------------------------------------------------------ *
 |  Booking instructions — what Supply Chain MAY NOT do
 * ------------------------------------------------------------------ */

it('refuses to let a permission holder create a default instruction', function () {
    $this->actingAs(scTemplateUser())
        ->post(route('admin.booking-instructions.store'), [
            'instruction' => 'Sneaking in as a default.',
            'instruction_type' => 'default',
            'sort_order' => 30,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.booking-instructions.index'));

    // Saved, but demoted. Refusing the whole request would lose the text; the
    // business rule is about is_default, not about the instruction existing.
    expect(BookingInstruction::where('instruction', 'Sneaking in as a default.')->first()->is_default)
        ->toBeFalse();
});

it('refuses to let a permission holder promote a suggestion to default', function () {
    $row = suggestionRow();

    $this->actingAs(scTemplateUser())
        ->put(route('admin.booking-instructions.update', $row), [
            'instruction' => $row->instruction,
            'instruction_type' => 'default',
            'sort_order' => 10,
            'is_active' => 1,
        ]);

    expect($row->fresh()->is_default)->toBeFalse();
});

it('blocks a permission holder from editing or deleting an existing default', function () {
    $user = scTemplateUser();
    $default = suggestionRow(['is_default' => true, 'instruction' => 'Company-wide clause.']);

    $this->actingAs($user)
        ->get(route('admin.booking-instructions.edit', $default))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('admin.booking-instructions.update', $default), [
            'instruction' => 'Rewritten without permission.',
            'instruction_type' => 'suggestion',
            'sort_order' => 10,
            'is_active' => 1,
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('admin.booking-instructions.destroy', $default))
        ->assertForbidden();

    expect($default->fresh())->not->toBeNull()
        ->and($default->fresh()->instruction)->toBe('Company-wide clause.')
        ->and($default->fresh()->is_default)->toBeTrue();
});

it('keeps a default instruction fully editable by an admin', function () {
    $admin = adminTemplateUser();
    $default = suggestionRow(['is_default' => true]);

    $this->actingAs($admin)
        ->get(route('admin.booking-instructions.edit', $default))
        ->assertOk();

    $this->actingAs($admin)
        ->put(route('admin.booking-instructions.update', $default), [
            'instruction' => 'Admin rewrote this.',
            'instruction_type' => 'default',
            'sort_order' => 10,
            'is_active' => 1,
        ]);

    expect($default->fresh()->instruction)->toBe('Admin rewrote this.')
        ->and($default->fresh()->is_default)->toBeTrue();
});

it('shuts a user with neither the role nor the permission out entirely', function () {
    $this->actingAs(User::factory()->create()->assignRole('store'))
        ->get(route('admin.booking-instructions.index'))
        ->assertForbidden();
});

/* ------------------------------------------------------------------ *
 |  Email templates
 * ------------------------------------------------------------------ */

/**
 * The PRA template, reset to a known starting point.
 *
 * updateOrCreate, not create: a migration already seeds both template rows and
 * `type` is unique, so a plain create collides.
 */
function praTemplateRow(): EmailTemplate
{
    return EmailTemplate::updateOrCreate(['type' => 'pra'], [
        'name' => 'Payment Request Approval (PRA)',
        'subject' => 'Original subject',
        'default_to' => 'accounts@example.com',
        'default_cc' => 'manager@example.com',
        'body' => '<p>Original body</p>',
    ]);
}

it('lets a permission holder open the email templates screen', function () {
    $this->actingAs(scTemplateUser())
        ->get(route('admin.email-templates.edit'))
        ->assertOk();
});

it('lets a permission holder change a template subject and body', function () {
    $template = praTemplateRow();

    $this->actingAs(scTemplateUser())
        ->put(route('admin.email-templates.update'), [
            'type' => 'pra',
            'subject' => 'New subject {{pr_number}}',
            'body' => '<p>New body</p>',
        ])
        ->assertRedirect(route('admin.email-templates.edit'));

    expect($template->fresh()->subject)->toBe('New subject {{pr_number}}')
        ->and($template->fresh()->body)->toBe('<p>New body</p>');
});

it('ignores recipient lists posted by a permission holder', function () {
    $template = praTemplateRow();

    $this->actingAs(scTemplateUser())
        ->put(route('admin.email-templates.update'), [
            'type' => 'pra',
            'subject' => 'Still fine to change',
            'body' => '<p>Still fine to change</p>',
            'default_to' => 'attacker@example.com',
            'default_cc' => 'attacker-cc@example.com',
        ]);

    // The wording change lands; the recipient list does not move.
    expect($template->fresh()->subject)->toBe('Still fine to change')
        ->and($template->fresh()->default_to)->toBe('accounts@example.com')
        ->and($template->fresh()->default_cc)->toBe('manager@example.com');
});

it('still lets an admin change the recipient lists', function () {
    $template = praTemplateRow();

    $this->actingAs(adminTemplateUser())
        ->put(route('admin.email-templates.update'), [
            'type' => 'pra',
            'subject' => 'Admin subject',
            'body' => '<p>Admin body</p>',
            'default_to' => 'newteam@example.com',
            'default_cc' => '',
        ]);

    expect($template->fresh()->default_to)->toBe('newteam@example.com')
        ->and($template->fresh()->default_cc)->toBeNull();
});

it('records the permission holder as the last editor', function () {
    $user = scTemplateUser();

    $this->actingAs($user)
        ->put(route('admin.email-templates.update'), [
            'type' => 'po_booking',
            'subject' => 'Edited by supply chain',
            'body' => '<p>Edited by supply chain</p>',
        ]);

    // updated_by only. created_by is set on the first save of a type, and both
    // rows are seeded by a migration, so their author is legitimately null —
    // the same case the controller documents and deliberately does not invent.
    expect(EmailTemplate::forType('po_booking')->updated_by)->toBe($user->id);
});

it('shuts a user without the email permission out of the templates screen', function () {
    $this->actingAs(User::factory()->create()->assignRole('store'))
        ->get(route('admin.email-templates.edit'))
        ->assertForbidden();
});
