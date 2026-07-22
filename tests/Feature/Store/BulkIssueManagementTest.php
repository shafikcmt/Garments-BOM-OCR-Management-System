<?php

use App\Models\MaterialBulkIssue;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Bulk Issue management surface: server-driven history (tabs/search/partial),
 * the edit read/update endpoints, bulk delete, and the Excel/PDF selection
 * exports. Each create/update/delete also exercises the ledger trigger.
 */
function bulkIssueStoreUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

function makeBookingPo(): \App\Models\BookingPo
{
    $file = \App\Models\ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'completed',
        'uploaded_by' => User::factory()->create()->id,
    ]);
    $row = \App\Models\ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    return \App\Models\BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'po_no' => 'PO-'.fake()->numerify('####'),
        'buyer_name' => 'Hugo Boss',
        'style_name' => 'STYLE-1',
        'item_name' => 'Accessories',
    ]);
}

function makeIssue(array $overrides = []): MaterialBulkIssue
{
    return MaterialBulkIssue::create(array_merge([
        'po_no' => 'PO-'.fake()->numerify('####'),
        'buyer_name' => 'Hugo Boss',
        'season_name' => 'W26FA',
        'style_name' => 'STYLE-1',
        'material_name' => 'Accessories',
        'material_description' => 'Woven label',
        'sap_code' => 'SAP-1',
        'issue_no' => 'BI-1',
        'issue_date' => now()->toDateString(),
        'bulk_qty' => 10,
        'sample_qty' => 0,
        'liability_qty' => 0,
        'dead_qty' => 0,
    ], $overrides));
}

it('filters history by tab and search and returns the partial', function () {
    $user = bulkIssueStoreUser();

    makeIssue(['po_no' => 'PO-TODAY', 'issue_date' => now()->toDateString()]);
    makeIssue(['po_no' => 'PO-OLD', 'issue_date' => now()->subMonths(2)->toDateString()]);

    // Partial request returns just the table (no full layout), scoped to today.
    $res = $this->actingAs($user)->get(route('store.material.bulk-issues.index', ['tab' => 'today', 'partial' => 1]));
    $res->assertOk()
        ->assertSee('PO-TODAY')
        ->assertDontSee('PO-OLD')
        ->assertDontSee('<html', false);   // partial, not the full page

    // Search narrows across denormalised identity columns.
    $this->actingAs($user)->get(route('store.material.bulk-issues.index', ['q' => 'PO-OLD', 'partial' => 1]))
        ->assertOk()->assertSee('PO-OLD')->assertDontSee('PO-TODAY');
});

it('returns a single issue as json for the edit panel', function () {
    $user = bulkIssueStoreUser();
    $issue = makeIssue(['indent_section' => 'Cutting', 'bulk_qty' => 42]);

    $this->actingAs($user)->getJson(route('store.material.bulk-issues.show', $issue))
        ->assertOk()
        ->assertJson([
            'id' => $issue->id,
            'indent_section' => 'Cutting',
            'bulk_qty' => 42.0,
        ]);
});

it('updates an issue', function () {
    $user = bulkIssueStoreUser();
    $issue = makeIssue(['bulk_qty' => 10]);

    $this->actingAs($user)->put(route('store.material.bulk-issues.update', $issue), [
        'booking_po_id' => $issue->booking_po_id ?? makeBookingPo()->id,
        'issue_date' => now()->toDateString(),
        'indent_section' => 'Sewing',
        'bulk_qty' => 25,
    ])->assertRedirect();

    expect($issue->fresh()->bulk_qty)->toEqual('25.0000')
        ->and($issue->fresh()->indent_section)->toBe('Sewing');
});

it('rejects an update with no quantities', function () {
    $user = bulkIssueStoreUser();
    $issue = makeIssue(['bulk_qty' => 10]);
    $poId = makeBookingPo()->id;

    $this->actingAs($user)->put(route('store.material.bulk-issues.update', $issue), [
        'booking_po_id' => $poId,
        'issue_date' => now()->toDateString(),
        'bulk_qty' => 0, 'sample_qty' => 0, 'liability_qty' => 0, 'dead_qty' => 0,
    ])->assertSessionHas('warning');

    // Unchanged.
    expect($issue->fresh()->bulk_qty)->toEqual('10.0000');
});

it('bulk deletes selected issues', function () {
    $user = bulkIssueStoreUser();
    $a = makeIssue();
    $b = makeIssue();
    $c = makeIssue();

    $this->actingAs($user)->post(route('store.material.bulk-issues.bulk-destroy'), [
        'ids' => [$a->id, $b->id],
    ])->assertRedirect();

    expect(MaterialBulkIssue::whereIn('id', [$a->id, $b->id])->count())->toBe(0)
        ->and(MaterialBulkIssue::find($c->id))->not->toBeNull();
});

it('exports selected issues to excel and pdf', function () {
    $user = bulkIssueStoreUser();
    $issue = makeIssue();

    $this->actingAs($user)->post(route('store.material.bulk-issues.export.excel'), ['ids' => [$issue->id]])
        ->assertOk()
        ->assertHeader('content-disposition');

    $this->actingAs($user)->post(route('store.material.bulk-issues.export.pdf'), ['ids' => [$issue->id]])
        ->assertOk();
})->group('export');
