<?php

use App\Models\ActivityLog;
use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\InboundShipment;
use App\Models\InboundShipmentPo;
use App\Models\User;
use App\Services\InboundShipments;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/** Commercial inbound shipments (C1) and the clearance summary (C2). */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 09:00:00');
    $this->commercial = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('commercial', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $this->file = ExcelFile::create(['file_name' => 'a.xlsx', 'original_file_name' => 'a.xlsx', 'file_path' => 'a', 'status' => 'processing', 'uploaded_by' => $this->commercial->id, 'buyer_id' => $this->buyer->id]);
    $this->otherFile = ExcelFile::create(['file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b', 'status' => 'processing', 'uploaded_by' => $this->commercial->id, 'buyer_id' => $this->other->id]);
    $this->row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 1]);
    $this->po = BookingPo::create(['excel_file_id' => $this->file->id, 'excel_row_id' => $this->row->id, 'po_no' => 'HUFA270002', 'vendor_name' => 'Acme Mills', 'style_name' => '748268', 'item_name' => 'Shell fabric', 'qty' => 5000, 'uom' => 'Yds', 'status' => 'generated']);
    $this->otherRow = ExcelRow::create(['excel_file_id' => $this->otherFile->id, 'row_number' => 1]);
    $this->otherPo = BookingPo::create(['excel_file_id' => $this->otherFile->id, 'excel_row_id' => $this->otherRow->id, 'po_no' => 'MAC1', 'vendor_name' => 'Acme Mills', 'style_name' => '1', 'qty' => 10, 'status' => 'generated']);
});
afterEach(fn () => Carbon::setTestNow());

function newShipment($test, array $extra = []): InboundShipment
{
    $test->actingAs($test->commercial)->post(route('commercial.shipments.store'), $extra + ['buyer_id' => $test->buyer->id, 'ship_mode' => 'sea', 'bl_awb_no' => 'BL-77',
        'port_of_discharge' => 'Chattogram', 'etd' => '2026-11-01', 'eta' => '2026-11-20'])->assertSessionHasNoErrors();

    return InboundShipment::latest('id')->firstOrFail();
}

it('records a shipment with a number and works its status out from the dates', function () {
    $s = newShipment($this);
    expect($s->shipment_no)->toMatch('/^SHP-26-\d{4}$/')->and($s->status())->toBe('planned');

    $step = function (array $dates) use ($s) {
        $this->put(route('commercial.shipments.update', $s), ['ship_mode' => 'sea', 'etd' => '2026-11-01', 'eta' => '2026-11-20'] + $dates)->assertSessionHasNoErrors();

        return $s->fresh()->status();
    };
    expect($step(['atd' => '2026-11-02']))->toBe('in_transit')
        ->and($step(['atd' => '2026-11-02', 'ata' => '2026-11-19']))->toBe('arrived')
        ->and($step(['atd' => '2026-11-02', 'ata' => '2026-11-19', 'customs_cleared_date' => '2026-11-22']))->toBe('cleared')
        ->and($step(['atd' => '2026-11-02', 'ata' => '2026-11-19', 'customs_cleared_date' => '2026-11-22', 'actual_inhouse_date' => '2026-11-25']))->toBe('in_house');

    // Late: ETA passed and not arrived.
    $late = newShipment($this, ['eta' => '2026-11-05']);
    expect($late->isLate())->toBeTrue()->and($s->fresh()->isLate())->toBeFalse();
});

it('refuses dates out of order and a missing buyer', function () {
    $s = newShipment($this);
    $base = ['ship_mode' => 'sea'];
    $this->put(route('commercial.shipments.update', $s), $base + ['atd' => '2026-11-10', 'ata' => '2026-11-05'])->assertSessionHasErrors('ata');
    $this->put(route('commercial.shipments.update', $s), $base + ['customs_cleared_date' => '2026-11-10'])->assertSessionHasErrors('ata');
    $this->put(route('commercial.shipments.update', $s), $base + ['ata' => '2026-11-10', 'customs_cleared_date' => '2026-11-09'])->assertSessionHasErrors('customs_cleared_date');
    $this->post(route('commercial.shipments.store'), ['ship_mode' => 'sea'])->assertSessionHasErrors('buyer_id');
});

it('adds only POs of the shipment buyer, once, with a quantity, and removes them', function () {
    $s = newShipment($this);

    $this->get(route('commercial.shipments.show', ['shipment' => $s, 'q' => 'acme']))->assertOk()->assertSee('HUFA270002')->assertDontSee('MAC1');
    $this->post(route('commercial.shipments.pos.add', $s), ['booking_po_id' => $this->otherPo->id])->assertForbidden();

    $this->post(route('commercial.shipments.pos.add', $s), ['booking_po_id' => $this->po->id])->assertSessionHas('success');
    $this->post(route('commercial.shipments.pos.add', $s), ['booking_po_id' => $this->po->id, 'qty' => 99]);
    $link = InboundShipmentPo::firstOrFail();
    expect(InboundShipmentPo::count())->toBe(1)->and((float) $link->qty)->toBe(5000.0);

    $this->get(route('commercial.shipments.show', ['shipment' => $s, 'q' => 'acme']))->assertDontSee('Add</button>');   // already on it

    $this->put(route('commercial.shipments.pos.update', [$s, $link]), ['qty' => 3000])->assertSessionHas('success');
    expect((float) $link->fresh()->qty)->toBe(3000.0);

    $this->delete(route('commercial.shipments.pos.remove', [$s, $link]))->assertSessionHas('success');
    expect(InboundShipmentPo::count())->toBe(0);
});

it('writes the BL / AWB no and the dates into the workspace rows of its POs, once', function () {
    $role = Role::findOrCreate('commercial', 'web');
    $make = fn ($name, $key, $pos) => ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $role->id, 'position' => $pos, 'field_type' => 'text']);
    $bl = $make('BL / AWB No', 'bl_awb_no', 1);
    $eta = $make('Committed ETA', 'committed_eta', 2);
    $make('Actual Inhouse', 'actual_inhouse', 3);

    $s = newShipment($this, ['eta' => '2026-11-20']);
    // Adding the PO carries what the shipment already knows into that PO's workspace row.
    $this->post(route('commercial.shipments.pos.add', $s), ['booking_po_id' => $this->po->id]);
    expect(ExcelCell::where('row_id', $this->row->id)->where('header_id', $bl->id)->value('value'))->toBe('BL-77')
        ->and(ExcelCell::where('row_id', $this->row->id)->where('header_id', $eta->id)->value('value'))->toBe('2026-11-20')
        ->and(ActivityLog::where('row_id', $this->row->id)->count())->toBe(2);

    // Saving the same values again changes nothing.
    $this->put(route('commercial.shipments.update', $s), ['ship_mode' => 'sea', 'bl_awb_no' => 'BL-77', 'eta' => '2026-11-20'])
        ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'workspace cell'));
    expect(ActivityLog::where('row_id', $this->row->id)->count())->toBe(2);

    // A changed value is written once and logged; the actual in-house date fills its own column.
    $this->put(route('commercial.shipments.update', $s), ['ship_mode' => 'sea', 'bl_awb_no' => 'BL-78', 'eta' => '2026-11-20', 'ata' => '2026-11-19', 'actual_inhouse_date' => '2026-11-25'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2 workspace cell'));
    expect(ExcelCell::where('row_id', $this->row->id)->where('header_id', $bl->id)->value('value'))->toBe('BL-78')
        ->and(ActivityLog::where('row_id', $this->row->id)->count())->toBe(4);
});

it('builds the clearance summary: ports, expected weeks, late, waiting at the port, cleared not delivered', function () {
    $mk = function (array $a) {
        $s = InboundShipment::create($a + ['buyer_id' => $this->buyer->id, 'ship_mode' => 'sea']);
        app(InboundShipments::class)->assignNumber($s);

        return $s->fresh();
    };
    $late = $mk(['eta' => '2026-11-05', 'port_of_discharge' => 'Chattogram']);                                       // ETA passed
    $mk(['eta' => '2026-11-18', 'etd' => '2026-11-01', 'atd' => '2026-11-02', 'port_of_discharge' => 'Chattogram']); // in transit
    $waiting = $mk(['atd' => '2026-11-01', 'ata' => '2026-11-04', 'port_of_discharge' => 'Dhaka']);                  // arrived 6 days ago
    $fresh = $mk(['atd' => '2026-11-01', 'ata' => '2026-11-09', 'port_of_discharge' => 'Dhaka']);                    // arrived yesterday: not "waiting" yet
    $cleared = $mk(['atd' => '2026-11-01', 'ata' => '2026-11-04', 'customs_cleared_date' => '2026-11-08', 'port_of_discharge' => 'Dhaka']);
    $done = $mk(['atd' => '2026-11-01', 'ata' => '2026-11-04', 'actual_inhouse_date' => '2026-11-09', 'port_of_discharge' => 'Dhaka']);

    $r = app(InboundShipments::class)->clearance(InboundShipment::all());
    expect($r['counts'])->toBe(['planned' => 1, 'in_transit' => 1, 'arrived' => 2, 'cleared' => 1, 'in_house' => 1])
        ->and($r['late']->pluck('id')->all())->toBe([$late->id])
        ->and($r['waiting']->pluck('id')->all())->toBe([$waiting->id])
        ->and($r['to_deliver']->pluck('id')->all())->toBe([$cleared->id])
        ->and($r['ports'])->toBe(['Dhaka' => 3, 'Chattogram' => 2])           // the in-house one is not counted
        ->and($r['weeks'])->toBe(['2026-W45' => 1, '2026-W47' => 1]);          // 5 Nov and 18 Nov

    $this->actingAs($this->commercial)->get(route('commercial.shipments.clearance'))->assertOk()->assertSee('ETA passed, not arrived')->assertSee('Chattogram');
    $this->get(route('commercial.shipments.index', ['status' => 'late']))->assertOk()->assertSee($late->shipment_no);
});

it('lets management read but not change, keeps a confined user and other roles out', function () {
    $s = newShipment($this);

    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('commercial.shipments.show', $s))->assertOk();
    $this->put(route('commercial.shipments.update', $s), ['ship_mode' => 'air'])->assertForbidden();
    $this->get(route('commercial.shipments.create'))->assertForbidden();

    $confined = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('commercial', 'web'));
    $confined->buyers()->sync([$this->other->id]);
    $this->actingAs($confined)->get(route('commercial.shipments.show', $s))->assertForbidden();
    $this->get(route('commercial.shipments.index'))->assertOk()->assertDontSee('BL-77');

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('commercial.shipments.index'))->assertForbidden();
});
