<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\BuyerOrder;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\OrderSizeQuantity;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** Buyer orders (M-2): register fields per customer PO and the order approval. */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $file = \App\Models\ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing',
        'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    foreach (['WHITE' => 600, 'BLACK' => 400] as $color => $qty) {
        OrderLine::create(['excel_file_id' => $file->id, 'line_key' => md5($color), 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id,
            'style_no' => '748268', 'gmts_color' => $color, 'customer_po_no' => 'PO-1', 'ship_date' => '2026-12-10', 'order_qty' => $qty]);
    }
});

it('lists the PO from the workspace with its figures and saves register fields', function () {
    OrderSizeQuantity::create(['mother_style_id' => $this->mother->id, 'gmts_color' => 'WHITE', 'customer_po_no' => 'PO-1', 'size' => 'M', 'qty' => 500]);
    $costing = Costing::create(['mother_style_id' => $this->mother->id, 'mode' => 'buyer', 'revision' => 1, 'status' => 'final', 'created_by' => $this->merchant->id]);

    $rows = $this->actingAs($this->merchant)->get(route('merchant.orders.show', $this->mother))->assertOk()
        ->assertSee('Buyer Orders')->viewData('rows');
    expect($rows)->toHaveCount(1)->and($rows[0]['order_qty'])->toBe(1000.0)->and($rows[0]['size_total'])->toBe(500.0)
        ->and($rows[0]['colors'])->toBe(['BLACK', 'WHITE']);

    $this->put(route('merchant.orders.update', $this->mother), ['po' => 'PO-1', 'destination' => 'Hamburg', 'contract_ref' => 'LC-77', 'costing_id' => $costing->id])
        ->assertSessionHas('success');
    expect(BuyerOrder::first())->destination->toBe('Hamburg')->contract_ref->toBe('LC-77')->costing_id->toBe($costing->id);

    // A quotation of another style is refused.
    $other = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $this->mother->buyer_season_id, 'style_no' => '999']);
    $foreign = Costing::create(['mother_style_id' => $other->id, 'mode' => 'buyer', 'revision' => 1, 'status' => 'final', 'created_by' => $this->merchant->id]);
    $this->put(route('merchant.orders.update', $this->mother), ['po' => 'PO-1', 'costing_id' => $foreign->id])->assertSessionHasErrors('costing_id');
});

it('runs submit, return, resubmit and approve, and flags a later change', function () {
    $this->actingAs($this->merchant)->put(route('merchant.orders.submit', $this->mother), ['po' => 'PO-1', 'destination' => 'Hamburg'])->assertSessionHas('success');
    $order = BuyerOrder::firstOrFail();
    expect($order->approval_status)->toBe('pending');
    expect(AppNotification::where('type', 'order_approval')->where('user_id', $this->admin->id)->count())->toBe(1);

    // The submitter does not approve their own order while an approver exists.
    $this->post(route('merchant.orders.approve', $order))->assertSessionHas('warning');

    $this->actingAs($this->admin)->post(route('merchant.orders.return', $order), [])->assertSessionHasErrors('comment');
    $this->post(route('merchant.orders.return', $order), ['comment' => 'Wrong destination'])->assertSessionHas('success');
    expect($order->fresh()->approval_status)->toBe('returned');

    $this->actingAs($this->merchant)->put(route('merchant.orders.submit', $this->mother), ['po' => 'PO-1'])->assertSessionHas('success');
    $this->actingAs($this->admin)->post(route('merchant.orders.approve', $order), ['comment' => 'OK'])->assertSessionHas('success');
    expect($order->fresh()->approval_status)->toBe('approved');

    $this->get(route('merchant.orders.show', $this->mother))->assertDontSee('Changed after approval');
    OrderLine::where('gmts_color', 'WHITE')->update(['order_qty' => 700]);
    $this->get(route('merchant.orders.show', $this->mother))->assertSee('Changed after approval');
});

it('refuses submit for a PO that is not in the workspace and blocks other buyers', function () {
    $this->actingAs($this->merchant)->put(route('merchant.orders.submit', $this->mother), ['po' => 'NOPE'])->assertSessionHas('warning');
    expect(BuyerOrder::where('approval_status', 'pending')->count())->toBe(0);

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.orders.show', $this->mother))->assertForbidden();
});

/** The optional gate: Supply Chain cannot generate a PO for a customer PO whose order is not approved. */
function gateRow($test): \App\Models\ExcelRow
{
    $line = \App\Models\OrderLine::where('customer_po_no', 'PO-1')->first();
    $row = \App\Models\ExcelRow::create(['excel_file_id' => $line->excel_file_id, 'row_number' => 1]);
    \App\Models\BomItem::create(['order_line_id' => $line->id, 'excel_row_id' => $row->id, 'material_description' => 'Zipper']);

    return $row;
}

it('does not block anything while the Admin gate is off', function () {
    $row = gateRow($this);
    expect(app(\App\Services\OrderApprovals::class)->blockReason($row))->toBeNull()
        ->and(app(\App\Services\BomApprovals::class)->blockReason($row))->toBeNull();
});

it('blocks a PO until the order is approved, again when it changes, and never an unlinked row', function () {
    $row = gateRow($this);
    $service = app(\App\Services\OrderApprovals::class);
    \App\Models\AppSetting::put(\App\Services\OrderApprovals::KEY_REQUIRED_FOR_PO, 1);

    // Never registered, then submitted: both mean "not approved".
    expect($service->blockReason($row))->toContain('PO PO-1')->toContain('748268')->toContain('not approved');
    $this->actingAs($this->merchant)->put(route('merchant.orders.submit', $this->mother), ['po' => 'PO-1']);
    expect(app(\App\Services\BomApprovals::class)->blockReason($row))->toContain('not approved');

    // Supply Chain is refused with the same reason (the existing generate action).
    $sc = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('supply_chain', 'web'));
    $this->actingAs($sc)->postJson(route('supply_chain.bookings.generate', $row))->assertStatus(422)->assertJsonPath('success', false)
        ->assertJsonPath('message', $service->blockReason($row));

    $this->actingAs($this->admin)->post(route('merchant.orders.approve', BuyerOrder::firstOrFail()), ['comment' => 'OK']);
    expect($service->blockReason($row))->toBeNull();

    OrderLine::where('gmts_color', 'WHITE')->update(['order_qty' => 700]);
    expect($service->blockReason($row))->toContain('changed after it was approved');

    $loose = \App\Models\ExcelRow::create(['excel_file_id' => $row->excel_file_id, 'row_number' => 99]);
    expect($service->blockReason($loose))->toBeNull();
});

it('lets only Admin switch the gate, from the approval settings, and says so on Buyer Orders', function () {
    $this->actingAs($this->admin)->post(route('bom_approvals.settings'), ['order_required_for_po' => 1])->assertSessionHas('success');
    expect(\App\Services\OrderApprovals::requiredForPo())->toBeTrue();
    $this->get(route('bom_approvals.index'))->assertOk()->assertSee('buyer order (customer PO) is approved');
    $this->get(route('merchant.orders.show', $this->mother))->assertSee('cannot generate a PO');

    $this->actingAs($this->merchant)->post(route('bom_approvals.settings'), ['order_required_for_po' => 0])->assertForbidden();
    $this->actingAs($this->admin)->post(route('bom_approvals.settings'), [])->assertSessionHas('success');
    expect(\App\Services\OrderApprovals::requiredForPo())->toBeFalse();
});
