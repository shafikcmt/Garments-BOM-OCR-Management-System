<?php

use App\Models\Buyer;
use App\Models\BusinessUnit;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierItemCategory;
use App\Models\User;
use App\Services\VendorMaster;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Vendor Master: workflow, usability rules, bank approval, audit, import, PO integration. */
function vmUser(array $permissions = [], string $role = 'supply_chain'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['status' => 1])->assignRole($role);
    foreach ($permissions as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function vmVendor(array $over = []): Supplier
{
    return Supplier::create($over + ['supplier_name' => 'IDEAL FASTENER', 'country' => 'Bangladesh', 'vendor_type' => 'local',
        'approval_status' => 'approved', 'vendor_status' => 'active']);
}

/* ------------------------------------------------------------ workflow */

it('walks a vendor Draft -> Submitted -> Under Review -> Approved and activates it with a generated code', function () {
    $maker = vmUser(['vendors.edit']);
    $approver = vmUser(['vendors.approve']);
    $this->actingAs($maker);

    $vendor = app(VendorMaster::class)->create(['supplier_name' => 'NEW TRIMS', 'country' => 'China', 'vendor_type' => 'foreign']);
    expect($vendor->approval_status)->toBe('draft')->and($vendor->vendor_status)->toBe('inactive')->and($vendor->unusableReason())->not->toBeNull();

    app(VendorMaster::class)->submit($vendor);
    expect($vendor->fresh()->approval_status)->toBe('submitted');

    $this->actingAs($approver);
    app(VendorMaster::class)->startReview($vendor->fresh());
    app(VendorMaster::class)->approve($vendor->fresh(), 'Looks fine');

    $vendor->refresh();
    expect($vendor->approval_status)->toBe('approved')->and($vendor->vendor_status)->toBe('active')->and($vendor->is_active)->toBeTrue()
        ->and($vendor->supplier_code)->toStartWith('VND-')->and($vendor->approved_by)->toBe($approver->id)->and($vendor->unusableReason())->toBeNull();

    expect($vendor->auditLogs()->pluck('action')->all())->toContain('created', 'submitted', 'review_started', 'approved');
});

it('will not submit an incomplete vendor, nor approve without the permission, nor reject without a reason', function () {
    $this->actingAs(vmUser(['vendors.edit']));
    $vendor = Supplier::create(['supplier_name' => 'BARE', 'approval_status' => 'draft']);
    expect(fn () => app(VendorMaster::class)->submit($vendor))->toThrow(ValidationException::class);

    $vendor->update(['country' => 'India']);
    app(VendorMaster::class)->submit($vendor->fresh());

    expect(fn () => app(VendorMaster::class)->approve($vendor->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->actingAs(vmUser(['vendors.approve']));
    expect(fn () => app(VendorMaster::class)->reject($vendor->fresh(), ''))->toThrow(ValidationException::class);
    app(VendorMaster::class)->reject($vendor->fresh(), 'Missing trade licence');
    expect($vendor->fresh()->approval_status)->toBe('rejected')->and($vendor->fresh()->status_reason)->toBe('Missing trade licence');
});

it('blocks, inactivates and reactivates with a reason, and keeps is_active in step', function () {
    $this->actingAs(vmUser(['vendors.block']));
    $vendor = vmVendor();

    expect(fn () => app(VendorMaster::class)->block($vendor, ''))->toThrow(ValidationException::class);
    app(VendorMaster::class)->block($vendor, 'Quality dispute');
    expect($vendor->fresh()->vendor_status)->toBe('blocked')->and($vendor->fresh()->is_active)->toBeFalse();

    app(VendorMaster::class)->reactivate($vendor->fresh(), 'Dispute settled');
    expect($vendor->fresh()->vendor_status)->toBe('active')->and($vendor->fresh()->is_active)->toBeTrue();

    $vendor->refresh()->update(['is_active' => false]);            // old code path still works
    expect($vendor->fresh()->vendor_status)->toBe('inactive');
});

it('expires vendors past their expiry date and writes the audit row', function () {
    $vendor = vmVendor(['expiry_date' => now()->subDay()->toDateString()]);

    expect($vendor->isExpired())->toBeTrue()->and(app(VendorMaster::class)->expireOverdue())->toBe(1);
    expect($vendor->fresh()->vendor_status)->toBe('inactive')->and($vendor->auditLogs()->where('action', 'expired')->exists())->toBeTrue();
});

/* ------------------------------------------------------ usability rules */

it('offers only approved, active, in-date vendors that apply to the business unit', function () {
    vmVendor(['supplier_name' => 'OK']);
    vmVendor(['supplier_name' => 'DRAFT', 'approval_status' => 'draft']);
    vmVendor(['supplier_name' => 'BLOCKED', 'vendor_status' => 'blocked']);
    vmVendor(['supplier_name' => 'INACTIVE', 'vendor_status' => 'inactive']);
    vmVendor(['supplier_name' => 'EXPIRED', 'expiry_date' => now()->subDay()->toDateString()]);
    vmVendor(['supplier_name' => 'FUTURE', 'effective_date' => now()->addDays(5)->toDateString()]);

    expect(Supplier::selectable()->pluck('supplier_name')->all())->toBe(['OK']);

    $bu1 = BusinessUnit::create(['code' => 'A', 'name' => 'Unit A']);
    $bu2 = BusinessUnit::create(['code' => 'B', 'name' => 'Unit B']);
    $onlyA = vmVendor(['supplier_name' => 'ONLY A']);
    $onlyA->businessUnits()->create(['business_unit_id' => $bu1->id, 'status' => 'active']);

    expect(Supplier::selectable($bu1->id)->pluck('supplier_name')->sort()->values()->all())->toBe(['OK', 'ONLY A'])
        ->and(Supplier::selectable($bu2->id)->pluck('supplier_name')->all())->toBe(['OK'])      // no BU rows = every unit
        ->and($onlyA->unusableReason($bu2->id))->toContain('not applicable');
});

it('lets item category terms beat vendor defaults and keeps incoterm / ship mode as defaults', function () {
    $v = vmVendor(['incoterm' => 'FOB', 'ship_mode' => 'SEA', 'tolerance_percent' => 3, 'lead_time_days' => 30, 'moq' => 1000, 'default_currency' => 'USD']);
    SupplierItemCategory::create(['supplier_id' => $v->id, 'category_name' => 'Zipper', 'item_type' => 'accessory', 'lead_time_days' => 14, 'moq' => 200, 'tolerance_percent' => 5]);

    $zip = app(VendorMaster::class)->termsFor($v, 'zipper');
    $other = app(VendorMaster::class)->termsFor($v, 'Thread');

    expect($zip)->toMatchArray(['lead_time_days' => 14, 'moq' => 200.0, 'tolerance_percent' => 5.0, 'incoterm' => 'FOB', 'ship_mode' => 'SEA', 'source' => 'category'])
        ->and($other)->toMatchArray(['lead_time_days' => 30, 'moq' => 1000.0, 'tolerance_percent' => 3.0, 'source' => 'vendor']);
});

/* --------------------------------------------------------------- audit */

it('audits each changed field, and holds a controlled field on an approved vendor for approval', function () {
    $vendor = vmVendor(['legal_name' => 'Ideal Ltd', 'incoterm' => 'FOB']);
    $maker = vmUser(['vendors.edit']);
    $this->actingAs($maker);

    $held = app(VendorMaster::class)->update($vendor, ['incoterm' => 'CIF', 'legal_name' => 'Ideal Limited']);

    expect($held)->toBe(['legal_name'])
        ->and($vendor->fresh()->incoterm)->toBe('CIF')                 // ordinary field: applied at once
        ->and($vendor->fresh()->legal_name)->toBe('Ideal Ltd');        // controlled field: waits
    $pending = $vendor->auditLogs()->where('action', 'modification_requested')->first();
    expect($pending->approval_status)->toBe('pending')->and($pending->new_value)->toBe('Ideal Limited');
    expect($vendor->auditLogs()->where('action', 'updated')->where('field', 'incoterm')->first())->toMatchArray(['old_value' => 'FOB', 'new_value' => 'CIF', 'changed_by' => $maker->id]);

    $this->actingAs(vmUser(['vendors.approve']));
    app(VendorMaster::class)->decideModification($pending, true);
    expect($vendor->fresh()->legal_name)->toBe('Ideal Limited')->and($pending->fresh()->approval_status)->toBe('approved');
});

/* ---------------------------------------------------------- bank accounts */

function vmBankPayload(array $over = []): array
{
    return $over + ['bank_name' => 'Dutch-Bangla Bank', 'bank_branch' => 'Motijheel', 'account_name' => 'Ideal Fastener', 'account_number' => '1234567890', 'swift_code' => 'DBBLBDDH', 'is_primary' => true];
}

it('needs a second person to approve a bank account, and never stores or logs the full number in clear', function () {
    $vendor = vmVendor();
    $maker = vmUser(['vendors.bank.manage', 'vendors.bank.approve']);
    $checker = vmUser(['vendors.bank.approve']);

    $this->actingAs($maker);
    $request = app(VendorMaster::class)->requestBankChange($vendor, 'create', vmBankPayload());
    expect(SupplierBankAccount::count())->toBe(0)->and($request->summary)->toContain('****7890')->and($request->summary)->not->toContain('1234567890');

    // The requester cannot approve their own change.
    expect(fn () => app(VendorMaster::class)->decideBankChange($request, true))->toThrow(ValidationException::class);

    $this->actingAs($checker);
    app(VendorMaster::class)->decideBankChange($request->fresh(), true, 'Verified with bank letter');

    $account = SupplierBankAccount::firstOrFail();
    expect($account->account_number)->toBe('1234567890')->and($account->is_primary)->toBeTrue()->and($account->verification_status)->toBe('approved')
        ->and($account->maskedNumber())->toBe('****7890')->and($account->approved_by)->toBe($checker->id);

    // At rest: encrypted. In JSON: hidden. In the audit trail: masked.
    expect(\Illuminate\Support\Facades\DB::table('supplier_bank_accounts')->value('account_number'))->not->toContain('1234567890')
        ->and(json_encode($account->toArray()))->not->toContain('1234567890')
        ->and(json_encode(\App\Models\SupplierAuditLog::all()->toArray()))->not->toContain('1234567890');
    expect($vendor->auditLogs()->where('entity', 'bank_account')->pluck('action')->all())->toContain('bank_change_requested', 'bank_change_approved');
});

it('refuses bank requests without permission, a rejected change without a reason, and a duplicate account number', function () {
    $vendor = vmVendor();
    $this->actingAs(vmUser(['vendors.edit']));
    expect(fn () => app(VendorMaster::class)->requestBankChange($vendor, 'create', vmBankPayload()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    $maker = vmUser(['vendors.bank.manage']);
    $checker = vmUser(['vendors.bank.approve']);
    $this->actingAs($maker);
    $r = app(VendorMaster::class)->requestBankChange($vendor, 'create', vmBankPayload());

    $this->actingAs($checker);
    expect(fn () => app(VendorMaster::class)->decideBankChange($r, false, ''))->toThrow(ValidationException::class);
    app(VendorMaster::class)->decideBankChange($r->fresh(), true);

    $this->actingAs($maker);
    expect(fn () => app(VendorMaster::class)->requestBankChange(vmVendor(['supplier_name' => 'OTHER']), 'create', vmBankPayload()))->toThrow(ValidationException::class);
});

it('keeps one primary bank account, and deactivating needs approval too', function () {
    $vendor = vmVendor();
    $maker = vmUser(['vendors.bank.manage']);
    $checker = vmUser(['vendors.bank.approve']);
    $svc = app(VendorMaster::class);

    foreach (['1111111111', '2222222222'] as $n) {
        $this->actingAs($maker);
        $r = $svc->requestBankChange($vendor, 'create', vmBankPayload(['account_number' => $n, 'is_primary' => $n === '2222222222']));
        $this->actingAs($checker);
        $svc->decideBankChange($r, true);
    }

    expect($vendor->bankAccounts()->where('is_primary', true)->count())->toBe(1)
        ->and($vendor->bankAccounts()->where('is_primary', true)->first()->account_number_last4)->toBe('2222');

    $this->actingAs($maker);
    $acc = $vendor->bankAccounts()->where('account_number_last4', '1111')->first();
    $d = $svc->requestBankChange($vendor, 'deactivate', [], $acc);
    expect($acc->fresh()->status)->toBe('active');
    $this->actingAs($checker);
    $svc->decideBankChange($d, true);
    expect($acc->fresh()->status)->toBe('inactive');
});

it('hides bank accounts and bank audit rows from users without bank permission', function () {
    $vendor = vmVendor();
    $maker = vmUser(['vendors.bank.manage']);
    $this->actingAs($maker);
    app(VendorMaster::class)->requestBankChange($vendor, 'create', vmBankPayload());

    $plain = vmUser(['vendors.view']);
    $this->withoutVite()->actingAs($plain)->get(route('vendors.show', $vendor))->assertOk()->assertDontSee('Bank accounts')->assertDontSee('bank change requested')->assertDontSee('7890');

    $this->withoutVite()->actingAs($maker)->get(route('vendors.show', $vendor))->assertOk()->assertSee('Bank accounts')->assertSee('****7890')->assertDontSee('1234567890');
});

/* ------------------------------------------------------------ profile UI */

it('keeps the vendor profile to people who may see vendors, and adds contacts with one primary', function () {
    $vendor = vmVendor();
    $this->actingAs(User::factory()->create())->get(route('vendors.show', $vendor))->assertForbidden();

    $editor = vmUser(['vendors.edit']);
    foreach ([['Rahim', null], ['Karim', '1']] as [$name, $primary]) {
        $this->actingAs($editor)->post(route('vendors.contacts.store', $vendor), ['name' => $name, 'contact_type' => 'sales', 'is_primary' => $primary])->assertRedirect();
    }

    expect($vendor->contacts()->count())->toBe(2)->and($vendor->contacts()->where('is_primary', true)->count())->toBe(1)
        ->and($vendor->contacts()->first()->name)->toBe('Karim')
        ->and($vendor->fresh()->contact_person)->toBe('Karim');       // old column follows the primary contact
    $this->withoutVite()->actingAs($editor)->get(route('vendors.show', $vendor))->assertOk()->assertSee('Karim')->assertSee('Audit trail');
});

it('runs the workflow from the profile buttons', function () {
    $vendor = Supplier::create(['supplier_name' => 'BTN', 'country' => 'India']);
    $u = vmUser(['vendors.edit', 'vendors.approve']);

    $this->actingAs($u)->post(route('vendors.workflow', [$vendor, 'submit']))->assertRedirect();
    $this->actingAs($u)->post(route('vendors.workflow', [$vendor, 'approve']), ['remarks' => 'ok'])->assertRedirect();
    expect($vendor->fresh()->approval_status)->toBe('approved');

    $this->actingAs(vmUser(['vendors.view']))->post(route('vendors.workflow', [$vendor, 'block']), ['remarks' => 'x'])->assertForbidden();
});

/* --------------------------------------------------------------- import */

function vmWorkbook(array $sheets): \Illuminate\Http\UploadedFile
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->removeSheetByIndex(0);
    foreach ($sheets as $title => $rows) {
        $sheet = $book->createSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($rows, null, 'A1');
    }
    $path = tempnam(sys_get_temp_dir(), 'vw') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

    return new \Illuminate\Http\UploadedFile($path, 'vendors.xlsx', null, null, true);
}

function vmSheets(array $over = []): array
{
    $h = fn ($k) => \App\Imports\VendorWorkbookImport::SHEETS[$k][1];

    return $over + [
        'Vendors' => [$h('vendors'), ['ABC TRADING', 'ABC-1', 'ABC Trading Ltd', 'Foreign', 'Trims', '', '', '', 'China', 'USD', '30 days', 'FOB', 'SEA', '3', '21', '500', '2026-01-01', '2027-12-31', 'E1', '']],
        'Contacts' => [$h('contacts'), ['ABC TRADING', 'Li Wei', 'Sales', 'li@example.com', '123', 'Sales', 'Y'], ['ABC TRADING', 'Zhang', '', '', '', '', '']],
        'Addresses' => [$h('addresses'), ['ABC TRADING', 'Factory', '88 Road', 'Guangzhou', '', '', 'China', 'Y']],
        'Item Categories' => [$h('categories'), ['ABC TRADING', 'Zipper', 'Accessory', '14', '1000', '3'], ['ABC TRADING', 'Button', 'Accessory', '', '', '']],
        'Bank Accounts' => [$h('banks'), ['ABC TRADING', 'HSBC', 'HK', 'ABC Trading', '99887766', '', 'HSBCHKHH', '', 'USD', 'Y']],
    ];
}

it('imports a vendor with several contacts, addresses, categories and a bank request, and gives a summary', function () {
    $user = vmUser(['vendors.edit', 'vendors.bank.manage'], 'merchant');

    $this->actingAs($user)->post(route('merchant.vendors.import'), ['file' => vmWorkbook(vmSheets())])
        ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, '1 vendor(s)') && str_contains($m, '2 contact(s)') && str_contains($m, '1 bank request(s)'));

    $v = Supplier::where('supplier_code', 'ABC-1')->firstOrFail();
    expect(Supplier::count())->toBe(1)                              // one row in the master, not one per contact
        ->and($v->approval_status)->toBe('draft')->and($v->vendor_type)->toBe('foreign')->and($v->contacts)->toHaveCount(2)
        ->and($v->contacts->where('is_primary', true))->toHaveCount(1)->and($v->contacts->first()->name)->toBe('Li Wei')
        ->and($v->itemCategories)->toHaveCount(2)->and($v->lead_time_days)->toBe(21)->and($v->expiry_date->toDateString())->toBe('2027-12-31')
        ->and($v->bankAccounts()->count())->toBe(0)                 // only a pending request
        ->and($v->bankChangeRequests()->where('status', 'pending')->count())->toBe(1);
});

it('skips bank rows for a user without bank permission and still imports the rest', function () {
    $this->actingAs(vmUser(['vendors.edit'], 'merchant'))->post(route('merchant.vendors.import'), ['file' => vmWorkbook(vmSheets())])
        ->assertSessionHas('success')->assertSessionHas('import_skipped', fn ($s) => str_contains(implode(' ', $s), 'Bank account rows were not imported'));

    expect(Supplier::count())->toBe(1)->and(\App\Models\SupplierBankChangeRequest::count())->toBe(0);
});

it('validates the whole workbook and imports nothing when a row is wrong', function () {
    $sheets = vmSheets();
    $sheets['Vendors'][] = ['ABC-DUP-CODE', 'ABC-1', '', 'Sideways', '', '', '', '', '', '', '', '', '', '250', '', '', '2027-12-31', '2026-01-01', '', ''];
    $sheets['Contacts'][] = ['NOT IN FILE', 'Someone', '', 'bad-email', '', '', ''];

    $this->actingAs(vmUser(['vendors.edit', 'vendors.bank.manage'], 'merchant'))->post(route('merchant.vendors.import'), ['file' => vmWorkbook($sheets)])
        ->assertSessionHas('import_errors', function ($e) {
            $text = implode(' | ', $e);

            return str_contains($text, 'already used') && str_contains($text, 'Local or Foreign') && str_contains($text, 'Tolerance')
                && str_contains($text, 'Expiry Date is before') && str_contains($text, 'not on the Vendors sheet');
        });

    expect(Supplier::count())->toBe(0);
});

it('does not create a duplicate of a vendor already in the master', function () {
    vmVendor(['supplier_name' => 'ABC Trading', 'supplier_code' => 'OLD-1']);

    $this->actingAs(vmUser(['vendors.edit'], 'merchant'))->post(route('merchant.vendors.import'), ['file' => vmWorkbook(vmSheets())])
        ->assertSessionHas('import_skipped', fn ($s) => str_contains(implode(' ', $s), 'already in the vendor list'));

    expect(Supplier::count())->toBe(1);
});

it('offers a template workbook with every sheet, and exports banks masked only with permission', function () {
    $merchant = vmUser(['vendors.edit'], 'merchant');
    $res = $this->actingAs($merchant)->get(route('merchant.vendors.template'))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
    file_put_contents($path, $res->streamedContent());
    expect(\PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheetNames())->toBe(['Vendors', 'Contacts', 'Addresses', 'Item Categories', 'Bank Accounts', 'Instructions']);

    $vendor = vmVendor();
    $acc = SupplierBankAccount::create(['supplier_id' => $vendor->id, 'bank_name' => 'HSBC', 'account_name' => 'X', 'account_number' => '5555666677', 'account_number_last4' => '6677', 'status' => 'active', 'verification_status' => 'approved']);
    $viewer = vmUser(['vendors.bank.view'], 'merchant');
    $res = $this->actingAs($viewer)->get(route('merchant.vendors.export'))->assertOk();
    file_put_contents($path, $res->streamedContent());
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    expect($book->getSheetNames())->toContain('Bank Accounts')
        ->and($book->getSheetByName('Bank Accounts')->toArray()[1][4])->toBe('****6677')
        ->and(json_encode($book->getSheetByName('Bank Accounts')->toArray()))->not->toContain('5555666677');
});

/* -------------------------------------------------- PO / PI integration */

function vmPendingRow(string $vendorName): ExcelRow
{
    $owner = Role::findOrCreate('supply_chain', 'web');
    $buyer = Buyer::firstOrCreate(['buyer_name' => 'ANNTAYLOR'], ['is_active' => true]);
    $file = ExcelFile::create(['file_name' => 'x.xlsx', 'original_file_name' => 'x.xlsx', 'file_path' => 'x', 'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer->id, 'total_rows' => 0]);

    $h = [];
    $pos = 1;
    foreach (['buyer_name' => 'Buyer Name', 'season_name' => 'Season Name', 'contract_shipment_date' => 'Contract Shipment Date', 'material_type' => 'Material Type',
        'material_description' => 'Material Description', 'sap_code' => 'SAP Code', 'vendor_name' => 'Vendor Name', 'materials_ordered' => 'Materials to be Ordered'] as $key => $name) {
        $h[$key] = ExcelHeader::firstOrCreate(['header_key' => $key], ['header_name' => $name, 'owner_role_id' => $owner->id, 'position' => $pos++, 'field_type' => 'text', 'is_active' => true]);
    }

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
    foreach (['buyer_name' => 'ANNTAYLOR', 'season_name' => 'SU27', 'contract_shipment_date' => '2027-01-25', 'material_description' => 'ZIPPER', 'sap_code' => 'S1', 'vendor_name' => $vendorName, 'materials_ordered' => '5'] as $k => $v) {
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $h[$k]->id, 'value' => $v]);
    }

    return $row;
}

it('refuses a new PO for a blocked, unapproved or expired vendor, and says why', function () {
    $user = vmUser([], 'supply_chain');

    foreach ([['BLOCKED CO', ['vendor_status' => 'blocked'], 'blocked'], ['DRAFT CO', ['approval_status' => 'draft'], 'not approved'], ['OLD CO', ['expiry_date' => now()->subDay()->toDateString()], 'expired']] as [$name, $state, $word]) {
        vmVendor(['supplier_name' => $name] + $state);
        $row = vmPendingRow($name);

        $this->actingAs($user)->postJson(route('supply_chain.bookings.generate', $row))
            ->assertStatus(422)->assertJsonPath('errors.vendor_name.0', fn ($m) => str_contains($m, $word));
        expect(\App\Models\BookingPo::count())->toBe(0);
    }
});

it('generates a PO for an approved vendor with a frozen snapshot that later vendor edits do not change', function () {
    $vendor = vmVendor(['supplier_name' => 'GOOD CO', 'incoterm' => 'FOB', 'ship_mode' => 'SEA', 'tolerance_percent' => 3, 'default_payment_terms' => '30 days', 'default_currency' => 'USD']);
    $row = vmPendingRow('GOOD CO');

    $this->actingAs(vmUser([], 'supply_chain'))->postJson(route('supply_chain.bookings.generate', $row))->assertOk();

    $po = \App\Models\BookingPo::firstOrFail();
    expect($po->supplier_id)->toBe($vendor->id)->and($po->incoterm)->toBe('FOB')->and($po->ship_mode)->toBe('SEA')->and((float) $po->tolerance_percent)->toBe(3.0)
        ->and($po->vendor_snapshot['vendor_name'])->toBe('GOOD CO')->and($po->vendor_snapshot['payment_terms'])->toBe('30 days')
        ->and(json_encode($po->vendor_snapshot))->not->toContain('account');

    $vendor->update(['incoterm' => 'CIF', 'supplier_name' => 'GOOD CO RENAMED', 'default_payment_terms' => '90 days']);
    $po->refresh();
    expect($po->incoterm)->toBe('FOB')->and($po->vendor_snapshot['vendor_name'])->toBe('GOOD CO')->and($po->vendor_snapshot['payment_terms'])->toBe('30 days');
});

it('still allows a PO for a vendor that is not in the master at all (free-text vendors keep working)', function () {
    $row = vmPendingRow('UNLISTED CO');
    $this->actingAs(vmUser([], 'supply_chain'))->postJson(route('supply_chain.bookings.generate', $row))->assertOk();
    expect(\App\Models\BookingPo::firstOrFail()->supplier_id)->toBeNull();
});

/* ----------------------------------------------- PI / receiving link */

it('links a payment request or receiving to the vendor id by exact name, and never guesses on duplicates', function () {
    $vendor = vmVendor(['supplier_name' => 'IDEAL FASTENER']);

    $pr = \App\Models\PaymentRequest::create(['request_no' => 'PR-T1', 'supplier_name' => 'ideal fastener', 'status' => 'draft']);
    $other = \App\Models\PaymentRequest::create(['request_no' => 'PR-T2', 'supplier_name' => 'IDEAL FASTENER LTD', 'status' => 'draft']);

    expect($pr->supplier_id)->toBe($vendor->id)->and($pr->supplier_name)->toBe('ideal fastener')   // text stays as entered
        ->and($other->supplier_id)->toBeNull();

    vmVendor(['supplier_name' => 'TWIN', 'supplier_code' => 'T1']);
    vmVendor(['supplier_name' => 'TWIN', 'supplier_code' => 'T2']);
    expect(\App\Models\PaymentRequest::create(['request_no' => 'PR-T3', 'supplier_name' => 'TWIN', 'status' => 'draft'])->supplier_id)->toBeNull();

    // Renaming the vendor later does not touch the old transaction.
    $vendor->update(['supplier_name' => 'IDEAL FASTENER GROUP']);
    expect($pr->fresh()->supplier_name)->toBe('ideal fastener')->and($pr->fresh()->supplier_id)->toBe($vendor->id);
});

it('shows Open and the workflow on the Admin vendor list and creates a draft from the Admin form', function () {
    $admin = vmUser([], 'admin');
    $this->actingAs($admin)->post(route('admin.suppliers.store'), ['supplier_name' => 'ADMIN MADE', 'country' => 'India'])
        ->assertRedirect();

    $vendor = Supplier::where('supplier_name', 'ADMIN MADE')->firstOrFail();
    expect($vendor->approval_status)->toBe('draft')->and($vendor->created_by)->toBe($admin->id);

    $this->withoutVite()->actingAs($admin)->get(route('admin.suppliers.index'))->assertOk()->assertSee('Open')->assertSee('Draft');
    $this->withoutVite()->actingAs($admin)->get(route('vendors.show', $vendor))->assertOk()->assertSee('Submit for Approval')->assertSee('Not available for new PO');
});
