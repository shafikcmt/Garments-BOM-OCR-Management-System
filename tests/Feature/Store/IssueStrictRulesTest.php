<?php

use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\StoreFormDraft;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Record Issue — the strict rules (owner, 2026-09-29):
 *
 *   1. Indent Section, Indent Person, Approved By, Requisition Number and
 *      each line's Type are required — on create and edit. Save Draft stays
 *      lenient; the rules apply when the draft is recorded.
 *   2. The same item on one requisition number only with a different remark
 *      (trimmed, spaces collapsed, case-insensitive) — within the form and
 *      against issues already recorded; an edited record is not its own twin.
 *
 * In-memory sqlite. No real record touched.
 */
function strictItem(string $name = '100% Cut Panel Inspection Report', float $stock = 50): StockItem
{
    $item = StockItem::create(['name' => $name, 'uom' => 'Pkt', 'category' => 'Stationery', 'opening_qty' => 0]);
    StockPurchase::create([
        'stock_item_id' => $item->id, 'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(), 'qty' => $stock, 'unit_price' => 1,
    ]);

    return $item;
}

function strictUser(array $permissions = ['store.issues.view', 'store.issues.create', 'store.issues.edit']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    return tap(User::factory()->create(['status' => 1]))->givePermissionTo($permissions);
}

/** A complete, valid Record Issue post; $overrides replace header keys. */
function strictPayload(StockItem $item, array $lines, array $overrides = []): array
{
    return array_merge([
        'issue_date' => now()->toDateString(),
        'indent_section_id' => 'new:Cutting',
        'indent_person_id' => 'new:Md Robeul',
        'issue_approver_id' => 'new:Store Head',
        'requisition_no' => 'HAPL/CUTTING/2026/September/04',
        'items' => array_map(fn ($line) => $line + ['stock_item_id' => $item->id, 'qty' => 1, 'requisition_type' => 'New'], $lines),
    ], $overrides);
}

function strictPost(User $user, array $payload)
{
    return test()->actingAs($user)->from(route('store.stock.issues.index'))->post(route('store.stock.issues.store'), $payload);
}

// --- 1. Required fields -------------------------------------------------------------

it('refuses a Record Issue with a required field empty, with its message', function (string $field, string $message) {
    $item = strictItem();
    $payload = strictPayload($item, [['remarks' => 'for buyer A']]);

    if ($field === 'items.0.requisition_type') {
        $payload['items'][0]['requisition_type'] = '';
    } else {
        $payload[$field] = '';
    }

    strictPost(strictUser(), $payload)->assertRedirect()->assertSessionHasErrors([$field => $message]);
    expect(StockIssue::count())->toBe(0);
})->with([
    'Indent Section' => ['indent_section_id', 'Select or type the Indent Section.'],
    'Indent Person' => ['indent_person_id', 'Select or type the Indent Person.'],
    'Approved By' => ['issue_approver_id', 'Select or type who approved the issue.'],
    'Requisition Number' => ['requisition_no', 'Enter the Requisition Number.'],
    'Type' => ['items.0.requisition_type', 'Select the type (New or Replace).'],
]);

it('keeps what was typed and shows the messages at the top and under the fields', function () {
    $item = strictItem();
    $user = strictUser();

    strictPost($user, strictPayload($item, [['remarks' => 'urgent']], ['indent_section_id' => '', 'requisition_no' => '']));

    $this->actingAs($user)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertSee('Please correct the following:')
        ->assertSee('Select or type the Indent Section.')
        ->assertSee('Enter the Requisition Number.')
        // Typed data kept: the person typed in, and the line.
        ->assertSee('Md Robeul')
        ->assertSee('"remarks":"urgent"', false);
});

it('saves a draft with every required field empty', function () {
    $item = strictItem();

    $this->actingAs(strictUser())->post(route('store.stock.issues.drafts.save'), [
        'items' => [['stock_item_id' => $item->id, 'qty' => 2, 'requisition_type' => '']],
    ])->assertRedirect()->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(1)->and(StockIssue::count())->toBe(0);
});

it('marks the required fields and starts a new line at New', function () {
    strictItem();
    $html = $this->actingAs(strictUser())->get(route('store.stock.issues.index'))->assertOk()->getContent();

    foreach (['issueSection' => 'Indent Section', 'issuePerson' => 'Indent Person', 'issueApprover' => 'Approved By', 'issueRequisitionNo' => 'Requisition Number'] as $id => $label) {
        expect($html)->toContain('for="'.$id.'">'.$label.' <span class="text-danger">*</span>');
    }
    expect($html)->toContain('<th>Type <span class="text-danger">*</span></th>')
        ->toContain("row.querySelector('.js-line-type').value = 'New';")
        // The client-side check covers the header and the repeat rule.
        ->toContain('Select or type the Indent Section.')
        ->toContain('with the same remark. Use a different remark to issue it again.')
        // Issue History's month filter: a list starting with All months.
        ->toMatch('/<select id="issueFilterMonth" name="month" class="form-select">\s*<option value="">All months<\/option>/');
});

// --- 2. Same item + same requisition number -----------------------------------------

it('saves the same item twice on one requisition when the remarks differ', function () {
    $item = strictItem();

    strictPost(strictUser(), strictPayload($item, [['remarks' => 'issue for buyer A'], ['remarks' => 'issue for buyer B']]))
        ->assertSessionHasNoErrors();

    expect(StockIssue::count())->toBe(2);
});

it('refuses the same remark again, whatever the case and spacing', function () {
    $item = strictItem();

    strictPost(strictUser(), strictPayload($item, [['remarks' => 'Issue for buyer A'], ['remarks' => '  issue   FOR buyer a ']]))
        ->assertSessionHasErrors(['items.1.remarks' => 'This item is already on requisition HAPL/CUTTING/2026/September/04 with the same remark. Use a different remark to issue it again.']);

    expect(StockIssue::count())->toBe(0);
});

it('refuses the same item twice with both remarks empty', function () {
    $item = strictItem();

    strictPost(strictUser(), strictPayload($item, [['remarks' => ''], ['remarks' => null]]))
        ->assertSessionHasErrors('items.1.remarks');

    expect(StockIssue::count())->toBe(0);
});

it('refuses a line repeating an issue already recorded on that requisition, and takes a different remark', function () {
    $item = strictItem();
    $user = strictUser();

    strictPost($user, strictPayload($item, [['remarks' => 'urgent']]))->assertSessionHasNoErrors();

    // Same item, same number (other case/spacing), same remark: refused.
    strictPost($user, strictPayload($item, [['remarks' => ' URGENT ']], ['requisition_no' => ' hapl/cutting/2026/september/04 ']))
        ->assertSessionHasErrors('items.0.remarks');

    // A different remark: saved. A different requisition number: saved too.
    strictPost($user, strictPayload($item, [['remarks' => 'ggg']]))->assertSessionHasNoErrors();
    strictPost($user, strictPayload($item, [['remarks' => 'urgent']], ['requisition_no' => 'HAPL/CUTTING/2026/September/05']))->assertSessionHasNoErrors();

    expect(StockIssue::count())->toBe(3);
});

// --- Edit ----------------------------------------------------------------------------

function strictEditPayload(StockIssue $issue, array $overrides = []): array
{
    return array_merge([
        'issue_date' => $issue->issue_date->toDateString(),
        'qty' => (float) $issue->qty,
        'indent_section_id' => IndentSection::firstOrCreate(['name' => 'Cutting'], ['is_active' => true])->id,
        'indent_person_id' => IndentPerson::firstOrCreate(['name' => 'Md Robeul'], ['is_active' => true])->id,
        'issue_approver_id' => IssueApprover::firstOrCreate(['name' => 'Store Head'], ['is_active' => true])->id,
        'requisition_no' => $issue->requisition_no,
        'requisition_type' => 'New',
        'remarks' => $issue->remarks,
    ], $overrides);
}

it('does not block an edit by the record itself, and refuses one that repeats another issue', function () {
    $item = strictItem();
    $user = strictUser();
    strictPost($user, strictPayload($item, [['remarks' => 'urgent'], ['remarks' => 'ggg']]))->assertSessionHasNoErrors();
    [$urgent, $ggg] = StockIssue::orderBy('id')->get()->all();

    // Saving "urgent" with its own remark again: not its own twin.
    $this->actingAs($user)->put(route('store.stock.issues.update', $urgent), strictEditPayload($urgent, ['qty' => 2]))
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect((float) $urgent->fresh()->qty)->toBe(2.0);

    // Changing "ggg" to "urgent" repeats the other record: refused, on Remarks.
    $this->actingAs($user)->put(route('store.stock.issues.update', $ggg), strictEditPayload($ggg, ['remarks' => 'Urgent']))
        ->assertSessionHasErrors(['remarks' => 'This item is already on requisition HAPL/CUTTING/2026/September/04 with the same remark. Use a different remark to issue it again.']);
    expect($ggg->fresh()->remarks)->toBe('ggg');
});

it('makes an old record with empty fields fill them when it is edited', function (string $field) {
    $item = strictItem();
    // Recorded before the rules: no section, person, approver, number or type.
    $old = StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => now()->toDateString(), 'qty' => 1]);

    $this->actingAs(strictUser())->put(route('store.stock.issues.update', $old), strictEditPayload($old, ['requisition_no' => 'REQ-OLD', $field => '']))
        ->assertSessionHasErrors($field);

    // Left exactly as it was.
    expect($old->fresh()->requisition_no)->toBeNull();
})->with(['indent_section_id', 'indent_person_id', 'issue_approver_id', 'requisition_no', 'requisition_type']);
