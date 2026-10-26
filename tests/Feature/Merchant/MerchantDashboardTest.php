<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\BomTableSync;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * The Merchandising dashboard: orders, booking / PI / payment pending, PRAs and
 * shipments, read from the standard tables and the workspace status cells.
 */
beforeEach(fn () => Carbon::setTestNow('2026-10-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function mdWorkspace(Buyer $buyer, array $rows): void
{
    $role = Role::findOrCreate('merchant', 'web');
    $file = ExcelFile::create([
        'file_name' => 'd.xlsx', 'original_file_name' => 'd.xlsx', 'file_path' => 'd',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id,
    ]);

    $keys = [
        'season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name',
        'contract_number' => 'Contract Number', 'contract_shipment_date' => 'Contract Shipment date',
        'customer_contract_quantity' => 'Customer Contract Quantity',
        'material_description' => 'Material Description', 'bom_quantity' => 'BOM Quantity',
        'material_po_number' => 'Material PO Number', 'pi_status' => 'PI Status',
        'payment_status' => 'Payment Status', 'receipt_qty' => 'Receipt Qty',
        'order_to_be_placed_by' => 'Order to be placed by',
    ];

    $headers = [];
    $position = 1;
    foreach ($keys as $key => $name) {
        $headers[$key] = ExcelHeader::firstOrCreate(['header_key' => $key], ['header_name' => $name, 'owner_role_id' => $role->id, 'position' => $position++]);
    }

    foreach ($rows as $i => $values) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach ($values as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $headers[$key]->id, 'value' => (string) $value]);
        }
    }

    app(BomTableSync::class)->syncFile($file);
}

function mdRow(array $over = []): array
{
    return $over + [
        'season_name' => 'SP27', 'style_name' => '748268', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1',
        'contract_shipment_date' => '2026-11-05', 'customer_contract_quantity' => '1200', 'bom_quantity' => '100',
    ];
}

function mdMerchant(array $buyerIds = []): User
{
    $user = User::factory()->create(['status' => 1]);
    $user->assignRole(Role::findOrCreate('merchant', 'web'));

    if ($buyerIds !== [] && method_exists($user, 'buyers')) {
        $user->buyers()->sync($buyerIds);
    }

    return $user;
}

it('shows orders and the booking, PI and payment pending counts', function () {
    $ann = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    mdWorkspace($ann, [
        mdRow(['material_description' => 'Shell', 'order_to_be_placed_by' => '2026-10-01']),             // booking pending, late
        mdRow(['material_description' => 'Lining', 'material_po_number' => 'PO1']),                       // PI pending
        mdRow(['material_description' => 'Zipper', 'material_po_number' => 'PO2', 'pi_status' => 'PI Received', 'payment_status' => 'Pmt Pending']), // payment pending
        mdRow(['material_description' => 'Label', 'material_po_number' => 'PO3', 'pi_status' => 'PI Received', 'payment_status' => 'Pmt Done', 'receipt_qty' => '50']),
    ]);

    PaymentRequest::create(['request_no' => 'PRA-1', 'buyer_name' => 'Ann Taylor', 'status' => PaymentRequest::STATUS_PENDING_APPROVAL, 'created_by' => User::factory()->create()->id]);

    $response = $this->actingAs(mdMerchant())->get(route('merchant.dashboard'))->assertOk();

    $kpis = $response->viewData('kpis');
    expect($kpis)->toMatchArray([
        'orders' => 1, 'styles' => 1, 'order_qty' => 1200.0, 'materials' => 4,
        'booking_pending' => 1, 'booking_late' => 1, 'pi_pending' => 1, 'payment_pending' => 1,
        'received' => 1, 'shipping_soon' => 1, 'pra_pending' => 1,
    ]);
    expect($response->viewData('pipeline'))->toMatchArray(['Not ordered' => 1, 'Ordered' => 1, 'PI received' => 1, 'Received' => 1]);
    expect($response->viewData('upcoming'))->toHaveCount(1)
        ->and($response->viewData('attention')->first()['late'])->toBe(1);

    $response->assertSee('Booking Pending')->assertSee('PI Pending')->assertSee('Payment Pending')
        ->assertSee('PRA Pending')->assertSee('md-chart-data', false);
});

it('renders an empty dashboard without orders', function () {
    $this->actingAs(mdMerchant())->get(route('merchant.dashboard'))
        ->assertOk()->assertSee('No orders are linked yet');
});
