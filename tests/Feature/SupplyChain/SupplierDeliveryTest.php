<?php

use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MaterialReceiving;
use App\Models\User;
use App\Services\SupplierDelivery;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/** Supplier delivery follow-up (S3): promised date against what arrived. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 09:00:00');
    $this->sc = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('supply_chain', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->file = ExcelFile::create(['file_name' => 'a.xlsx', 'original_file_name' => 'a.xlsx', 'file_path' => 'a', 'status' => 'processing', 'uploaded_by' => $this->sc->id, 'buyer_id' => $this->buyer->id]);
    $role = Role::findOrCreate('commercial', 'web');
    $this->h = [
        'in' => ExcelHeader::create(['header_name' => 'Committed Inhouse', 'header_key' => 'committed_inhouse', 'owner_role_id' => $role->id, 'position' => 1, 'field_type' => 'date']),
        'eta' => ExcelHeader::create(['header_name' => 'Committed ETA', 'header_key' => 'committed_eta', 'owner_role_id' => $role->id, 'position' => 2, 'field_type' => 'date']),
        'act' => ExcelHeader::create(['header_name' => 'Actual Inhouse', 'header_key' => 'actual_inhouse', 'owner_role_id' => $role->id, 'position' => 3, 'field_type' => 'date']),
    ];
    $this->n = 0;
});
afterEach(fn () => Carbon::setTestNow());

function poLine($test, string $vendor, float $qty, array $cells = [], float $received = 0, ?string $receivedOn = null): BookingPo
{
    $n = ++$test->n;
    $row = ExcelRow::create(['excel_file_id' => $test->file->id, 'row_number' => $n]);
    foreach ($cells as $k => $v) {
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $test->h[$k]->id, 'value' => $v]);
    }
    $po = BookingPo::create(['excel_file_id' => $test->file->id, 'excel_row_id' => $row->id, 'po_no' => 'PO' . $n, 'vendor_name' => $vendor, 'style_name' => '748268', 'item_name' => 'Fabric', 'qty' => $qty, 'uom' => 'Yds', 'status' => 'generated']);
    if ($received > 0) {
        MaterialReceiving::create(['excel_file_id' => $test->file->id, 'excel_row_id' => $row->id, 'grn_no' => 'G' . $n, 'receive_date' => $receivedOn, 'grn_date' => $receivedOn, 'source_type' => MaterialReceiving::SOURCE_BOOKING, 'qty' => $received]);
    }

    return $po;
}

it('classifies every PO line against its promised date and the receipts', function () {
    $overdue = poLine($this, 'Acme Mills', 1000, ['in' => '2026-11-01']);                                          // 9 days overdue
    $open = poLine($this, 'Acme Mills', 500, ['in' => '2026-11-30']);
    $noDate = poLine($this, 'Acme Mills', 200);
    $onTime = poLine($this, 'Beta Trims', 100, ['in' => '2026-11-05'], 100, '2026-11-04');
    $late = poLine($this, 'Beta Trims', 100, ['in' => '2026-11-05'], 99, '2026-11-08');                           // 99% received, 3 days late
    $partial = poLine($this, 'Beta Trims', 100, ['in' => '2026-11-12'], 40, '2026-11-08');                         // not delivered, not due
    $viaEta = poLine($this, 'Beta Trims', 100, ['eta' => '2026-11-02']);                                           // ETA is the fallback promise
    $actual = poLine($this, 'Beta Trims', 100, ['in' => '2026-11-05', 'act' => '2026-11-07']);                     // Actual in-house, 2 days late
    $plain = poLine($this, 'Beta Trims', 100, [], 100, '2026-11-01');                                              // delivered, nothing to judge it against

    $r = app(SupplierDelivery::class)->build(BookingPo::all());
    $by = $r['lines']->keyBy(fn ($l) => $l['po']->id);
    expect($by[$overdue->id])->toMatchArray(['status' => 'overdue', 'days_late' => 9])
        ->and($by[$open->id]['status'])->toBe('open')
        ->and($by[$noDate->id]['status'])->toBe('no_date')
        ->and($by[$onTime->id]['status'])->toBe('on_time')
        ->and($by[$late->id])->toMatchArray(['status' => 'delivered_late', 'days_late' => 3])
        ->and($by[$partial->id])->toMatchArray(['status' => 'open', 'partial' => true])
        ->and($by[$viaEta->id])->toMatchArray(['status' => 'overdue', 'basis' => 'Committed ETA'])
        ->and($by[$actual->id])->toMatchArray(['status' => 'delivered_late', 'days_late' => 2])
        ->and($by[$plain->id]['status'])->toBe('delivered');

    $beta = $r['suppliers']->firstWhere('vendor', 'Beta Trims');
    // Judged: onTime, late, actual => 1 of 3 on time.
    expect($beta)->toMatchArray(['lines' => 6, 'overdue' => 1, 'on_time_rate' => 33, 'avg_days_late' => 2.5])
        ->and($r['suppliers']->first()['vendor'])->toBe('Acme Mills')                  // both have 1 overdue; Acme also has a line with no date
        ->and($r['kpis'])->toMatchArray(['lines' => 9, 'overdue' => 2, 'no_date' => 1, 'open' => 2, 'on_time_rate' => 33, 'suppliers' => 2]);
});

it('lists overdue lines first on the page, filters and searches, and links to the PO', function () {
    $po = poLine($this, 'Acme Mills', 1000, ['in' => '2026-11-01']);
    poLine($this, 'Beta Trims', 100, ['in' => '2026-11-30']);

    $this->actingAs($this->sc)->get(route('supply_chain.supplier_delivery'))->assertOk()->assertSee('Overdue PO lines')->assertSee('Acme Mills')->assertSee('9 day(s) late')
        ->assertSee(route('supply_chain.bookings.show', $po), false);
    $this->get(route('supply_chain.supplier_delivery', ['status' => 'open']))->assertOk()->assertSee('PO2');
    $this->get(route('supply_chain.supplier_delivery', ['status' => 'overdue', 'q' => 'beta']))->assertOk()->assertSee('Nothing here.');
});

it('is read-only for management, scoped to the buyers a user holds, and closed to other roles', function () {
    poLine($this, 'Acme Mills', 1000, ['in' => '2026-11-01']);
    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('supply_chain.supplier_delivery'))->assertOk()->assertSee('Acme Mills');

    $other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $confined = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('supply_chain', 'web'));
    $confined->buyers()->sync([$other->id]);
    $this->actingAs($confined)->get(route('supply_chain.supplier_delivery'))->assertOk()->assertDontSee('Acme Mills');

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('supply_chain.supplier_delivery'))->assertForbidden();
});
