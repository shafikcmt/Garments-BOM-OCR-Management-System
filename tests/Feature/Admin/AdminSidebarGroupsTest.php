<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The admin sidebar is grouped (Departments / Administration) so a long list
 * never pushes entries off the bottom. Every entry must still be reachable.
 */
it('keeps every admin menu entry, grouped under department and administration headings', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    foreach (['Departments', 'Administration', 'Merchandising', 'Supply Chain', 'Commercial', 'Requisition Setup', 'Settings'] as $label) {
        expect($html)->toContain('>'.$label.'<');
    }

    foreach ([
        'Style Status', 'OCR Summary', 'Samples', 'Inquiries', 'Meetings', 'Analytics', 'Consumption Master', 'Costing Templates', 'Merchandising Reports',
        'Supplier Delivery', 'Supply Chain Reports', 'Inbound Shipments', 'Commercial Reports',
        'Alert Settings', 'PI / PRA Settings', 'PRA Approvers', 'Email Templates',
        'Departments', 'Requisition Signatures', 'Monthly Requisition Window', 'Item SKU',
        'Excel Headers', 'Unmapped Columns', 'TnA Templates', 'Users', 'Roles', 'Vendors', 'Buyers',
    ] as $entry) {
        expect($html)->toContain('>'.$entry.'</a>');
    }
});

it('opens the group that holds the current page', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)->get(route('admin.requisition-signatures.edit'))->assertOk()->getContent();

    expect($html)->toMatch('/sidebar-group is-open[^>]*>\s*<button[^>]*>\s*<span class="sidebar-link-main">\s*<span class="sidebar-icon"><i class="bi bi-clipboard-check"/');
});

it('lets admin set the default CS signature boxes, which only new sheets use', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect(\App\Models\GsComparativeStatement::defaultSignatories())->toHaveCount(3);

    $this->actingAs($admin)->get(route('admin.cs-signatures.edit'))->assertOk()->assertSee('Jr. Executive - SCM');
    $this->actingAs($admin)->post(route('admin.cs-signatures.update'), ['boxes' => [
        ['label' => 'Buyer', 'role' => 'prepared'],
        ['label' => 'GM', 'role' => 'approved'],
        ['label' => '', 'role' => 'blank'],
    ]])->assertRedirect(route('admin.cs-signatures.edit'));

    expect(\App\Models\GsComparativeStatement::defaultSignatories())->toBe([
        ['label' => 'Buyer', 'role' => 'prepared'], ['label' => 'GM', 'role' => 'approved'],
    ]);

    // A sheet that stored nothing keeps the standard three, whatever the default now is.
    expect((new \App\Models\GsComparativeStatement)->signBoxes())->toHaveCount(3);

    $this->actingAs($admin)->post(route('admin.cs-signatures.update'), ['reset' => 1])->assertRedirect();
    expect(\App\Models\GsComparativeStatement::defaultSignatories())->toHaveCount(3);

    $this->actingAs(User::factory()->create())->get(route('admin.cs-signatures.edit'))->assertForbidden();
});

it('groups the merchant menu into folders and keeps every entry', function () {
    Role::findOrCreate('merchant', 'web');
    $merchant = User::factory()->create();
    $merchant->assignRole('merchant');

    $html = $this->actingAs($merchant)->get(route('merchant.workspace'))->assertOk()->getContent();

    foreach (['Buyer Desk', 'Costing &amp; Materials', 'Reports &amp; Status'] as $folder) {
        expect($html)->toContain('>'.$folder.'<');
    }

    foreach (['Style Library', 'Workspace', 'Inquiries', 'Samples', 'Meetings', 'Consumption Master', 'Costing Templates', 'Vendors', 'Style Status', 'Reports', 'Analytics', 'OCR Summary', 'Store Reports'] as $entry) {
        expect($html)->toContain('>'.$entry.'<');
    }
});
