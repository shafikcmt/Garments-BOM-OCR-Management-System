<?php

use App\Models\AppSetting;
use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PraStageAssignment;
use App\Models\User;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use App\Support\PaymentRequestSettings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * THE MANUAL-ASSIGNMENT CONTROLS, driven through the real routes.
 *
 *   create preview   the optional step-2 picker, populated from the resolver
 *   store()          honours a valid pick, refuses an ineligible one BEFORE
 *                    creating anything, ignores the field on the legacy path
 *   departmental     the queue says "assigned to you"; the review page lists
 *   queue / review   the whole roster with the named person first; approving
 *                    may name the next step's person, checked the same way
 *
 * Every option offered on screen is the resolver's eligible set; every id
 * posted is checked against that same set. The two cannot disagree.
 */
function uiRole(string $name): Role
{
    return Role::findOrCreate($name, 'web');
}

function uiUser(string $role, bool $head = false, string $name = 'X'): User
{
    uiRole($role);
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function uiConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

/** One BOM row on the buyer with enough cells to be a payable PO line. */
function uiPayablePo(Buyer $buyer, string $poNo, string $pi, string $vendor = 'Vendor'): BookingPo
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id, 'total_rows' => 1,
    ]);
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    foreach ([
        'buyer_name' => $buyer->buyer_name, 'style_name' => 'Style', 'vendor_name' => $vendor,
        'pi_number' => $pi, 'pi_status' => 'PI Received',
    ] as $key => $value) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => ExcelHeader::where('header_key', $key)->value('id'),
            'value' => $value,
        ]);
    }

    return BookingPo::create([
        'excel_file_id' => $file->id, 'excel_row_id' => $row->id, 'po_no' => $poNo,
        'buyer_name' => $buyer->buyer_name, 'vendor_name' => $vendor,
        'generated_at' => now(), 'status' => 'completed', 'completed_at' => now(),
    ]);
}

beforeEach(function () {
    Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial', 'admin'] as $role) {
        uiRole($role)->givePermissionTo('pra.departmental.act');
    }
    AppSetting::put(PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW, '1');

    $supply = uiRole('supply_chain');
    $merchant = uiRole('merchant');
    foreach ([
        ['buyer_name', 'Buyer Name', $merchant, 1], ['style_name', 'Style Name', $merchant, 2],
        ['vendor_name', 'Vendor Name', $supply, 10], ['material_po_number', 'Material PO Number', $supply, 11],
        ['pi_number', 'PI Number', $supply, 12], ['pi_status', 'PI Status', $supply, 13],
    ] as [$key, $name, $owner, $position]) {
        ExcelHeader::create([
            'header_name' => $name, 'header_key' => $key, 'owner_role_id' => $owner->id,
            'position' => $position, 'field_type' => 'text', 'is_active' => true,
        ]);
    }

    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->po = uiPayablePo($this->buyer, 'PO-1', 'PI-1');

    $this->creator = uiConfine(uiUser('supply_chain', name: 'Creator'), $this->buyer);
    $this->merchantA = uiConfine(uiUser('merchant', name: 'Merchant Alpha'), $this->buyer);
    $this->merchantB = uiConfine(uiUser('merchant', name: 'Merchant Bravo'), $this->buyer);
    $this->merchantHead = uiUser('merchant', head: true, name: 'Merchant Head');
    $this->accountsTeam = uiUser('account', name: 'Accounts Clerk');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->workflow = app(PraWorkflow::class);
});

// --- Create preview + store -------------------------------------------------

it('offers only the eligible Merchant Team members for step 2 on the create preview', function () {
    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => [$this->po->id]]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('name="assignee_step2"')
        ->toContain('value="' . $this->merchantA->id . '"')
        ->toContain('value="' . $this->merchantB->id . '"')
        // The head is not "team", and Accounts is another department.
        ->not->toContain('<option value="' . $this->merchantHead->id . '"')
        ->not->toContain('<option value="' . $this->accountsTeam->id . '"');
});

it('creates the PRA with the chosen primary assignee for step 2', function () {
    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'assignee_step2' => $this->merchantB->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'Merchant Bravo'));

    $pra = PaymentRequest::sole();
    expect($pra->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($pra->current_sequence)->toBe(2);

    $row = PraStageAssignment::sole();
    expect($row->payment_request_id)->toBe($pra->id)
        ->and($row->sequence)->toBe(2)
        ->and($row->user_id)->toBe($this->merchantB->id)
        ->and($row->assigned_by)->toBe($this->creator->id);
});

it('creates the PRA with no assignment when the picker is left blank', function () {
    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'assignee_step2' => '',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PaymentRequest::count())->toBe(1)
        ->and(PraStageAssignment::count())->toBe(0);
});

it('refuses an ineligible step-2 assignee before creating anything', function () {
    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'assignee_step2' => $this->accountsTeam->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'not eligible'));

    expect(PaymentRequest::count())->toBe(0)
        ->and(PraStageAssignment::count())->toBe(0);
});

it('ignores the assignee field entirely on the legacy flow', function () {
    AppSetting::put(PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW, '0');

    // The legacy preview has neither the picker nor the searchable library.
    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => [$this->po->id]]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('assignee_step2')->not->toContain('tom-select');

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'assignee_step2' => $this->merchantB->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PaymentRequest::sole()->flow_version)->toBe(PaymentRequest::FLOW_LEGACY)
        ->and(PraStageAssignment::count())->toBe(0);
});

// --- Departmental queue and review page -------------------------------------

function uiAssignedPra($test): PaymentRequest
{
    $test->actingAs($test->creator)->post(route('supply_chain.payment_requests.store'), [
        'booking_po_ids' => [$test->po->id],
        'assignee_step2' => $test->merchantB->id,
    ]);

    return PaymentRequest::sole();
}

it('tells the named person the PRA is assigned to them, and still lists it for the rest of the team', function () {
    $pra = uiAssignedPra($this);

    $this->actingAs($this->merchantB)
        ->get(route('pra_departmental.index'))
        ->assertOk()
        ->assertSee('Assigned to you')
        ->assertSee($pra->request_no);

    $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.index'))
        ->assertOk()
        ->assertSee($pra->request_no)
        ->assertSee('Assigned to Merchant Bravo')
        ->assertDontSee('Assigned to you')
        ->assertSee(route('pra_departmental.show', $pra), false);
});

it('shows the whole roster with the named person first, and offers only eligible people for the next step', function () {
    $pra = uiAssignedPra($this);

    $html = $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.show', $pra))
        ->assertOk()
        ->assertSee('Who can act')
        ->assertSee('Primary')
        ->assertSee('Merchant Alpha')
        ->assertSee('Merchant Bravo')
        ->assertSee('Approve This Step')
        ->getContent();

    // Bravo (named) is listed before Alpha even though Alpha sorts first by
    // name. Measured inside the roster — the header shows the signed-in user's
    // own name (Alpha) long before the roster is reached.
    $roster = substr($html, strpos($html, 'class="pra-roster'));
    expect(strpos($roster, 'Merchant Bravo'))->toBeLessThan(strpos($roster, 'Merchant Alpha'));

    // Next step is Merchant Head Approval: only the head who owns this buyer.
    expect($html)
        ->toContain('name="assign_next"')
        ->toContain('<option value="' . $this->merchantHead->id . '"')
        ->not->toContain('<option value="' . $this->accountsTeam->id . '"');
});

it('lets the approver name the next step\'s person, and records it', function () {
    $pra = uiAssignedPra($this);

    $this->actingAs($this->merchantA)
        ->post(route('pra_departmental.approve', $pra), ['assign_next' => $this->merchantHead->id])
        ->assertRedirect(route('pra_departmental.index'))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'assigned to Merchant Head'));

    $pra->refresh();
    expect($pra->current_sequence)->toBe(3)
        ->and(PraStageAssignment::where('sequence', 3)->sole()->user_id)->toBe($this->merchantHead->id)
        // The step-2 assignment is untouched, and its decision row remembers it.
        ->and(PraStageAssignment::where('sequence', 2)->sole()->user_id)->toBe($this->merchantB->id)
        ->and($pra->approvals->firstWhere('sequence', 2)->assigned_user_id)->toBe($this->merchantB->id);
});

it('refuses an ineligible next-step assignee without approving the step', function () {
    $pra = uiAssignedPra($this);

    $this->actingAs($this->merchantA)
        ->post(route('pra_departmental.approve', $pra), ['assign_next' => $this->accountsTeam->id])
        ->assertRedirect(route('pra_departmental.show', $pra))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'not eligible'));

    expect($pra->fresh()->current_sequence)->toBe(2)
        ->and(PraStageAssignment::where('sequence', 3)->count())->toBe(0)
        ->and($pra->approvals()->count())->toBe(0);
});

it('approves normally with the next-step picker left blank', function () {
    $pra = uiAssignedPra($this);

    $this->actingAs($this->merchantB)
        ->post(route('pra_departmental.approve', $pra), ['assign_next' => ''])
        ->assertRedirect(route('pra_departmental.index'))
        ->assertSessionHas('success');

    expect($pra->fresh()->current_sequence)->toBe(3)
        ->and(PraStageAssignment::where('sequence', 3)->count())->toBe(0);
});

it('shows the creator who each step is assigned to', function () {
    $pra = uiAssignedPra($this);

    $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $pra))
        ->assertOk()
        ->assertSee('Assigned to Merchant Bravo');
});

// --- Create preview signature area --------------------------------------------
//
// THE PREVIEW IS THE DOCUMENT BEFORE IT EXISTS, and it must not change shape
// when Create PRA is pressed. The unsaved preview model used to default to
// flow 1, so a departmental preview printed the three fixed legacy officers
// with their configured images — names that have nothing to do with the chain
// the PRA was about to join. These pin the preview to the same
// signatureBlocksFor() the saved PRA, its PDF and its Excel already read.

function uiFixedOfficers(): void
{
    Storage::fake('public');
    Storage::disk('public')->put('signatures/fixed.png', 'png');

    foreach (['prepared', 'checked', 'approved'] as $officer) {
        AppSetting::put("pra_sign_{$officer}_name", 'Fixed Officer ' . ucfirst($officer));
        AppSetting::put("pra_sign_{$officer}_image", 'signatures/fixed.png');
        AppSetting::put("pra_sign_{$officer}_enabled", '1');
    }
}

it('previews a departmental PRA with four dynamic signature blocks and no fixed officer', function () {
    uiFixedOfficers();
    Storage::disk('public')->put('signatures/creator.png', 'png');
    $this->creator->forceFill(['signature_path' => 'signatures/creator.png'])->save();

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => [$this->po->id]]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Prepared By')
        ->toContain('Checked By')
        ->toContain('Approved By (Merchant)')
        ->toContain('Approved By (Finance and Commercial)')
        // Prepared By is the creator, with THEIR signature — the same snapshot
        // store() will take.
        ->toContain('alt="Prepared By signature"')
        ->toContain('signatures/creator.png')
        ->toContain('Creator')
        // The other three are unsigned: they show the same Signature & Date caption
        // as Prepared By, not a "Pending approval" status line.
        ->and($html)->not->toContain('Pending approval')
        // Nothing fixed leaks through — not a name, not an image.
        ->and($html)->not->toContain('Fixed Officer')
        ->and($html)->not->toContain('signatures/fixed.png');

    // Then the saved PRA prints the same four blocks the preview showed.
    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id], 'payment_required_date' => now()->toDateString(),
        ]);

    $pra = PaymentRequest::latest('id')->first();
    expect($pra->isDepartmentalFlow())->toBeTrue()
        ->and($pra->prepared_signature_path)->toBe('signatures/creator.png');

    $saved = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $pra))
        ->assertOk()
        ->getContent();

    expect($saved)
        ->toContain('Approved By (Finance and Commercial)')
        ->toContain('alt="Prepared By signature"')
        ->not->toContain('Fixed Officer');
});

it('previews a legacy PRA with the same three fixed officers as before', function () {
    AppSetting::put(PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW, '0');
    uiFixedOfficers();

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => [$this->po->id]]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Fixed Officer Prepared')
        ->toContain('Fixed Officer Checked')
        ->toContain('Fixed Officer Approved')
        ->not->toContain('Approved By (Merchant)')
        ->not->toContain('Pending approval');
});

// --- Supporting documents ----------------------------------------------------

it('stores supporting documents uploaded with the PRA and serves them to authorised users only', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'documents' => [\Illuminate\Http\UploadedFile::fake()->create('PI-1.pdf', 100, 'application/pdf')],
        ])
        ->assertRedirect();

    $pra = PaymentRequest::sole();
    expect($pra->attachments())->toHaveCount(1)
        ->and($pra->attachments()[0]['name'])->toBe('PI-1.pdf');

    \Illuminate\Support\Facades\Storage::disk('local')->assertExists($pra->attachments()[0]['path']);

    $this->actingAs($this->creator)
        ->get(route('pra_documents.download', [$pra, 0]))
        ->assertOk();

    $this->actingAs($this->accountsTeam)
        ->get(route('pra_documents.download', [$pra, 0]))
        ->assertForbidden();
});

it('rejects a document type that is not allowed', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'documents' => [\Illuminate\Http\UploadedFile::fake()->create('run.exe', 10)],
        ])
        ->assertSessionHasErrors('documents.0');

    expect(PaymentRequest::count())->toBe(0);
});


// --- Single vendor / multi vendor PRA ---------------------------------------

it('refuses a single-vendor PRA that spans two vendors and allows it as a multi-vendor PRA', function () {
    $second = uiPayablePo($this->buyer, 'PO-2', 'PI-2', 'Other Vendor');
    $ids = [$this->po->id, $second->id];

    $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => $ids, 'pra_mode' => 'single']))
        ->assertRedirect(route('supply_chain.payment_requests.index'))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'single-vendor'));

    $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => $ids, 'pra_mode' => 'multi']))
        ->assertOk()
        ->assertSee('Multi Vendor PRA');

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), ['booking_po_ids' => $ids, 'pra_mode' => 'multi'])
        ->assertRedirect();

    $pra = PaymentRequest::latest('id')->first();
    expect($pra->data['vendor_mode'])->toBe('multi')
        ->and($pra->supplier_name)->toContain('Other Vendor');
});

// --- Merge existing PRAs ------------------------------------------------------

it('merges two untouched PRAs into one new PRA and closes the originals', function () {
    $second = uiPayablePo($this->buyer, 'PO-2', 'PI-2', 'Other Vendor');

    foreach ([$this->po, $second] as $po) {
        $this->actingAs($this->creator)
            ->post(route('supply_chain.payment_requests.store'), ['booking_po_ids' => [$po->id]])
            ->assertRedirect();
    }

    $first = PaymentRequest::orderBy('id')->first();
    $other = PaymentRequest::orderBy('id')->skip(1)->first();

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.merge'), ['payment_request_ids' => [$first->id, $other->id]])
        ->assertRedirect();

    $merged = PaymentRequest::latest('id')->first();

    expect($merged->id)->not->toBe($first->id)
        ->and($merged->items()->count())->toBe(2)
        ->and($merged->data['vendor_mode'])->toBe('multi')
        ->and($merged->current_sequence)->toBe(2)
        ->and($first->fresh()->status)->toBe('merged')
        ->and($first->fresh()->items()->count())->toBe(0)
        ->and((float) $first->fresh()->total_pi_amount)->toBe(0.0)
        ->and($other->fresh()->data['merged_into']['id'])->toBe($merged->id);
});

it('refuses to merge a PRA that someone has already acted on', function () {
    $second = uiPayablePo($this->buyer, 'PO-2', 'PI-2', 'Other Vendor');

    foreach ([$this->po, $second] as $po) {
        $this->actingAs($this->creator)
            ->post(route('supply_chain.payment_requests.store'), ['booking_po_ids' => [$po->id]]);
    }

    [$a, $b] = PaymentRequest::orderBy('id')->get()->all();
    $a->update(['current_sequence' => 3]);

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.merge'), ['payment_request_ids' => [$a->id, $b->id]])
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'cannot be merged'));

    expect($b->fresh()->status)->not->toBe('merged');
});

it('opens a PDF supporting document inline and still allows a download', function () {
    \Illuminate\Support\Facades\Storage::fake('local');

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->po->id],
            'documents' => [\Illuminate\Http\UploadedFile::fake()->create('PI-1.pdf', 50, 'application/pdf')],
        ]);

    $pra = PaymentRequest::sole();

    $view = $this->actingAs($this->creator)->get(route('pra_documents.view', [$pra, 0]))->assertOk();
    expect($view->headers->get('content-disposition'))->toContain('inline');

    $download = $this->actingAs($this->creator)->get(route('pra_documents.download', [$pra, 0]))->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment');
});
