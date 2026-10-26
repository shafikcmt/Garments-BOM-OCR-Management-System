<?php

use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\BomTableSync;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

/**
 * OCR summaries (Contract, Material, Payment, Clearance) and the OCR workbook,
 * built from workspace cells found by header name.
 */
beforeEach(fn () => Carbon::setTestNow('2026-10-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function ocrHeaders(): array
{
    $role = Role::findOrCreate('merchant', 'web');
    $names = [
        'season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name',
        'contract_number' => 'Contract Number', 'contract_shipment_date' => 'Contract Shipment date',
        'customer_contract_quantity' => 'Customer Contract Quantity', 'material_description' => 'Material Description',
        'bom_quantity' => 'BOM Quantity', 'material_po_number' => 'Material PO Number', 'vendor_name' => 'Vendor Name',
        'materials_ordered' => 'Materials Ordered', 'pi_no' => 'PI No', 'pi_status' => 'PI Status', 'pi_amount' => 'PI Amount',
        'payment_status' => 'Payment Status', 'payment_reqd_date' => "Payment Req'd Date", 'bl_awb_no' => 'BL / AWB No',
        'eta' => 'ETA', 'ship_mode' => 'Ship Mode', 'receipt_qty' => 'Receipt Qty',
    ];
    $headers = [];
    $pos = 1;
    foreach ($names as $key => $name) {
        $headers[$key] = ExcelHeader::firstOrCreate(['header_key' => $key], [
            'header_name' => $name, 'owner_role_id' => $role->id, 'position' => $pos++, 'is_active' => true, 'can_view_all' => true,
        ]);
    }

    return $headers;
}

function ocrFile(Buyer $buyer, array $rows): ExcelFile
{
    $headers = ocrHeaders();
    $file = ExcelFile::create([
        'file_name' => 'o.xlsx', 'original_file_name' => 'o.xlsx', 'file_path' => 'o',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id,
    ]);
    foreach ($rows as $i => $values) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach ($values + ['season_name' => 'SP27', 'style_name' => '748268', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1',
            'contract_shipment_date' => '2026-11-05', 'customer_contract_quantity' => '1000', 'bom_quantity' => '100'] as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $headers[$key]->id, 'value' => (string) $value]);
        }
    }
    app(BomTableSync::class)->syncFile($file);

    return $file;
}

function ocrMerchant(): User
{
    return User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
}

beforeEach(function () {
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    ocrFile($this->buyer, [
        // Same PI on two lines: counted once (5,000), paid.
        ['material_description' => 'Shell', 'material_po_number' => 'PO1', 'vendor_name' => 'Mill A', 'materials_ordered' => '110',
            'pi_no' => 'PI-1', 'pi_status' => 'PI Received', 'pi_amount' => '5,000', 'payment_status' => 'Pmt Done', 'receipt_qty' => '110'],
        ['material_description' => 'Lining', 'material_po_number' => 'PO1', 'vendor_name' => 'Mill A', 'materials_ordered' => '90',
            'pi_no' => 'PI-1', 'pi_status' => 'PI Received', 'pi_amount' => '5000', 'payment_status' => 'Pmt Done',
            'bl_awb_no' => 'BL-77', 'eta' => '2026-10-28', 'ship_mode' => 'Sea'],
        // Unpaid PI, due date past.
        ['material_description' => 'Zipper', 'material_po_number' => 'PO2', 'vendor_name' => 'Trims B', 'materials_ordered' => '100',
            'pi_no' => 'PI-2', 'pi_status' => 'PI Received', 'pi_amount' => '800', 'payment_status' => 'Pmt Pending', 'payment_reqd_date' => '2026-10-15'],
        ['material_description' => 'Label'],
    ]);
});

it('builds the four OCR summaries from the workspace', function () {
    $response = $this->actingAs(ocrMerchant())->get(route('merchant.ocr.index', ['tab' => 'payment']))->assertOk();

    $contract = $response->viewData('contract');
    expect($contract)->toHaveCount(1)
        ->and($contract[0])->toMatchArray(['style' => '748268', 'contract' => 'C1', 'materials' => 4, 'ordered_pct' => 75, 'paid_pct' => 50, 'received_pct' => 25]);

    $shell = $response->viewData('material')->firstWhere('description', 'Shell');
    expect($shell)->toMatchArray(['bom_qty' => 100.0, 'ordered_qty' => 110.0, 'short_excess' => 10.0, 'received_qty' => 110.0]);

    $payment = $response->viewData('payment')->keyBy('vendor');
    expect($payment['Mill A'])->toMatchArray(['pis' => 1, 'pi_amount' => 5000.0, 'paid_amount' => 5000.0, 'pending_amount' => 0.0])
        ->and($payment['Trims B'])->toMatchArray(['pi_amount' => 800.0, 'pending_amount' => 800.0])
        ->and($payment['Trims B']['next_due']->toDateString())->toBe('2026-10-15');

    $clearance = $response->viewData('clearance')->keyBy('bl');
    expect($clearance['BL-77'])->toMatchArray(['ship_mode' => 'Sea', 'lines' => 1])
        ->and($clearance['BL-77']['eta']->toDateString())->toBe('2026-10-28')
        ->and($clearance['No BL / AWB yet']['lines'])->toBe(1);

    $response->assertSee('Payment Summary')->assertSee('Mill A')->assertSee('Download OCR Excel');
});

it('keeps a buyer-confined merchant to their buyers', function () {
    $other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $merchant = ocrMerchant();
    if (! method_exists($merchant, 'buyers')) {
        $this->markTestSkipped('No buyer assignment on users.');
    }
    $merchant->buyers()->sync([$other->id]);

    $this->actingAs($merchant)->get(route('merchant.ocr.index'))
        ->assertOk()->assertViewHas('contract', fn ($c) => $c->isEmpty());
});

it('downloads the OCR workbook with the Database sheet in the buyer layout', function () {
    Excel::fake();
    $profile = BuyerImportProfile::create(['buyer_id' => $this->buyer->id, 'name' => 'Ann Taylor']);
    $profile->columns()->create(['source_header' => 'Style No', 'source_key' => 'style_no', 'excel_header_id' => ExcelHeader::where('header_key', 'style_name')->value('id')]);

    $this->actingAs(ocrMerchant())->get(route('merchant.ocr.excel', ['buyer' => $this->buyer->id]))->assertOk();

    Excel::assertDownloaded('OCR-ann-taylor-' . now()->format('Ymd') . '.xlsx', function ($export) {
        $sheets = $export->sheets();
        $db = $sheets[0];

        return count($sheets) === 5
            && $db->title() === 'Database'
            && $db->headings()[0] === 'Style No'
            && count($db->array()) === 4
            && $sheets[3]->title() === 'Payment Summary';
    });
});
