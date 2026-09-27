<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\User;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * THE READ-ONLY "ALL PRAS" LIST beside the My Step queue.
 *
 * The queue drops a PRA the moment its step advances — correct, but it left
 * the person who had just approved it with no page to find it on. This list
 * is scoped by PARTICIPATION: the creator, or anyone holding an approval row
 * on it, sees it for good; an admin sees everything. Nobody else sees a row,
 * and a row here never re-opens a step that has passed — the decision panel
 * is withheld in the view and the workflow refuses the post regardless.
 */
function alUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function alConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

function alPra(Buyer $buyer, User $creator, string $no): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => $no, 'status' => 'draft', 'created_by' => $creator->id,
    ]);
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'uploaded_by' => $creator->id, 'buyer_id' => $buyer->id, 'total_rows' => 1,
    ]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    return app(PraWorkflow::class)->start($pra->fresh());
}

beforeEach(function () {
    $permission = Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial', 'admin'] as $role) {
        Role::findOrCreate($role, 'web')->givePermissionTo($permission);
    }

    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->otherBuyer = Buyer::create(['buyer_name' => 'MACYS']);

    $this->creator = alUser('supply_chain', name: 'Creator');
    $this->merchantA = alConfine(alUser('merchant', name: 'Merchant Alpha'), $this->buyer);
    $this->merchantB = alConfine(alUser('merchant', name: 'Merchant Bravo'), $this->buyer);
    $this->merchantOther = alConfine(alUser('merchant', name: 'Merchant Other'), $this->otherBuyer);
    $this->merchantHead = alUser('merchant', head: true, name: 'M Head');
    $this->accountsTeam = alUser('account', name: 'A Team');
    $this->admin = alUser('admin', name: 'Super Admin');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();
    $this->otherBuyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->workflow = app(PraWorkflow::class);

    // X: Alpha checks it, so it moves past the Merchant Team step.
    $this->praX = alPra($this->buyer, $this->creator, 'PRA-X');
    $this->workflow->approve($this->praX, $this->merchantA, 'Checked.');

    // Y: on the other buyer; Alpha has never touched it.
    $this->praY = alPra($this->otherBuyer, $this->creator, 'PRA-Y');
});

// --- Scoping ------------------------------------------------------------------

it('lists a PRA for the member who acted on it, even after it moved past their step', function () {
    $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.index'))
        ->assertOk()
        ->assertDontSee('PRA-X');   // gone from the queue — their step is done

    $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.all'))
        ->assertOk()
        ->assertSee('PRA-X')
        ->assertSee('Approved — step 2')
        ->assertDontSee('PRA-Y');   // never touched it
});

it('does not list a PRA for an eligible teammate who never acted on it', function () {
    // Bravo could have checked X; Alpha did. Bravo's department is on record
    // under Alpha, and Bravo has no row of their own.
    $this->actingAs($this->merchantB)
        ->get(route('pra_departmental.all'))
        ->assertOk()
        ->assertDontSee('PRA-X');

    $this->actingAs($this->merchantB)
        ->get(route('pra_departmental.show', $this->praX))
        ->assertForbidden();
});

it('lists a PRA for its creator when they can reach the screen', function () {
    // Creators are normally Supply Chain, who use My PRA Status instead — but
    // the participation rule counts them, so a creator who does hold the
    // departmental permission sees their own.
    $this->creator->givePermissionTo('pra.departmental.act');

    $this->actingAs($this->creator->fresh())
        ->get(route('pra_departmental.all'))
        ->assertOk()
        ->assertSee('PRA-X')
        ->assertSee('PRA-Y')
        ->assertSee('Raised by you');
});

it('lists every departmental PRA for an admin, and none of the legacy ones', function () {
    PaymentRequest::create(['request_no' => 'PRA-LEGACY', 'status' => 'pending_approval']);

    $this->actingAs($this->admin)
        ->get(route('pra_departmental.all'))
        ->assertOk()
        ->assertSee('PRA-X')
        ->assertSee('PRA-Y')
        ->assertDontSee('PRA-LEGACY')
        ->assertDontSee('Your Decision');
});

it('keeps a user with no connection off the list and out of the detail page', function () {
    $this->actingAs($this->merchantOther)
        ->get(route('pra_departmental.all'))
        ->assertOk()
        ->assertDontSee('PRA-X');

    $this->actingAs($this->merchantOther)
        ->get(route('pra_departmental.show', $this->praX))
        ->assertForbidden();

    // And someone without the permission at all never reaches the list.
    $this->actingAs($this->creator)
        ->get(route('pra_departmental.all'))
        ->assertForbidden();
});

// --- Read-only once the step has passed ----------------------------------------

it('shows a past participant the full record without the decision panel', function () {
    $html = $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.show', $this->praX))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Approval History')
        ->toContain('Merchant Alpha')
        ->toContain('cannot act on this step')
        ->not->toContain('Approve This Step')
        ->not->toContain('name="assign_next"');
});

it('refuses a past participant who posts an approval anyway', function () {
    // Server-side, not just a hidden button: step 3 belongs to the Merchant
    // Head, and the workflow will not let Alpha act on it.
    $this->actingAs($this->merchantA)
        ->post(route('pra_departmental.approve', $this->praX), ['comment' => 'again'])
        ->assertRedirect(route('pra_departmental.index'))
        ->assertSessionHas('warning');

    expect($this->praX->fresh()->current_sequence)->toBe(3)
        ->and(PraApproval::where('payment_request_id', $this->praX->id)->count())->toBe(1);
});

it('shows an admin the record read-only and refuses their approval too', function () {
    $html = $this->actingAs($this->admin)
        ->get(route('pra_departmental.show', $this->praX))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Approve This Step');

    $this->actingAs($this->admin)
        ->post(route('pra_departmental.approve', $this->praX))
        ->assertSessionHas('warning');

    expect($this->praX->fresh()->current_sequence)->toBe(3);
});

it('puts a PRA back in the queue, not just the list, when it returns to the same person', function () {
    // Head rejects; creator resubmits; step 2 is Alpha's again.
    $this->workflow->reject($this->praX->fresh(), $this->merchantHead, 'No.');
    $this->workflow->resubmit($this->praX->fresh());

    $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.index'))
        ->assertOk()
        ->assertSee('PRA-X');

    $this->actingAs($this->merchantA)
        ->get(route('pra_departmental.all'))
        ->assertOk()
        ->assertSee('PRA-X')
        ->assertSee('Approved — step 2');   // the earlier round is still on record
});
