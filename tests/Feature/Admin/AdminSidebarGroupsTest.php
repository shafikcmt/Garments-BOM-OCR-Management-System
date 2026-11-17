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
