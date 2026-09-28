<?php

use App\Models\AppSetting;
use App\Models\Department;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionHeaderResolver as Header;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 2 — the requisition document header as a snapshot.
 *
 *   - Written on every submit and resubmit: unit, section, user, contact and
 *     the Store Personnel box.
 *   - Not touched by a Store correction; frozen once approved.
 *   - A later change to a user's phone never rewrites a sent document.
 *   - An OLD sheet (no snapshot) prints exactly what it printed before, from
 *     its own columns; only the Store Personnel box is current data.
 *   - Admin sets Name of Unit, Store Personnel and Contact Person.
 *
 * In-memory sqlite. No real record touched.
 */
function hdrUser(string $role, array $permissions = [], array $attributes = []): User
{
    $r = Role::findOrCreate($role, 'web');
    $user = User::factory()->create($attributes)->assignRole($r);

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function hdrSubmit(User $user, string $action = 'submit')
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    test()->actingAs($user)->post(route('store.stock.requisitions.store'), [
        'action' => $action,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ]);

    return PurchaseRequisition::latest('id')->firstOrFail();
}

/** A department user in the Cutting section, with no head above them. */
function hdrRaiser(array $attributes = []): User
{
    $section = IndentSection::firstOrCreate(['name' => 'Cutting'], ['department_key' => 'production', 'is_active' => true]);

    return hdrUser('production', ['store.requisition.create'], $attributes + [
        'name' => 'Md Robeul', 'email' => 'robeul@example.test', 'phone' => '01711-000001',
        'requisition_section_id' => $section->id,
    ]);
}

function hdrStoreHead(): User
{
    $head = User::factory()->create(['name' => 'Store Head', 'designation' => 'Manager', 'phone' => '01711-999999', 'email' => 'storehead@example.test']);
    Department::updateOrCreate(['key' => 'store'], ['label' => 'Store', 'head_user_id' => $head->id, 'is_active' => true]);

    return $head;
}

/** The document PDF's HTML, as the PDF and single-sheet Excel render it. */
function hdrDocumentHtml(PurchaseRequisition $requisition, ?string $departmentHeadName = null): string
{
    return view('store.stock.requisitions.document-pdf', [
        'requisition' => $requisition->fresh(['indentSection', 'indentPerson', 'createdBy']),
        'rows' => collect(),
        'title' => 'Purchase Requisition',
        'signatures' => [],
        'departmentHeadName' => $departmentHeadName,
    ])->render();
}

// --- Writing the snapshot ------------------------------------------------------

it('snapshots a department sheet on submit: its section, its raiser and their contact', function () {
    AppSetting::put(Header::SETTING_UNIT_NAME, 'Unit-2, Gazipur');
    hdrStoreHead();

    $sheet = hdrSubmit(hdrRaiser());
    $snap = $sheet->header_snapshot;

    expect($sheet->level)->toBe(PurchaseRequisition::LEVEL_DEPARTMENT)
        ->and($sheet->header_snapshot_at)->not->toBeNull()
        ->and($snap['unit_name'])->toBe('Unit-2, Gazipur')
        ->and($snap['section'])->toBe('Cutting')
        ->and($snap['user_name'])->toBe('Md Robeul')
        ->and($snap['contact'])->toBe('01711-000001 / robeul@example.test')
        ->and($snap['store_personnel'])->toMatchArray([
            'name' => 'Store Head', 'designation' => 'Manager', 'phone' => '01711-999999', 'email' => 'storehead@example.test',
        ]);

    // The printed header reads the snapshot; the letterhead stays the company.
    $html = hdrDocumentHtml($sheet);
    expect($html)->toContain('Unit-2, Gazipur')->toContain('01711-000001 / robeul@example.test')
        ->toContain(config('stock.company_name'));
});

it('writes nothing for a draft', function () {
    expect(hdrSubmit(hdrRaiser(), 'draft')->header_snapshot)->toBeNull();
});

it('snapshots a final sheet as ALL, with the Contact Person, else the preparer', function () {
    $store = hdrUser('store', ['store.requisition.create'], ['name' => 'Store Clerk', 'email' => 'clerk@example.test', 'phone' => null]);

    $first = hdrSubmit($store);
    expect($first->level)->toBe(PurchaseRequisition::LEVEL_FINAL)
        ->and($first->header_snapshot['section'])->toBe('ALL')
        ->and($first->header_snapshot['user_name'])->toBe('ALL')
        ->and($first->header_snapshot['contact'])->toBe('clerk@example.test');

    $contact = User::factory()->create(['phone' => '01811-222222', 'email' => 'purchase@example.test']);
    AppSetting::put(Header::SETTING_CONTACT_PERSON, (string) $contact->id);

    expect(hdrSubmit($store)->header_snapshot['contact'])->toBe('01811-222222 / purchase@example.test');
});

it('takes Store Personnel from the setting before the Store head', function () {
    hdrStoreHead();
    $chosen = User::factory()->create(['name' => 'Store Officer', 'designation' => 'Officer']);
    AppSetting::put(Header::SETTING_STORE_PERSONNEL, (string) $chosen->id);

    expect(hdrSubmit(hdrRaiser())->header_snapshot['store_personnel']['name'])->toBe('Store Officer');
});

it('refreshes on resubmit after a return', function () {
    $raiser = hdrRaiser();
    $sheet = hdrSubmit($raiser);
    $sheet->update(['department_review_status' => PurchaseRequisition::DEPT_REVIEW_REJECTED]);
    $raiser->update(['phone' => '01999-555555']);

    $this->actingAs($raiser)->put(route('store.stock.requisitions.update', $sheet), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => StockItem::first()->id, 'qty_requested' => 3]],
    ])->assertRedirect();

    expect($sheet->fresh()->header_snapshot['contact'])->toBe('01999-555555 / robeul@example.test');
});

it('does not change on a Store correction', function () {
    $raiser = hdrRaiser();
    $sheet = hdrSubmit($raiser);
    $before = $sheet->header_snapshot;
    $raiser->update(['phone' => '01999-555555']);

    $store = hdrUser('store', ['store.requisition.edit']);
    $this->actingAs($store)->put(route('store.stock.requisitions.update', $sheet), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => StockItem::first()->id, 'qty_requested' => 9]],
    ])->assertRedirect();

    expect($sheet->fresh()->header_snapshot)->toBe($before);
});

it('is frozen once approved, and approval does not refresh it', function () {
    $raiser = hdrRaiser();
    $sheet = hdrSubmit($raiser);
    $before = $sheet->header_snapshot;
    $raiser->update(['phone' => '01999-555555']);

    $this->actingAs(hdrUser('store', ['store.requisition.review']))->post(route('store.stock.requisitions.received', $sheet));
    $this->actingAs(hdrUser('admin', ['store.requisition.approve']))->post(route('store.stock.requisitions.approve', $sheet));
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($sheet->fresh()->header_snapshot)->toBe($before);

    // Even called directly, the writer refuses an approved sheet.
    app(Header::class)->snapshot($sheet->fresh());
    expect($sheet->fresh()->header_snapshot)->toBe($before);
});

it('keeps a sent document unchanged when the user later changes their details', function () {
    $raiser = hdrRaiser();
    $sheet = hdrSubmit($raiser);
    $raiser->update(['phone' => '01999-555555', 'name' => 'Robeul Hasan']);

    $html = hdrDocumentHtml($sheet);
    expect($html)->toContain('01711-000001')->not->toContain('01999-555555')->toContain('Md Robeul');

    $this->actingAs(hdrUser('store'))->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('01711-000001')->assertDontSee('01999-555555');
});

// --- Old sheets --------------------------------------------------------------

it('prints an old sheet exactly as before, from its own columns', function () {
    $store = hdrUser('store');
    $person = IndentPerson::create(['name' => 'Paper Requester']);
    $old = PurchaseRequisition::create([
        'requisition_no' => 'OLD-1', 'requisition_date' => '2026-08-10', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'unit_name' => 'Old Unit Name', 'contact' => 'old-contact@example.test',
        'indent_person_id' => $person->id, 'status' => PurchaseRequisition::STATUS_APPROVED, 'created_by' => $store->id,
    ]);

    // Settings changed since — none of it may reach the old sheet's header.
    AppSetting::put(Header::SETTING_UNIT_NAME, 'New Unit');
    AppSetting::put(Header::SETTING_CONTACT_PERSON, (string) User::factory()->create(['email' => 'new@example.test'])->id);

    expect($old->header_snapshot)->toBeNull();

    $html = hdrDocumentHtml($old);
    expect($html)->toContain('old-contact@example.test')->toContain('Old Unit Name')->toContain('Paper Requester')
        ->not->toContain('new@example.test')->not->toContain('New Unit');

    $this->actingAs($store)->get(route('store.stock.requisitions.show', $old))
        ->assertOk()->assertSee('old-contact@example.test')->assertSee('Old Unit Name')->assertSee('Paper Requester');
});

it('prints a dash for an empty value', function () {
    $raiser = hdrRaiser(['phone' => null, 'email' => 'x@example.test']);
    $sheet = hdrSubmit($raiser);
    $snapshot = $sheet->header_snapshot;
    $snapshot['contact'] = null;
    $sheet->forceFill(['header_snapshot' => $snapshot])->saveQuietly();

    expect(hdrDocumentHtml($sheet))->toMatch('/Contact No\. \/ Email<\/td>\s*<td[^>]*>-<\/td>/');
});

// --- Settings ----------------------------------------------------------------

it('lets Admin save the header settings, and nobody else', function () {
    $admin = hdrUser('admin');
    $personnel = User::factory()->create();
    $contact = User::factory()->create();
    $payload = [
        'blocks' => [
            'department' => [['label' => 'Requested by', 'assignee_type' => 'requester', 'stamp_source' => 'prepared']],
            'final' => [['label' => 'Prepared by', 'assignee_type' => 'role', 'role_name' => 'store', 'stamp_source' => 'prepared']],
        ],
        'header_unit_name' => '  Unit-2  ',
        'header_store_personnel' => $personnel->id,
        'header_contact_person' => $contact->id,
    ];

    $this->actingAs($admin)->get(route('admin.requisition-signatures.edit'))->assertOk()
        ->assertSee('Document Header')->assertSee('Store Personnel')->assertSee('Contact Person (final sheet)');
    $this->actingAs($admin)->post(route('admin.requisition-signatures.update'), $payload)
        ->assertRedirect(route('admin.requisition-signatures.edit'));

    expect(AppSetting::get(Header::SETTING_UNIT_NAME))->toBe('Unit-2')
        ->and((int) AppSetting::get(Header::SETTING_STORE_PERSONNEL))->toBe($personnel->id)
        ->and((int) AppSetting::get(Header::SETTING_CONTACT_PERSON))->toBe($contact->id)
        ->and(Header::unitName())->toBe('Unit-2');

    $this->actingAs(hdrUser('store'))->post(route('admin.requisition-signatures.update'), $payload)->assertForbidden();
    $this->actingAs(hdrUser('management'))->post(route('admin.requisition-signatures.update'), $payload)->assertForbidden();

    // Empty falls back to the company name.
    AppSetting::put(Header::SETTING_UNIT_NAME, '');
    expect(Header::unitName())->toBe(config('stock.company_name'));
});

// --- Monthly report ------------------------------------------------------------

it('prints the Store Personnel setting in the monthly report Excel, not whoever downloads it', function () {
    hdrStoreHead();
    $downloader = hdrUser('store', [], ['name' => 'Downloader Person', 'email' => 'downloader@example.test']);
    hdrSubmit(hdrUser('store', ['store.requisition.create']));

    // Rendered AS the downloader: before this change their name and email
    // filled the box.
    $this->actingAs($downloader);
    $html = view('store.stock.requisitions.report-pdf', [
        'forExcel' => true, 'title' => 'Purchase Requisition', 'monthLabel' => 'September 2026', 'merge' => false,
        'filters' => [], 'summary' => ['requisitions' => 1, 'lines' => 1, 'items' => 1, 'amount' => 0],
        'sections' => ['ALL' => collect()], 'signatures' => [],
    ])->render();

    expect($html)->toContain('Store Head')->toContain('Manager')->toContain('storehead@example.test')
        ->not->toContain('Downloader Person')->not->toContain('downloader@example.test');
});
