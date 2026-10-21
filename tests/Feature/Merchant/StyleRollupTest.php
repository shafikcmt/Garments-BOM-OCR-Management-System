<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\BomTableSync;
use App\Services\StyleRollup;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * The style roll-up: orders and materials of one mother style, and how far each
 * material has come, read from the same cells the departments fill in.
 */
function rollupWorkspace(array $rows, ?Buyer $buyer = null): array
{
    $buyer ??= Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $role = Role::findOrCreate('merchant', 'web');
    $file = ExcelFile::create([
        'file_name' => 'l.xlsx', 'original_file_name' => 'l.xlsx', 'file_path' => 'l',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id,
    ]);

    $keys = [
        'season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name',
        'contract_number' => 'Contract Number', 'material_description' => 'Material Description', 'bom_quantity' => 'BOM Quantity',
        'material_po_number' => 'Material PO Number', 'vendor_name' => 'Vendor Name', 'pi_status' => 'PI Status',
        'payment_status' => 'Payment Status', 'receipt_qty' => 'Receipt Qty', 'bulk_issued' => 'Bulk Issued',
        'order_to_be_placed_by' => 'Order to be placed by',
    ];
    $headers = [];
    $position = 1;
    foreach ($keys as $key => $name) {
        $headers[$key] = ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $role->id, 'position' => $position++]);
    }

    foreach ($rows as $i => $values) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach ($values as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $headers[$key]->id, 'value' => (string) $value]);
        }
    }

    app(BomTableSync::class)->syncFile($file);

    return [$buyer, MotherStyle::where('style_no', '748268')->first()];
}

function baseRow(array $over = []): array
{
    return $over + ['season_name' => 'SP27', 'style_name' => '748268 (866317)', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1', 'bom_quantity' => '100'];
}

it('shows each material at the furthest stage it has reached', function () {
    [, $mother] = rollupWorkspace([
        baseRow(['material_description' => 'A none', 'order_to_be_placed_by' => '2030-01-01']),
        baseRow(['material_description' => 'B ordered', 'material_po_number' => 'HB1', 'vendor_name' => 'Acme']),
        baseRow(['material_description' => 'C pi', 'material_po_number' => 'HB2', 'pi_status' => 'PI Received', 'payment_status' => 'Pmt Pending']),
        baseRow(['material_description' => 'D paid', 'material_po_number' => 'HB3', 'pi_status' => 'PI Received', 'payment_status' => 'Pmt Done']),
        baseRow(['material_description' => 'E received', 'material_po_number' => 'HB4', 'receipt_qty' => '1,500']),
        baseRow(['material_description' => 'F issued', 'material_po_number' => 'HB5', 'receipt_qty' => '10', 'bulk_issued' => '4']),
        baseRow(['material_description' => 'G waiting', 'material_po_number' => 'PO Pending']),
    ]);

    $data = app(StyleRollup::class)->forMother($mother);
    $stages = $data['materials']->pluck('stage', 'description');

    expect($stages->all())->toBe([
        'A none' => 'Not ordered', 'B ordered' => 'Ordered', 'C pi' => 'PI received', 'D paid' => 'Paid',
        'E received' => 'Received', 'F issued' => 'Issued', 'G waiting' => 'Not ordered',
    ]);
    expect($data['kpis'])->toMatchArray(['order_lines' => 1, 'materials' => 7, 'ordered' => 5, 'pi_received' => 2, 'paid' => 1, 'received' => 2, 'issued' => 1]);
    expect($data['materials']->firstWhere('description', 'E received')['receipt_qty'])->toBe(1500.0);
});

it('lights up unordered material by its order-by date', function () {
    [, $mother] = rollupWorkspace([
        baseRow(['material_description' => 'late', 'order_to_be_placed_by' => '2026-10-01']),
        baseRow(['material_description' => 'soon', 'order_to_be_placed_by' => '2026-11-01']),
        baseRow(['material_description' => 'later', 'order_to_be_placed_by' => '2027-03-01']),
        baseRow(['material_description' => 'unknown']),
        baseRow(['material_description' => 'ordered late date', 'order_to_be_placed_by' => '2026-01-01', 'material_po_number' => 'HB1']),
    ]);

    $data = app(StyleRollup::class)->forMother($mother, null, Carbon::parse('2026-10-20'));

    expect($data['materials']->pluck('light', 'description')->all())->toBe([
        'late' => 'red', 'soon' => 'amber', 'later' => null, 'unknown' => null, 'ordered late date' => null,
    ]);
    expect($data['kpis']['late'])->toBe(1)->and($data['kpis']['due_soon'])->toBe(1);
});

it('renders the page and filters by child style', function () {
    [$buyer, $mother] = rollupWorkspace([
        baseRow(['material_description' => 'Zipper', 'material_po_number' => 'HB1']),
        baseRow(['style_name' => '748268', 'gmts_color_name' => 'BLACK', 'material_description' => 'Button']),
    ]);
    Role::findOrCreate('merchant', 'web');
    $user = User::factory()->create()->assignRole('merchant');
    $child = $mother->children()->firstOrFail();

    $this->actingAs($user)->get(route('merchant.styles.mothers.show', $mother))
        ->assertOk()->assertSee('Zipper')->assertSee('Button')->assertSee('HB1')->assertSee('Order lines');

    $this->actingAs($user)->get(route('merchant.styles.mothers.show', [$mother, 'child' => $child->id]))
        ->assertOk()->assertSee('Zipper')->assertDontSee('Button');

    $this->actingAs($user)->get(route('merchant.styles.index', ['buyer' => $buyer->id]))
        ->assertOk()->assertSee('View Status')->assertSee('order line(s)');
});

it('explains an empty style and keeps another buyer out', function () {
    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $other = Buyer::create(['buyer_name' => 'Other', 'is_active' => true]);
    $season = \App\Models\BuyerSeason::create(['buyer_id' => $other->id, 'code' => 'SP27']);
    $empty = MotherStyle::create(['buyer_id' => $other->id, 'buyer_season_id' => $season->id, 'style_no' => '999']);

    Role::findOrCreate('merchant', 'web');
    $free = User::factory()->create()->assignRole('merchant');
    $this->actingAs($free)->get(route('merchant.styles.mothers.show', $empty))->assertOk()->assertSee('No orders are linked');

    $scoped = User::factory()->create(['buyer_id' => $mine->id])->assignRole('merchant');
    $scoped->buyers()->attach($mine->id);
    $this->actingAs($scoped->fresh())->get(route('merchant.styles.mothers.show', $empty))->assertForbidden();
});
