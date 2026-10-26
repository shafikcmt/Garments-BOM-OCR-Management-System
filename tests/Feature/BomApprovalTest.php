<?php

use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\Buyer;
use App\Models\BomApproval;
use App\Models\BomItem;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\BomApprovals;
use App\Services\BomTableSync;
use Spatie\Permission\Models\Role;

/**
 * BOM approval: submit -> Merchandising Head -> [Management] -> approved, with a
 * return path, re-approval when the BOM changes, and an optional PO gate.
 */
function baUser(string $role, array $attrs = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attrs + ['status' => 1])->assignRole($role);
}

/** @return array{0: MotherStyle, 1: ExcelRow} a style with two material rows */
function baStyle(): array
{
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $role = Role::findOrCreate('merchant', 'web');
    $file = ExcelFile::create(['file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b', 'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id]);

    $h = [];
    $pos = 1;
    foreach (['season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name', 'material_description' => 'Material Description', 'bom_quantity' => 'BOM Quantity'] as $key => $name) {
        $h[$key] = ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $role->id, 'position' => $pos++]);
    }

    $first = null;
    foreach ([['Zipper', '100'], ['Button', '50']] as $i => [$desc, $qty]) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach (['season_name' => 'SP27', 'style_name' => '748268', 'gmts_color_name' => 'WHITE', 'material_description' => $desc, 'bom_quantity' => $qty] as $k => $v) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $h[$k]->id, 'value' => $v]);
        }
        $first ??= $row;
    }

    app(BomTableSync::class)->syncFile($file);

    return [MotherStyle::where('style_no', '748268')->firstOrFail(), $first, $h];
}

function baChangeQty(ExcelRow $row, array $headers, string $qty): void
{
    ExcelCell::where('row_id', $row->id)->where('header_id', $headers['bom_quantity']->id)->update(['value' => $qty]);
    app(BomTableSync::class)->syncRows($row->excelFile, [$row->id]);
}

it('submits a BOM, tells the Merchandising Head, and approves it in one level', function () {
    [$mother] = baStyle();
    $merchant = baUser('merchant');
    $head = baUser('merchant', ['is_department_admin' => true]);
    $supply = baUser('supply_chain');
    $service = app(BomApprovals::class);

    expect($service->state($mother))->toBe('not_submitted');
    $this->actingAs($merchant)->get(route('merchant.styles.mothers.show', $mother))->assertOk()->assertSee('Submit for Approval');

    $this->actingAs($merchant)->post(route('bom_approvals.submit', $mother))->assertSessionHas('success');
    $approval = BomApproval::sole();
    expect($approval->status)->toBe('pending')->and($approval->level)->toBe(1);
    expect(AppNotification::where('type', 'bom_approval_request')->pluck('user_id')->all())->toBe([$head->id]);

    // Not the one who submitted it, and not an ordinary merchant.
    expect($service->canDecide($merchant, $approval))->toBeFalse();
    $this->actingAs($merchant)->post(route('bom_approvals.approve', $approval))->assertSessionHas('warning');

    $this->actingAs($head)->post(route('bom_approvals.approve', $approval), ['comment' => 'ok'])->assertSessionHas('success', 'BOM approved.');
    expect($service->state($mother->fresh()))->toBe('approved');

    // Ready-to-order goes to Supply Chain and back to the submitter.
    expect(AppNotification::where('type', 'bom_approved')->pluck('user_id')->sort()->values()->all())->toBe(collect([$supply->id, $merchant->id])->sort()->values()->all());
    expect($approval->steps()->pluck('action')->all())->toBe(['submitted', 'approved']);
});

it('refuses a style with no BOM and a second submission', function () {
    $buyer = Buyer::create(['buyer_name' => 'B', 'is_active' => true]);
    $season = \App\Models\BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);
    $empty = MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '9']);
    $merchant = baUser('merchant');

    $this->actingAs($merchant)->post(route('bom_approvals.submit', $empty))->assertSessionHas('warning');
    expect(BomApproval::count())->toBe(0);

    [$mother] = baStyle();
    $this->actingAs($merchant)->post(route('bom_approvals.submit', $mother))->assertSessionHas('success');
    $this->actingAs($merchant)->post(route('bom_approvals.submit', $mother))->assertSessionHas('warning');
    expect(BomApproval::count())->toBe(1);
});

it('adds a Management level when the admin switches it on', function () {
    [$mother] = baStyle();
    AppSetting::put(BomApprovals::KEY_MANAGEMENT_STAGE, 1);
    $merchant = baUser('merchant');
    $head = baUser('merchant', ['is_department_admin' => true]);
    $mgmt = baUser('management');

    $this->actingAs($merchant)->post(route('bom_approvals.submit', $mother));
    $approval = BomApproval::sole();

    $this->actingAs($head)->post(route('bom_approvals.approve', $approval))->assertSessionHas('success', fn ($m) => str_contains($m, 'Management'));
    expect($approval->fresh()->level)->toBe(2)->and($approval->fresh()->status)->toBe('pending');
    expect(AppNotification::where('type', 'bom_approval_request')->where('user_id', $mgmt->id)->count())->toBe(1);

    // The head cannot decide a level that belongs to Management.
    expect(app(BomApprovals::class)->canDecide($head, $approval->fresh()))->toBeFalse();

    $this->actingAs($mgmt)->post(route('bom_approvals.approve', $approval))->assertSessionHas('success', 'BOM approved.');
    expect(app(BomApprovals::class)->state($mother->fresh()))->toBe('approved');
});

it('returns a BOM with a reason and lets the merchant submit it again', function () {
    [$mother] = baStyle();
    $merchant = baUser('merchant');
    $head = baUser('merchant', ['is_department_admin' => true]);

    $this->actingAs($merchant)->post(route('bom_approvals.submit', $mother));
    $approval = BomApproval::sole();

    $this->actingAs($head)->post(route('bom_approvals.return', $approval), ['comment' => ''])->assertSessionHasErrors('comment');
    $this->actingAs($head)->post(route('bom_approvals.return', $approval), ['comment' => 'Zipper qty is wrong'])->assertSessionHas('success');

    expect($approval->fresh()->status)->toBe('returned');
    expect(AppNotification::where('type', 'bom_returned')->where('user_id', $merchant->id)->value('message'))->toContain('Zipper qty is wrong');

    $this->actingAs($merchant)->post(route('bom_approvals.submit', $mother))->assertSessionHas('success');
    expect($approval->fresh()->status)->toBe('pending')->and($approval->fresh()->cycle)->toBe(2);
    expect($approval->steps()->pluck('action')->all())->toBe(['submitted', 'returned', 'submitted']);
});

it('shows an approved BOM as changed when it is edited, tells people once, and refuses to approve a stale submission', function () {
    [$mother, $row, $headers] = baStyle();
    $merchant = baUser('merchant');
    $head = baUser('merchant', ['is_department_admin' => true]);
    $service = app(BomApprovals::class);

    $service->submit($mother, $merchant);
    $service->approve(BomApproval::sole(), $head, null);
    expect($service->state($mother->fresh()))->toBe('approved');

    baChangeQty($row, $headers, '999');
    expect($service->state($mother->fresh()))->toBe('changed');

    $service->afterRowsChanged([$row->id]);
    $service->afterRowsChanged([$row->id]);
    expect(AppNotification::where('type', 'bom_changed')->where('user_id', $merchant->id)->count())->toBe(1);

    // Submitted, then edited before the head decides: it cannot be approved as is.
    $service->submit($mother->fresh(), $merchant);
    baChangeQty($row, $headers, '1');
    expect(fn () => $service->approve(BomApproval::sole()->fresh(), $head, null))->toThrow(DomainException::class, 'changed after it was submitted');
});

it('does not let people approve their own submission unless nobody else could', function () {
    [$mother] = baStyle();
    $head = baUser('merchant', ['is_department_admin' => true]);
    $service = app(BomApprovals::class);

    // The only approver submitted it: allowed, so a one-person team is not stuck.
    $service->submit($mother, $head);
    expect($service->canDecide($head, BomApproval::sole()))->toBeTrue();

    // A second head exists now: the submitter may no longer approve it.
    $other = baUser('merchant', ['is_department_admin' => true]);
    expect($service->canDecide($head, BomApproval::sole()))->toBeFalse()->and($service->canDecide($other, BomApproval::sole()))->toBeTrue();
});

it('blocks PO generation for an unapproved style only when the admin gate is on', function () {
    [$mother, $row, $headers] = baStyle();
    $service = app(BomApprovals::class);
    $merchant = baUser('merchant');
    $head = baUser('merchant', ['is_department_admin' => true]);

    expect($service->blockReason($row))->toBeNull();

    AppSetting::put(BomApprovals::KEY_REQUIRED_FOR_PO, 1);
    expect($service->blockReason($row))->toContain('748268')->toContain('not approved');

    // Ajax generate from Supply Chain is refused with the reason.
    $this->actingAs(baUser('supply_chain'))->postJson(route('supply_chain.bookings.generate', $row))
        ->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('message', $service->blockReason($row));

    $service->submit($mother, $merchant);
    $service->approve(BomApproval::sole(), $head, null);
    expect($service->blockReason($row))->toBeNull();

    baChangeQty($row, $headers, '7');
    expect($service->blockReason($row))->not->toBeNull();

    // A row that is not linked to any style is never blocked.
    $loose = ExcelRow::create(['excel_file_id' => $row->excel_file_id, 'row_number' => 99]);
    expect($service->blockReason($loose))->toBeNull();
});

it('shows the queue, the season badge and the admin settings to the right people', function () {
    [$mother] = baStyle();
    $merchant = baUser('merchant');
    $head = baUser('merchant', ['is_department_admin' => true]);
    app(BomApprovals::class)->submit($mother, $merchant);

    $this->actingAs($head)->get(route('bom_approvals.index'))->assertOk()->assertSee('748268')->assertSee('Waiting for Merchandising Head')->assertDontSee('Second approval by Management');
    $this->actingAs($merchant)->get(route('bom_approvals.index'))->assertOk()->assertSee('Nothing is waiting for you');
    $this->actingAs(baUser('admin'))->get(route('bom_approvals.index'))->assertOk()->assertSee('Second approval by Management');
    $this->actingAs(baUser('management'))->get(route('bom_approvals.index', ['tab' => 'all']))->assertOk()->assertSee('748268');

    $this->actingAs($head)->get(route('merchant.styles.mothers.show', $mother))->assertOk()->assertSee('Approve')->assertSee('Return to Merchant');
    $this->actingAs(baUser('management'))->get(route('merchant.status.season', $mother->season))->assertOk()->assertSee('Waiting for Merchandising Head');

    $this->actingAs($merchant)->post(route('bom_approvals.settings'), ['required_for_po' => 1])->assertForbidden();
    $this->actingAs(baUser('admin'))->post(route('bom_approvals.settings'), ['required_for_po' => 1])->assertSessionHas('success');
    expect(BomApprovals::requiredForPo())->toBeTrue()->and(BomApprovals::managementStage())->toBeFalse();

    $this->actingAs(baUser('store'))->get(route('bom_approvals.index'))->assertForbidden();
});
