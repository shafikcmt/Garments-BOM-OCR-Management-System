<?php

use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Services\PraWorkflow;
use App\Support\BookingPoLink;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * THE WAY FROM A PRA TO THE BOOKING PO IT PAYS FOR.
 *
 * The link is navigation, not a gate: it points at screens that enforce their
 * own access, and it is shown only to a viewer those screens would admit —
 * admin, or Supply Chain within their buyer access. Everyone else who may see
 * the PRA sees the PO summary built from the PRA's own lines, and no button.
 * A legacy PRA's lines carry no booking_po_id, so it shows nothing new.
 */
function bplUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function bplConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

function bplBookingPo(Buyer $buyer, string $poNo): BookingPo
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id, 'total_rows' => 1,
    ]);
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    return BookingPo::create([
        'excel_file_id' => $file->id, 'excel_row_id' => $row->id, 'po_no' => $poNo,
        'buyer_name' => $buyer->buyer_name, 'vendor_name' => 'Avery Dennison',
        'generated_at' => now(), 'status' => 'completed', 'completed_at' => now(),
    ]);
}

function bplPra(User $creator, BookingPo $po, bool $departmental = true): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft', 'created_by' => $creator->id,
    ]);
    PaymentRequestItem::create([
        'payment_request_id' => $pra->id, 'excel_file_id' => $po->excel_file_id,
        // A legacy PRA knows its PO only as text.
        'booking_po_id' => $departmental ? $po->id : null, 'po_no' => $po->po_no,
        'style_name' => 'Style A', 'material_description' => 'Woven label', 'pi_number' => 'PI-77',
        'qty' => 1200, 'pi_amount' => 1617.10, 'payment_term' => 'TT',
    ]);

    return $departmental ? app(PraWorkflow::class)->start($pra->fresh()) : $pra->fresh();
}

beforeEach(function () {
    $permission = Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial', 'admin'] as $role) {
        Role::findOrCreate($role, 'web')->givePermissionTo($permission);
    }

    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->otherBuyer = Buyer::create(['buyer_name' => 'MACYS']);

    $this->admin = bplUser('admin', name: 'Super Admin');
    $this->creator = bplConfine(bplUser('supply_chain', name: 'SC Creator'), $this->buyer);
    $this->scOther = bplConfine(bplUser('supply_chain', name: 'SC Other'), $this->otherBuyer);
    $this->merchant = bplConfine(bplUser('merchant', name: 'Merchant Alpha'), $this->buyer);
    $this->merchantHead = bplUser('merchant', head: true, name: 'M Head');
    $this->accounts = bplUser('account', name: 'Accounts Clerk');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->po = bplBookingPo($this->buyer, 'HUFA270002');
    $this->pra = bplPra($this->creator, $this->po);
});

// --- Who gets a link ---------------------------------------------------------

it('gives an admin the PO Generate Control link on the departmental detail', function () {
    $html = $this->actingAs($this->admin)
        ->get(route('pra_departmental.show', $this->pra))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Open Booking PO')
        ->toContain(route('admin.po-generate-control.show', $this->po))
        ->toContain('HUFA270002');
});

it('gives a Supply Chain creator the Booking Preview link on the document view, and it opens', function () {
    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $this->pra))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('Booking PO HUFA270002')
        ->toContain(route('supply_chain.bookings.show', $this->po));

    // The screen the link points at admits them — the link and the guard agree.
    $this->actingAs($this->creator)
        ->get(route('supply_chain.bookings.show', $this->po))
        ->assertOk();
});

it('shows a Merchant or Accounts reviewer the PO summary but no link', function () {
    // Merchant acts at step 2 (and keeps read access); the Head takes step 3
    // so step 4 is the Accounts clerk's turn.
    app(PraWorkflow::class)->approve($this->pra, $this->merchant, 'Checked.');
    app(PraWorkflow::class)->approve($this->pra->fresh(), $this->merchantHead);

    foreach ([$this->merchant, $this->accounts] as $viewer) {
        $html = $this->actingAs($viewer)
            ->get(route('pra_departmental.show', $this->pra))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('HUFA270002')
            ->toContain('Avery Dennison')
            ->toContain('Woven label')
            ->not->toContain('Open Booking PO')
            ->not->toContain(route('supply_chain.bookings.show', $this->po));

        // And the screen they were not linked to refuses them anyway.
        $this->actingAs($viewer)
            ->get(route('supply_chain.bookings.show', $this->po))
            ->assertForbidden();
    }
});

// --- Buyer scoping -------------------------------------------------------------

it('offers no link to a Supply Chain user confined to a different buyer', function () {
    expect(BookingPoLink::urlFor($this->creator, $this->po))->toBe(route('supply_chain.bookings.show', $this->po))
        ->and(BookingPoLink::urlFor($this->scOther, $this->po))->toBeNull()
        ->and(BookingPoLink::urlFor($this->merchant, $this->po))->toBeNull()
        ->and(BookingPoLink::urlFor($this->admin, $this->po))->toBe(route('admin.po-generate-control.show', $this->po));

    // The screen itself agrees with the null.
    $this->actingAs($this->scOther)
        ->get(route('supply_chain.bookings.show', $this->po))
        ->assertForbidden();
});

// --- Legacy ----------------------------------------------------------------------

it('shows nothing for a legacy PRA whose lines carry no booking_po_id', function () {
    $legacy = bplPra($this->creator, $this->po, departmental: false);

    expect(BookingPoLink::forPaymentRequest($legacy, $this->creator))->toBeEmpty();

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $legacy))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('Booking PO HUFA270002');
});
