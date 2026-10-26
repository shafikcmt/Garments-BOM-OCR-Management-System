<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Accounts' Payments screen: approved PRAs still unpaid, and one-step recording
 * of the payment reference on all their lines.
 */
function accountsUser(): User
{
    Role::findOrCreate('account', 'web');

    return User::factory()->create()->assignRole('account');
}

function approvedPra(string $status = 'approved', ?int $buyerId = null, int $lines = 2): array
{
    $creator = User::factory()->create();
    $file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b.xlsx',
        'uploaded_by' => $creator->id, 'buyer_id' => $buyerId,
    ]);
    $role = Role::findOrCreate('account', 'web');
    $header = ExcelHeader::firstOrCreate(
        ['header_key' => 'pmt_doc_no'],
        ['header_name' => 'Pmt doc no', 'owner_role_id' => $role->id, 'position' => 1]
    );

    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('####'), 'supplier_name' => 'Acme Trims',
        'status' => $status, 'total_pi_amount' => 1500, 'created_by' => $creator->id,
    ]);

    $rows = [];
    for ($i = 1; $i <= $lines; $i++) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i]);
        PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id, 'excel_row_id' => $row->id, 'po_no' => 'HB1']);
        $rows[] = $row;
    }

    return [$pra, $header, $rows, $file];
}

it('lists approved PRAs as awaiting payment and records the reference on every line', function () {
    $user = accountsUser();
    [$pra, $header, $rows] = approvedPra();

    $this->actingAs($user)->get(route('account.payments.index'))
        ->assertOk()->assertSee($pra->request_no)->assertSee('Record Payment');

    $this->actingAs($user)->post(route('account.payments.record', $pra), ['payment_reference' => 'PV-77'])
        ->assertRedirect()->assertSessionHas('success');

    foreach ($rows as $row) {
        expect(ExcelCell::where('row_id', $row->id)->where('header_id', $header->id)->value('value'))->toBe('PV-77');
    }

    $this->actingAs($user)->get(route('account.payments.index', ['tab' => 'paid']))
        ->assertOk()->assertSee($pra->request_no)->assertSee('PV-77');
    $this->actingAs($user)->get(route('account.payments.index'))
        ->assertOk()->assertDontSee($pra->request_no);
});

it('counts a PRA as paid when the reference was typed into the workspace', function () {
    $user = accountsUser();
    [$pra, $header, $rows] = approvedPra();

    foreach ($rows as $row) {
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => 'MANUAL-1']);
    }

    $this->actingAs($user)->get(route('account.payments.index', ['tab' => 'paid']))->assertOk()->assertSee($pra->request_no);
});

it('does not let Accounts bypass an approval step that is still pending', function () {
    $user = accountsUser();
    [$pra] = approvedPra('pending_completion');

    $this->actingAs($user)->get(route('account.payments.index'))->assertOk()->assertSee('Open PRA')->assertDontSee('Record Payment');

    $this->actingAs($user)->post(route('account.payments.record', $pra), ['payment_reference' => 'PV-1'])
        ->assertSessionHas('warning');
    expect(ExcelCell::count())->toBe(0);
});

it('requires a reference and hides PRAs of another buyer from a confined user', function () {
    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $other = Buyer::create(['buyer_name' => 'Other', 'is_active' => true]);
    $user = accountsUser();
    $user->forceFill(['buyer_id' => $mine->id])->save();
    $user->buyers()->attach($mine->id);
    $user = $user->fresh();

    [$ownPra] = approvedPra('approved', $mine->id);
    [$foreignPra] = approvedPra('approved', $other->id);

    $this->actingAs($user)->get(route('account.payments.index'))
        ->assertOk()->assertSee($ownPra->request_no)->assertDontSee($foreignPra->request_no);

    $this->actingAs($user)->post(route('account.payments.record', $ownPra), ['payment_reference' => ''])
        ->assertSessionHasErrors('payment_reference');
    $this->actingAs($user)->post(route('account.payments.record', $foreignPra), ['payment_reference' => 'X'])
        ->assertNotFound();
});
