<?php

use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Spatie\Permission\Models\Permission;

/**
 * Correcting a recorded issue.
 *
 * The permission existed and was ticked in the matrix; the screen had no button
 * and the controller had no method, so a role granted the right could not use
 * it. These cover the three things that decide whether the correction is safe:
 *
 *   - It is gated exactly as Delete is, server-side, on either the section
 *     permission or the flat one.
 *   - It cannot take an item below zero — the rule Create already enforces —
 *     while still allowing an edit that only LOOKS like an overdraw because the
 *     balance still counts the row's own old quantity.
 *   - It cannot move an issue to a different item.
 *
 * Stock balance is derived, never stored: the Consumable Stock Report sums
 * stock_purchases and stock_issues at read time. So there is no recalculation
 * to assert — the tests read the balance back through the same service the
 * report uses and check the figure moved.
 *
 * In-memory sqlite. No real record touched.
 */
function editableItem(string $name = 'Sewing Needle', string $uom = 'Pkt'): StockItem
{
    return StockItem::create([
        'name' => $name, 'uom' => $uom, 'category' => 'Needle', 'opening_qty' => 0,
    ]);
}

function editStockOnHand(StockItem $item, float $qty): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => $qty,
        'unit_price' => 1,
    ]);
}

function issueEditor(array $permissions = ['store.issues.view', 'store.issues.edit']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

/** The figure the Stock Report prints, read through the same service. */
function issueBalance(StockItem $item): float
{
    return (float) app(GeneralStockReportService::class)
        ->rows(now()->startOfMonth(), ['item_ids' => [$item->id], 'only_active' => false])
        ->first()['stock_as_on'];
}

function recordedIssue(StockItem $item, float $qty = 10): StockIssue
{
    return StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => $qty,
        'requisition_no' => 'REQ-1',
    ]);
}

// --- Permission: gated exactly as Delete is ---------------------------------

it('refuses an update from a user without the edit right', function () {
    $item = editableItem();
    editStockOnHand($item, 100);
    $issue = recordedIssue($item, 10);

    // View only — the right that lets them see the screen, not correct it.
    $viewer = issueEditor(['store.issues.view']);

    $this->actingAs($viewer)
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 99,
        ])
        ->assertForbidden();

    expect((float) $issue->fresh()->qty)->toBe(10.0);
});

it('accepts the section permission and the flat one alike', function () {
    $item = editableItem();
    editStockOnHand($item, 100);

    foreach (['store.issues.edit', 'store.edit'] as $permission) {
        $issue = recordedIssue($item, 10);
        $user = issueEditor(['store.issues.view', $permission]);

        $this->actingAs($user)
            ->put(route('store.stock.issues.update', $issue), [
                'issue_date' => $issue->issue_date->toDateString(),
                'qty' => 12,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect((float) $issue->fresh()->qty)->toBe(12.0);
    }
});

it('hides the Edit button from a user who cannot use it', function () {
    $item = editableItem();
    editStockOnHand($item, 100);
    recordedIssue($item, 10);

    $viewer = issueEditor(['store.issues.view']);

    $this->actingAs($viewer)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertDontSee('editIssue', false);

    $editor = issueEditor(['store.issues.view', 'store.issues.edit']);

    // Shown with a visible label, not a bare icon.
    $this->actingAs($editor)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertSee('editIssue', false)
        ->assertSee('>Edit', false);
});

// --- The balance rule -------------------------------------------------------

it('lets an edit raise a quantity when the stock is genuinely there', function () {
    $item = editableItem();
    editStockOnHand($item, 100);
    $issue = recordedIssue($item, 10);

    expect(issueBalance($item))->toBe(90.0);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 25,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    // Derived, so the report moved with no recalculation step anywhere.
    expect((float) $issue->fresh()->qty)->toBe(25.0)
        ->and(issueBalance($item))->toBe(75.0);
});

it('discounts the row s own quantity, so a full re-issue of it is not refused', function () {
    // THE CASE THAT MAKES THE SELF-EXCLUSION NECESSARY. 100 received, all 100
    // already issued by this very row. Editing it to 100 is a no-op, and
    // editing it to 90 releases stock — but the balance still counts the old
    // 100 as gone, so a naive check sees 0 available and refuses both.
    $item = editableItem();
    editStockOnHand($item, 100);
    $issue = recordedIssue($item, 100);

    expect(issueBalance($item))->toBe(0.0);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 90,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect((float) $issue->fresh()->qty)->toBe(90.0)
        ->and(issueBalance($item))->toBe(10.0);
});

it('refuses an edit that would take the item below zero', function () {
    $item = editableItem();
    editStockOnHand($item, 100);
    $issue = recordedIssue($item, 10);

    // A second issue holds 85 of the 100, leaving 5 spare beyond this row's own
    // 10 — so 15 is the most this row can become.
    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => 85,
        'requisition_no' => 'REQ-2',
    ]);

    $this->actingAs(issueEditor())
        ->from(route('store.stock.issues.index'))
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 16,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('qty');

    // Refused whole: nothing written, balance untouched.
    expect((float) $issue->fresh()->qty)->toBe(10.0)
        ->and(issueBalance($item))->toBe(5.0);

    // And 15 exactly — the last affordable figure — still goes through.
    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 15,
        ])
        ->assertSessionHasNoErrors();

    expect(issueBalance($item))->toBe(0.0);
});

it('does not light up the Record Issue form s own warning when an edit is refused', function () {
    // That banner sits inside the create form. Filling it after a rejected
    // correction would accuse a form the user never touched.
    $item = editableItem();
    editStockOnHand($item, 10);
    $issue = recordedIssue($item, 10);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 50,
        ])
        ->assertSessionHasErrors('qty')
        ->assertSessionMissing('issue_stock_errors');
});

// --- What may and may not change --------------------------------------------

it('ignores an attempt to move the issue to another item', function () {
    $needle = editableItem();
    $thread = editableItem('Sewing Thread', 'Cone');
    editStockOnHand($needle, 100);
    editStockOnHand($thread, 100);

    $issue = recordedIssue($needle, 10);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 10,
            // Not a field of this form. A hand-crafted request naming it must
            // be ignored, not honoured: it is one balance undone and another
            // created, which is a delete and a re-record.
            'stock_item_id' => $thread->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($issue->fresh()->stock_item_id)->toBe($needle->id)
        ->and(issueBalance($needle))->toBe(90.0)
        ->and(issueBalance($thread))->toBe(100.0);
});

it('updates the header fields and keeps the denormalised copies in step', function () {
    $item = editableItem();
    editStockOnHand($item, 100);
    $issue = recordedIssue($item, 10);

    $section = IndentSection::create(['name' => 'Finishing', 'is_active' => true]);
    $person = IndentPerson::create(['name' => 'Rafiq Islam', 'is_active' => true]);
    $category = ItemCategory::create(['name' => 'Consumable', 'is_active' => true]);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => '2026-08-09',
            'qty' => 10,
            'indent_section_id' => $section->id,
            'indent_person_id' => $person->id,
            'item_category_id' => $category->id,
            'requisition_no' => 'REQ-CORRECTED',
            'requisition_type' => 'Replace',
            'remarks' => 'Corrected after stock count',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $fresh = $issue->fresh();

    expect($fresh->issue_date->toDateString())->toBe('2026-08-09')
        ->and($fresh->indent_section_id)->toBe($section->id)
        ->and($fresh->item_category_id)->toBe($category->id)
        ->and($fresh->requisition_no)->toBe('REQ-CORRECTED')
        ->and($fresh->requisition_type)->toBe('Replace')
        ->and($fresh->remarks)->toBe('Corrected after stock count')
        // The older free-text columns follow the masters, exactly as the create
        // form writes them, or they would still name the old section.
        ->and($fresh->department)->toBe('Finishing')
        ->and($fresh->issued_to)->toBe('Rafiq Islam');
});

it('refuses an unknown master or a bad requisition type', function () {
    $item = editableItem();
    editStockOnHand($item, 100);
    $issue = recordedIssue($item, 10);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $issue), [
            'issue_date' => $issue->issue_date->toDateString(),
            'qty' => 10,
            'indent_section_id' => 9999,
            'requisition_type' => 'Borrowed',
        ])
        ->assertSessionHasErrors(['indent_section_id', 'requisition_type']);
});

it('edits one row of a requisition without touching its siblings', function () {
    // A requisition is several rows sharing a header, and the table lists it a
    // row at a time. Editing the row the user clicked must leave the rest alone.
    $needle = editableItem();
    $thread = editableItem('Sewing Thread', 'Cone');
    editStockOnHand($needle, 100);
    editStockOnHand($thread, 100);

    $header = ['issue_date' => now()->startOfMonth()->toDateString(), 'requisition_no' => 'REQ-7'];

    $first = StockIssue::create($header + ['stock_item_id' => $needle->id, 'qty' => 5]);
    $second = StockIssue::create($header + ['stock_item_id' => $thread->id, 'qty' => 3]);

    $this->actingAs(issueEditor())
        ->put(route('store.stock.issues.update', $first), [
            'issue_date' => $header['issue_date'],
            'qty' => 9,
            'requisition_no' => 'REQ-7',
        ])
        ->assertSessionHas('success');

    expect((float) $first->fresh()->qty)->toBe(9.0)
        ->and((float) $second->fresh()->qty)->toBe(3.0)
        ->and($second->fresh()->requisition_no)->toBe('REQ-7');
});
