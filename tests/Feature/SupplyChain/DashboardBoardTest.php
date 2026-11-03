<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\BomTableSync;
use App\Services\SupplyChainDashboard;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * The Supply Chain dashboard: every material line from booking to arrival, read
 * from the Supply Chain columns of the BOM workspace.
 */
function scWorkspace(array $rows, ?Buyer $buyer, string $style = '748268'): ExcelFile
{
    $role = Role::findOrCreate('supply_chain', 'web');
    $file = ExcelFile::create([
        'file_name' => 'l.xlsx', 'original_file_name' => 'l.xlsx', 'file_path' => 'l',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer?->id,
    ]);

    $keys = [
        'season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name',
        'contract_number' => 'Contract Number', 'material_description' => 'Material Description', 'bom_quantity' => 'BOM Quantity',
        'material_po_number' => 'Material PO Number', 'vendor_name' => 'Vendor Name', 'pi_status' => 'PI Status',
        'payment_status' => 'Payment Status', 'receipt_qty' => 'Receipt Qty', 'order_to_be_placed_by' => 'Order to be placed by',
        'po_date' => 'PO Date', 'committed_etd' => 'Committed ETD', 'atd' => 'ATD', 'committed_eta' => 'Committed ETA',
        'ata' => 'ATA', 'payment_reqd_date' => "Payment Req'd Date",
    ];
    $headers = [];
    $position = 1;
    foreach ($keys as $key => $name) {
        $headers[$key] = ExcelHeader::firstOrCreate(['header_key' => $key], ['header_name' => $name, 'owner_role_id' => $role->id, 'position' => $position++]);
    }

    foreach ($rows as $i => $values) {
        $values += ['season_name' => 'SP27', 'style_name' => $style, 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1', 'bom_quantity' => '100'];
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach ($values as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $headers[$key]->id, 'value' => (string) $value]);
        }
    }

    app(BomTableSync::class)->syncFile($file);

    return $file;
}

function scMaterials(): array
{
    return [
        ['material_description' => 'A not ordered, late', 'order_to_be_placed_by' => '2026-10-01'],
        ['material_description' => 'B ordered, no PI', 'material_po_number' => 'HB1', 'po_date' => '2026-09-01', 'vendor_name' => 'Acme'],
        ['material_description' => 'C PI in, unpaid, overdue', 'material_po_number' => 'HB2', 'pi_status' => 'PI Received', 'payment_status' => 'Pmt Pending', 'payment_reqd_date' => '2026-10-10'],
        ['material_description' => 'D in transit', 'material_po_number' => 'HB3', 'pi_status' => 'PI Received', 'payment_status' => 'Pmt Done', 'atd' => '2026-10-15', 'committed_eta' => '2026-10-25'],
        ['material_description' => 'E ETA passed', 'material_po_number' => 'HB4', 'committed_eta' => '2026-10-05', 'vendor_name' => 'Acme'],
        ['material_description' => 'F arrived (ATA)', 'material_po_number' => 'HB5', 'committed_eta' => '2026-10-05', 'ata' => '2026-10-10'],
        ['material_description' => 'G received in store', 'material_po_number' => 'HB6', 'committed_eta' => '2026-10-01', 'receipt_qty' => '10'],
    ];
}

it('counts material lines at each step from booking to arrival', function () {
    scWorkspace(scMaterials(), Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]));

    $board = app(SupplyChainDashboard::class)->build(null, false, Carbon::parse('2026-10-20'));

    expect($board['hasData'])->toBeTrue();
    expect($board['kpis'])->toMatchArray([
        'materials' => 7,
        'booking_pending' => 1,
        'booking_late' => 1,
        'pi_pending' => 4,        // B, E, F, G have a PO and no PI
        'payment_pending' => 1,   // C
        'payment_overdue' => 1,   // C: required date 10 Oct
        'in_transit' => 1,        // D: ATD, no ATA
        'eta_overdue' => 1,       // E: ETA 5 Oct, not arrived (F has an ATA, G is received)
        'eta_soon' => 1,          // D: ETA 25 Oct, within 7 days
        'arrived' => 2,           // F (ATA) and G (received in store)
    ]);
    expect($board['pipeline'])->toBe(['Not ordered' => 1, 'Ordered' => 3, 'PI received' => 1, 'Paid' => 1, 'Received' => 1, 'Issued' => 0]);
});

it('lists the lines that need attention, longest waiting first', function () {
    scWorkspace(scMaterials(), Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]));

    $board = app(SupplyChainDashboard::class)->build(null, false, Carbon::parse('2026-10-20'));

    expect($board['lateBooking']->first())->toMatchArray(['material' => 'A not ordered, late', 'days' => 19, 'buyer' => 'Ann Taylor']);
    expect($board['piPending']->first())->toMatchArray(['po' => 'HB1', 'vendor' => 'Acme', 'days' => 49]);
    expect($board['arrivalOverdue']->pluck('po')->all())->toBe(['HB4']);
    expect($board['arrivalOverdue']->first()['days'])->toBe(15);
});

it('feeds the charts: ETA months, open work per buyer and lines waiting on vendors', function () {
    scWorkspace(scMaterials(), Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]));

    $board = app(SupplyChainDashboard::class)->build(null, false, Carbon::parse('2026-10-20'));

    // Overdue first, then Oct 2026 onwards: E is overdue, D arrives in October.
    expect($board['etaMonths']['labels'][0])->toBe('Overdue')->and($board['etaMonths']['labels'][1])->toBe('Oct 2026');
    expect($board['etaMonths']['values'][0])->toBe(1)->and($board['etaMonths']['values'][1])->toBe(1);

    expect($board['byBuyer'])->toMatchArray(['labels' => ['Ann Taylor'], 'booking' => [1], 'pi' => [4], 'payment' => [1]]);

    // Waiting on a vendor = ordered, not received: B, C, D, E, F. Acme has B and E (PI pending).
    expect($board['byVendor']['labels'])->toBe(['No vendor', 'Acme']);
    expect($board['byVendor']['pi'])->toBe([1, 2])->and($board['byVendor']['payment'])->toBe([1, 0])->and($board['byVendor']['paid'])->toBe([1, 0]);
});

it('keeps a buyer-confined view to its buyers, and to untagged files when asked', function () {
    $a = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $b = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    scWorkspace([['material_description' => 'A1']], $a, '111');
    scWorkspace([['material_description' => 'B1']], $b, '222');
    scWorkspace([['material_description' => 'U1']], null, '333');

    $board = app(SupplyChainDashboard::class);

    expect($board->build(null)['kpis']['materials'])->toBe(3)                          // everyone, tagged or not
        ->and($board->build([$a->id])['kpis']['materials'])->toBe(1)                   // Ann Taylor only
        ->and($board->build([$a->id], true)['kpis']['materials'])->toBe(2)             // plus the file nobody tagged
        ->and($board->build([$a->id, $b->id])['kpis']['materials'])->toBe(2);
});

it('renders for a supply chain user with the new sections', function () {
    scWorkspace(scMaterials(), Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]));
    $user = User::factory()->create()->assignRole(Role::findOrCreate('supply_chain', 'web'));

    $this->withoutVite()->actingAs($user)->get(route('supply_chain.dashboard'))
        ->assertOk()
        ->assertSee('Booking Pending')->assertSee('PI Pending')->assertSee('Payment Pending')
        ->assertSee('In Transit')->assertSee('Arrival Overdue')
        ->assertSee('sc-pipeline', false)->assertSee('sc-chart-data', false)
        ->assertSee('Late Bookings')->assertSee('Open Work by Buyer')
        ->assertViewHas('kpis', fn ($k) => $k['materials'] === 7);
});

it('renders on an empty database', function () {
    $user = User::factory()->create()->assignRole(Role::findOrCreate('supply_chain', 'web'));

    $this->withoutVite()->actingAs($user)->get(route('supply_chain.dashboard'))
        ->assertOk()->assertSee('No order lines are linked yet');
});
