<?php

use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping for the Accounts department.
 *
 * WHAT ACCOUNTS ACTUALLY HAS, which is why this file is shorter than
 * SupplyChain/BuyerScopeTest.php rather than a copy of it:
 *
 *   dashboard   BOM row count + the PRA queue. Both buyer-linked, both scoped.
 *   workspace   the shared BOM file list — FILTERED since 2026-08-30. It was
 *               deliberately unfiltered before that, on the reading that buyer
 *               scoping there was an EDIT LOCK rather than a visibility
 *               filter. That reading leaked buyer and style names into the
 *               list itself, so it was dropped; Merchandising, Supply Chain,
 *               Commercial and Store all changed at the same time.
 *   report      Accounts has none. There is no routes/account.php report group
 *               and no AccountReportService, so there is nothing to scope.
 *
 * Accounts raises no PO and no PRA of its own, so there are no write paths to
 * guard here — the 403 refusals live with the department that owns those
 * actions, in tests/Feature/SupplyChain/BuyerScopeTest.php.
 *
 * THREE BUYER STATES, because they are three code paths and the dangerous one
 * is the middle: one buyer, several buyers, all buyers.
 */
function acScopeUser(array $buyerIds = [], string $mode = BuyerScope::MODE_ALL): User
{
    Role::findOrCreate('account', 'web');

    $user = User::factory()->create([
        'buyer_scope_mode' => $mode,
        'buyer_id' => $buyerIds[0] ?? null,
    ])->assignRole('account');

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

/** One BOM file on the given buyer (null = untagged), carrying one row. */
function acScopeFile(?int $buyerId): ExcelRow
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);

    return ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
}

/** A PRA carrying one item traced to the given BOM row's file. */
function acScopePra(string $requestNo, ExcelRow $row): PaymentRequest
{
    $po = BookingPo::create([
        'excel_file_id' => $row->excel_file_id,
        'excel_row_id' => $row->id,
        'po_no' => 'PO-' . $requestNo,
        'generated_at' => now(),
    ]);

    $pra = PaymentRequest::create([
        'request_no' => $requestNo,
        'created_by' => User::factory()->create()->id,
    ]);

    PaymentRequestItem::create([
        'payment_request_id' => $pra->id,
        'booking_po_id' => $po->id,
        'excel_file_id' => $row->excel_file_id,
        'po_no' => $po->po_no,
    ]);

    return $pra;
}

beforeEach(function () {
    Role::findOrCreate('account', 'web');
    Role::findOrCreate('admin', 'web');

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    $this->rowA = acScopeFile($this->buyerA->id);
    $this->rowB = acScopeFile($this->buyerB->id);
    $this->rowUntagged = acScopeFile(null);

    $this->praA = acScopePra('PRA-A', $this->rowA);
    $this->praB = acScopePra('PRA-B', $this->rowB);
    $this->praUntagged = acScopePra('PRA-U', $this->rowUntagged);
});

/*
|--------------------------------------------------------------------------
| The department is switched on
|--------------------------------------------------------------------------
*/

it('scopes the accounts department', function () {
    expect(BuyerScope::appliesTo('accounts'))->toBeTrue();
});

/* THE KEY IS PLURAL. 'account' is the ROLE; 'accounts' is the department key.
 * The singular would match nothing in SCOPED_DEPARTMENTS and look like scoping
 * that quietly does not work. */
it('is keyed on the department, not the role name', function () {
    expect(BuyerScope::appliesTo('account'))->toBeFalse()
        ->and(App\Support\DepartmentRoles::departmentOf('account'))->toBe('accounts');
});

/*
|--------------------------------------------------------------------------
| The three buyer states
|--------------------------------------------------------------------------
*/

it('confines a one-buyer accounts user to their buyer plus the untagged archive', function () {
    $user = acScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    expect($user->isBuyerConfined())->toBeTrue()
        ->and($user->scopedBuyerIds())->toBe([$this->buyerA->id]);

    $this->actingAs($user)
        ->get(route('account.dashboard'))
        ->assertOk()
        // 2 of 3 BOM rows, and 2 of 3 PRAs — their own and the untagged one.
        ->assertSee('2');
});

it('gives a multi-buyer accounts user every buyer they hold, not just the first', function () {
    $user = acScopeUser([$this->buyerA->id, $this->buyerB->id], BuyerScope::MODE_SPECIFIC);

    expect($user->scopedBuyerIds())->toBe([$this->buyerA->id, $this->buyerB->id]);

    $this->actingAs($user)->get(route('account.dashboard'))->assertOk();
});

/* THE DEFAULT. Switching Accounts on confined nobody: a user with no buyer sits
 * on the column default 'all' and sees everything, exactly as before. */
it('leaves an unassigned accounts user unconfined', function () {
    $user = acScopeUser();

    expect($user->isBuyerConfined())->toBeFalse()
        ->and($user->buyer_scope_mode)->toBe(BuyerScope::MODE_ALL)
        ->and($user->scopedBuyerIds())->toBe([]);

    $this->actingAs($user)->get(route('account.dashboard'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| What the dashboard counts
|--------------------------------------------------------------------------
|
| Asserted through the controller's own queries rather than by scraping digits
| out of the rendered card, which would pass on any number that happened to
| appear somewhere else on the page.
*/

it('counts only the BOM rows and PRAs a confined accounts user may see', function () {
    $user = acScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);
    $this->actingAs($user);

    $scoper = new class {
        use App\Support\Concerns\ScopesToAssignedBuyers;

        public function rows()
        {
            $q = ExcelRow::query();
            $this->applyBuyerScope($q);

            return $q->count();
        }

        public function pras()
        {
            $q = PaymentRequest::query();
            $this->applyBuyerScopeToPaymentRequests($q);

            return $q->count();
        }
    };

    // Buyer A's + the untagged one, in both cases. Buyer B's is gone.
    expect($scoper->rows())->toBe(2)
        ->and($scoper->pras())->toBe(2);
});

it('counts everything for an all-buyers accounts user', function () {
    $this->actingAs(acScopeUser());

    $scoper = new class {
        use App\Support\Concerns\ScopesToAssignedBuyers;

        public function pras()
        {
            $q = PaymentRequest::query();
            $this->applyBuyerScopeToPaymentRequests($q);

            return $q->count();
        }
    };

    expect($scoper->pras())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The two deliberate non-changes
|--------------------------------------------------------------------------
*/

/* The workspace file list is an EDIT LOCK, not a visibility filter — the same
 * rule Merchandising and Supply Chain follow. Switching Accounts on must not
 * quietly start hiding files from it. */
/* The workspace file list is now FILTERED — see the header of this file for
 * why the edit-lock reading was dropped.
 *
 * The assertions go through the response BODY as well as the view data,
 * because a row excluded from the query but still printed by some other
 * partial would satisfy a count alone. The row's OPEN LINK is the identifier,
 * not the file name: the table prints the name only inside a lock modal that
 * non-admins never render, so a name-based assertion would pass whether the
 * row was there or not. The trailing quote anchors the match so
 * /uploaded-files/3 cannot satisfy a search for /uploaded-files/30. */
it('hides another buyers files from the accounts workspace list', function () {
    $response = $this->actingAs(acScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('account.workspace'))
        ->assertOk()
        ->assertViewHas('files', fn ($files) => $files->count() === 2);

    $link = fn (ExcelRow $row) => route('uploaded-files.show', $row->excel_file_id) . '"';

    $response->assertSee($link($this->rowA), false);
    $response->assertSee($link($this->rowUntagged), false);
    $response->assertDontSee($link($this->rowB), false);
});

/* A scope that narrows the unconfined is a different bug from one that fails
 * to narrow the confined, so both directions are pinned. */
it('leaves the accounts workspace list whole for an unconfined user', function () {
    $this->actingAs(acScopeUser())
        ->get(route('account.workspace'))
        ->assertOk()
        ->assertViewHas('files', fn ($files) => $files->count() === 3);
});

it('never restricts a super admin, whatever they hold', function () {
    Role::findOrCreate('admin', 'web');

    $admin = User::factory()->create([
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
        'buyer_id' => $this->buyerA->id,
    ])->assignRole('admin');

    expect($admin->isBuyerConfined())->toBeFalse()
        ->and($admin->scopedBuyerIds())->toBe([]);
});
