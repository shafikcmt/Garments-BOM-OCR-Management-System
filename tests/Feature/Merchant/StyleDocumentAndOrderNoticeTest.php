<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\StyleDocument;
use App\Models\User;
use App\Services\BomTableSync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/** Style document bank (M-3) and the Store notice when the order changes (M-1). */
beforeEach(function () {
    Storage::fake('local');
    $role = Role::findOrCreate('merchant', 'web');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole($role);
    $this->store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
});

it('adds, downloads and deletes a style document', function () {
    $this->actingAs($this->merchant)->post(route('merchant.documents.store', $this->mother), [
        'document' => UploadedFile::fake()->create('techpack.pdf', 120, 'application/pdf'),
        'category' => 'Tech Pack',
    ])->assertSessionHas('success');

    $doc = StyleDocument::firstOrFail();
    expect($doc->title)->toBe('techpack')->and($doc->category)->toBe('Tech Pack');
    Storage::disk('local')->assertExists($doc->file_path);

    $this->get(route('merchant.documents.download', $doc))->assertOk();
    $this->get(route('merchant.styles.mothers.show', $this->mother))->assertOk()->assertSee('Documents')->assertSee('techpack');

    $this->delete(route('merchant.documents.destroy', $doc))->assertSessionHas('success');
    expect(StyleDocument::count())->toBe(0);
    Storage::disk('local')->assertMissing($doc->file_path);
});

it('rejects a file type that is not allowed and keeps other roles out', function () {
    $this->actingAs($this->merchant)->post(route('merchant.documents.store', $this->mother), [
        'document' => UploadedFile::fake()->create('run.exe', 10),
    ])->assertSessionHasErrors('document');

    $this->actingAs($this->store)->post(route('merchant.documents.store', $this->mother), [
        'document' => UploadedFile::fake()->create('a.pdf', 10),
    ])->assertForbidden();
});

it('tells the Store when a tracked order column changes, once per style', function () {
    $make = fn ($key, $name, $pos, $track = false) => ExcelHeader::create(['header_key' => $key, 'header_name' => $name,
        'owner_role_id' => Role::findByName('merchant', 'web')->id, 'position' => $pos, 'field_type' => 'text', 'value_mode' => 'manual',
        'is_active' => true, 'can_view_all' => true, 'track_order_changes' => $track]);
    $h = [
        'season_name' => $make('season_name', 'Season Name', 1), 'style_name' => $make('style_name', 'Style', 2),
        'gmts_color_name' => $make('gmts_color_name', 'GMTS Color Name', 3), 'contract_number' => $make('contract_number', 'Contract Number', 4),
        'ship' => $make('contract_shipment_date', 'Contract Shipment date', 5, true),
        'material_description' => $make('material_description', 'Material Description', 6),
        'remark' => $make('merchant_remark', 'Merchant Remark', 7),
    ];
    $file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing',
        'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    $rows = collect(['Shell', 'Lining'])->map(function ($m, $i) use ($h, $file) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach (['season_name' => 'SP27', 'style_name' => '748268', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1', 'ship' => '2026-11-05', 'material_description' => $m] as $k => $v) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $h[$k]->id, 'value' => $v]);
        }

        return $row;
    });
    app(BomTableSync::class)->syncFile($file);

    // An untracked column alone sends nothing.
    $this->actingAs($this->merchant)->put(route('uploaded-files.update', $file->id), ['cells' => [$rows[0]->id => [$h['remark']->id => 'call mill']]]);
    expect(AppNotification::where('type', 'order_changed')->count())->toBe(0);

    // A ship date change on two lines: one notice to the Store, none to the merchant.
    $this->put(route('uploaded-files.update', $file->id), ['cells' => [
        $rows[0]->id => [$h['ship']->id => '2026-11-20'],
        $rows[1]->id => [$h['ship']->id => '2026-11-20'],
    ]]);

    $n = AppNotification::where('type', 'order_changed')->get();
    expect($n)->toHaveCount(1)
        ->and($n[0]->user_id)->toBe($this->store->id)
        ->and($n[0]->message)->toContain('748268')->toContain('Contract Shipment date');
});
