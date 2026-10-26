<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\BomTableSync;
use App\Services\StyleRollup;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Season and buyer status: all styles of a season at a glance, read-only for
 * Admin and Management, confined to a user's own buyers.
 */
function seasonWorkspace(Buyer $buyer, array $rows): ExcelFile
{
    $role = Role::findOrCreate('merchant', 'web');
    $file = ExcelFile::create([
        'file_name' => 's.xlsx', 'original_file_name' => 's.xlsx', 'file_path' => 's',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id,
    ]);

    $headers = [];
    $position = 1;
    foreach (['season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name', 'material_description' => 'Material Description',
        'material_po_number' => 'Material PO Number', 'receipt_qty' => 'Receipt Qty', 'order_to_be_placed_by' => 'Order to be placed by'] as $key => $name) {
        $headers[$key] = ExcelHeader::firstOrCreate(['header_key' => $key], ['header_name' => $name, 'owner_role_id' => $role->id, 'position' => $position++]);
    }

    foreach ($rows as $i => $values) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach ($values + ['season_name' => 'SP27'] as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $headers[$key]->id, 'value' => (string) $value]);
        }
    }

    app(BomTableSync::class)->syncFile($file);

    return $file;
}

function userWith(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

it('summarises every style of a season and totals them', function () {
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    seasonWorkspace($buyer, [
        ['style_name' => '111', 'material_description' => 'a', 'material_po_number' => 'HB1', 'receipt_qty' => '5'],
        ['style_name' => '111', 'material_description' => 'b', 'order_to_be_placed_by' => '2026-10-01'],
        ['style_name' => '222', 'material_description' => 'c', 'material_po_number' => 'HB2'],
    ]);

    $season = BuyerSeason::firstOrFail();
    $data = app(StyleRollup::class)->forSeason($season, Carbon::parse('2026-10-20'));

    $byStyle = $data['styles']->mapWithKeys(fn ($s) => [$s['mother']->style_no => $s['kpis']]);
    expect($byStyle['111'])->toMatchArray(['materials' => 2, 'ordered' => 1, 'received' => 1, 'late' => 1]);
    expect($byStyle['222'])->toMatchArray(['materials' => 1, 'ordered' => 1, 'received' => 0]);
    expect($data['totals'])->toMatchArray(['materials' => 3, 'ordered' => 2, 'received' => 1, 'late' => 1]);
});

it('lists seasons for a buyer and opens a season with the late style first', function () {
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    seasonWorkspace($buyer, [
        ['style_name' => '111', 'material_description' => 'a', 'material_po_number' => 'HB1'],
        ['style_name' => '222', 'material_description' => 'b', 'order_to_be_placed_by' => '2020-01-01'],
    ]);
    $season = BuyerSeason::firstOrFail();
    $merchant = userWith('merchant');

    $this->actingAs($merchant)->get(route('merchant.status.index'))
        ->assertOk()->assertSee('Ann Taylor')->assertSee('SP27')->assertSee('View Season');

    $page = $this->actingAs($merchant)->get(route('merchant.status.season', $season))
        ->assertOk()->assertSee('View Status')->assertSee('1 late');
    expect($page->viewData('styles')->first()['mother']->style_no)->toBe('222');
});

it('lets Admin and Management read it, with working links, but not the editable library', function () {
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    seasonWorkspace($buyer, [['style_name' => '111', 'material_description' => 'a', 'material_po_number' => 'HB1']]);
    $season = BuyerSeason::firstOrFail();
    $mother = $season->motherStyles()->firstOrFail();

    foreach (['management', 'admin'] as $role) {
        $viewer = userWith($role);

        $this->actingAs($viewer)->get(route('merchant.status.index'))->assertOk();
        $this->actingAs($viewer)->get(route('merchant.status.season', $season))->assertOk()
            ->assertSee(route('merchant.status.style', $mother), false);
        $this->actingAs($viewer)->get(route('merchant.status.style', $mother))->assertOk()
            ->assertSee('Back to Season Status')->assertSee('HB1');
        $this->actingAs($viewer)->get(route('merchant.styles.index'))->assertForbidden();
    }

    $this->actingAs(userWith('store'))->get(route('merchant.status.index'))->assertForbidden();
});

it('keeps a buyer-confined user to their own buyers', function () {
    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $other = Buyer::create(['buyer_name' => 'Other', 'is_active' => true]);
    seasonWorkspace($other, [['style_name' => '999', 'material_description' => 'x']]);
    $otherSeason = BuyerSeason::where('buyer_id', $other->id)->firstOrFail();

    Role::findOrCreate('merchant', 'web');
    $scoped = User::factory()->create(['buyer_id' => $mine->id])->assignRole('merchant');
    $scoped->buyers()->attach($mine->id);
    $scoped = $scoped->fresh();

    $this->actingAs($scoped)->get(route('merchant.status.index'))->assertOk()->assertDontSee('Other');
    $this->actingAs($scoped)->get(route('merchant.status.season', $otherSeason))->assertForbidden();
});
