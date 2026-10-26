<?php

use App\Models\AppNotification;
use App\Models\BomItem;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Costings;
use Spatie\Permission\Models\Role;

/**
 * Costing approval (finalize -> approve / return) and the pre-costing vs BOM
 * comparison on the costing page.
 */
function caUser(string $role, array $attrs = []): User
{
    return User::factory()->create($attrs + ['status' => 1])->assignRole(Role::findOrCreate($role, 'web'));
}

beforeEach(function () {
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $this->merchant = caUser('merchant');
    $this->head = caUser('merchant', ['is_department_admin' => true]);

    $costings = app(Costings::class);
    $this->costing = $costings->start($this->mother, 'buyer', $this->merchant);
    $costings->save($this->costing, ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => 10], [
        ['head' => 'fabric', 'description' => 'Shell Fabric', 'qty' => 1.2, 'unit' => 'yds', 'wastage_pct' => 0, 'rate' => 3],
        ['head' => 'trims', 'description' => 'Zipper', 'qty' => 1, 'unit' => 'pcs', 'wastage_pct' => 0, 'rate' => 0.4],
        ['head' => 'cm', 'description' => 'CM', 'qty' => 1, 'unit' => '', 'wastage_pct' => 0, 'rate' => 2],
    ]);
});

it('sends a finalized costing for approval and lets the Merchandising Head approve it', function () {
    $this->actingAs($this->merchant)->post(route('costing.finalize', $this->costing))->assertSessionHas('success');

    expect($this->costing->fresh()->approval_status)->toBe(Costing::APPROVAL_PENDING)
        ->and(AppNotification::where('user_id', $this->head->id)->where('type', 'costing_approval')->exists())->toBeTrue();

    // The merchant who finalized cannot approve while someone else can.
    $this->actingAs($this->merchant)->post(route('costing.approve', $this->costing))->assertSessionHas('warning');

    $this->actingAs($this->head)->get(route('costing.show', $this->mother))->assertOk()->assertSee('Approve Costing');
    $this->actingAs($this->head)->post(route('costing.approve', $this->costing), ['comment' => 'OK'])->assertSessionHas('success');

    $c = $this->costing->fresh();
    expect($c->approval_status)->toBe(Costing::APPROVAL_APPROVED)->and($c->approved_by)->toBe($this->head->id)
        ->and(AppNotification::where('user_id', $this->merchant->id)->where('title', 'Costing approved')->exists())->toBeTrue();
});

it('returns a costing to draft with a reason', function () {
    $this->actingAs($this->merchant)->post(route('costing.finalize', $this->costing));

    $this->actingAs($this->head)->post(route('costing.return', $this->costing), [])->assertSessionHasErrors('comment');
    $this->actingAs($this->head)->post(route('costing.return', $this->costing), ['comment' => 'CM too low'])->assertSessionHas('success');

    $c = $this->costing->fresh();
    expect($c->isDraft())->toBeTrue()->and($c->approval_status)->toBe(Costing::APPROVAL_RETURNED)->and($c->approval_comment)->toBe('CM too low')
        ->and(AppNotification::where('user_id', $this->merchant->id)->where('title', 'Costing returned')->exists())->toBeTrue();

    $this->actingAs($this->merchant)->get(route('costing.show', $this->mother))->assertSee('CM too low');
});

it('compares costed materials with the BOM consumption and PI rate', function () {
    $role = Role::findOrCreate('merchant', 'web');
    $file = ExcelFile::create(['file_name' => 'x', 'original_file_name' => 'x', 'file_path' => 'x', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->mother->buyer_id]);
    $rate = ExcelHeader::create(['header_key' => 'pi_rate', 'header_name' => 'PI Rate', 'owner_role_id' => $role->id, 'position' => 1, 'field_type' => 'number', 'value_mode' => 'input', 'is_active' => true]);
    $line = OrderLine::create(['excel_file_id' => $file->id, 'line_key' => 'k', 'buyer_id' => $this->mother->buyer_id, 'mother_style_id' => $this->mother->id, 'style_no' => '748268', 'gmts_color' => 'W', 'customer_po_no' => 'C1', 'order_qty' => 100]);
    foreach ([['SHELL FABRIC', 1.5, '3.20'], ['Care Label', 1, null]] as $i => [$desc, $cons, $piRate]) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        BomItem::create(['order_line_id' => $line->id, 'excel_row_id' => $row->id, 'material_description' => $desc, 'uom' => 'yds', 'consumption_incl_yy' => $cons]);
        if ($piRate) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $rate->id, 'value' => $piRate]);
        }
    }

    $cmp = $this->actingAs($this->merchant)->get(route('costing.show', $this->mother))->assertOk()
        ->assertSee('Pre-costing vs BOM')->viewData('comparison');

    $shell = $cmp['rows']->firstWhere('description', 'Shell Fabric');
    expect($shell['bom']['cons'])->toBe(1.5)->and($shell['bom']['rate'])->toBe(3.2)
        ->and($shell['bom_amount'])->toBe(4.8)->and(round($shell['difference'], 2))->toBe(1.2)
        ->and($cmp['rows']->firstWhere('description', 'Zipper')['bom'])->toBeNull()
        ->and($cmp['uncosted']->pluck('description')->all())->toBe(['Care Label']);
});
