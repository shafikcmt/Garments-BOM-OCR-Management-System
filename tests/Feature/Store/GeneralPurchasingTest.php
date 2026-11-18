<?php

use App\Models\GeneralStockSupplier;
use App\Models\GsComparativeStatement;
use App\Models\GsPurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\GsDocumentNumberGenerator;
use App\Support\AmountInWords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * General Stock purchasing: To Purchase queue -> Comparative Statement ->
 * Internal PO -> Store receiving against the PO number.
 *
 * In-memory sqlite. The role and permissions come from migration
 * 2026_11_05_000002, exactly as they arrive on the live server.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/** A user on the Supply Chain General role, plus any extra permissions. */
function gpUser(array $extra = [], string $name = 'SCM User'): User
{
    $user = User::factory()->create(['name' => $name])->assignRole(Role::findByName('supply_chain_general', 'web'));

    foreach ($extra as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $user;
}

/** An approved Final requisition with one line per [name, to-be-procured qty]. */
function gpApproved(array $lines, array $attributes = []): PurchaseRequisition
{
    $sheet = PurchaseRequisition::create($attributes + [
        'requisition_no' => 'FR-'.uniqid(),
        'requisition_date' => '2026-10-20',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL,
        'status' => PurchaseRequisition::STATUS_APPROVED,
        'submitted_at' => now(),
        'approved_at' => now(),
        'created_by' => User::factory()->create()->id,
    ]);

    foreach ($lines as $i => [$name, $qty]) {
        $item = StockItem::firstOrCreate(['name' => $name], ['uom' => 'Pkt', 'is_active' => true]);

        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id,
            'stock_item_id' => $item->id,
            'sort_order' => $i + 1,
            'qty_requested' => $qty,
            'to_be_procured_qty' => $qty,
            'uom_snapshot' => 'Pkt',
        ]);
    }

    return $sheet->fresh('items');
}

/**
 * A CS form payload for the given requisition lines, two suppliers, supplier 1
 * cheaper on every line unless $prices says otherwise.
 *
 * @param  array<int, array{0: float, 1: float}>  $prices  per line index: [supplier0 price, supplier1 price]
 */
function gpPayload($lines, array $prices = [], array $over = []): array
{
    $payload = [
        'cs_date' => '2026-10-24',
        'recommendation' => 'Go with KT Corporation.',
        'suppliers' => [
            0 => ['key' => '0', 'name' => 'Banani Trading House', 'phone' => '01711-696211', 'terms' => 'Excluding VAT.'],
            1 => ['key' => '1', 'name' => 'KT Corporation', 'phone' => '+880 1329-267052', 'terms' => 'Excluding VAT.'],
        ],
        'recommended_supplier' => '1',
        'lines' => [],
    ];

    foreach (collect($lines)->values() as $i => $line) {
        [$a, $b] = $prices[$i] ?? [100, 90];

        $payload['lines'][$i] = [
            'purchase_requisition_item_id' => $line->id,
            'qty' => (string) $line->to_be_procured_qty,
            'selected' => $b < $a ? '1' : '0',
            'quotes' => [0 => ['price' => (string) $a], 1 => ['price' => (string) $b]],
        ];
    }

    return array_replace($payload, $over);
}

/** Walk a CS from a payload to Approved, with three different people. */
function gpApprovedCs(GsComparativeStatement &$cs = null, ?PurchaseRequisition $sheet = null): GsComparativeStatement
{
    $sheet ??= gpApproved([['T/N DPx5 FFG 18', 30], ['F/L UYx128 FFG 18', 20]]);
    $maker = gpUser(['store.purchase_cs.create'], 'Maker');

    test()->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items, [[88, 85], [175, 168]]) + ['action' => 'submit'])
        ->assertSessionHasNoErrors();

    $cs = GsComparativeStatement::latest('id')->firstOrFail();

    $checker = gpUser(['store.purchase_cs.review'], 'Checker');
    $approver = gpUser(['store.purchase_cs.approve'], 'Approver');

    test()->actingAs($checker)->post(route('store.purchasing.cs.check', $cs))->assertSessionHasNoErrors();
    test()->actingAs($approver)->post(route('store.purchasing.cs.approve', $cs))->assertSessionHasNoErrors();

    return $cs->fresh(['items.quotes', 'suppliers']);
}

// ---------------------------------------------------------------- pure helpers

it('writes the PO total in words the way the printed PO does', function () {
    expect(AmountInWords::taka(11710))->toBe('Eleven thousand seven hundred ten Taka Only.')
        ->and(AmountInWords::taka(0))->toBe('Zero Taka Only.')
        ->and(AmountInWords::taka(100000))->toBe('One hundred thousand Taka Only.')
        ->and(AmountInWords::taka(2550.5))->toBe('Two thousand five hundred fifty Taka and Fifty Paisa Only.')
        ->and(AmountInWords::taka(1250000))->toBe('One million two hundred fifty thousand Taka Only.');
});

it('numbers CS and PO per financial year, restarting every July', function () {
    $gen = app(GsDocumentNumberGenerator::class);

    DB::transaction(function () use ($gen) {
        expect($gen->nextCs(Carbon::parse('2026-10-24')))->toBe('CS-26-27/0001')
            ->and($gen->nextCs(Carbon::parse('2026-11-02')))->toBe('CS-26-27/0002')
            ->and($gen->nextPo(Carbon::parse('2026-10-24')))->toBe('PO-26-27/T-001')
            // June is still the previous financial year; it has its own counter.
            ->and($gen->nextCs(Carbon::parse('2026-06-30')))->toBe('CS-25-26/0001')
            ->and($gen->nextCs(Carbon::parse('2026-07-01')))->toBe('CS-26-27/0003');
    });

    expect($gen->previewCs(Carbon::parse('2026-10-24')))->toBe('CS-26-27/0004');
});

it('starts the PO series after the last hand-booked PO and prints it as T-080', function () {
    config(['purchasing.po_first_no' => 81]);
    $gen = app(GsDocumentNumberGenerator::class);

    expect($gen->previewPo(Carbon::parse('2026-10-26')))->toBe('PO-26-27/T-081');

    DB::transaction(function () use ($gen) {
        expect($gen->nextPo(Carbon::parse('2026-10-26')))->toBe('PO-26-27/T-081')
            ->and($gen->nextPo(Carbon::parse('2026-10-27')))->toBe('PO-26-27/T-082');
    });
});

// ------------------------------------------------------------------ role setup

it('creates the Supply Chain General role with view and create only', function () {
    $role = Role::findByName('supply_chain_general', 'web');

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe([
        'store.purchase_cs.create', 'store.purchase_cs.view', 'store.purchase_po.create', 'store.purchase_po.view',
    ]);
});

it('keeps buyer-order Supply Chain screens closed to the Supply Chain General role', function () {
    $user = gpUser();

    $this->actingAs($user)->get(route('supply_chain.bookings.index'))->assertForbidden();
    $this->actingAs($user)->get(route('supply_chain.payment_requests.index'))->assertForbidden();
    $this->actingAs($user)->get(route('store.stock.ledger'))->assertForbidden();
});

it('keeps the purchasing screens closed to the Store role and to people with no permission', function () {
    $store = User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));

    $this->actingAs($store)->get(route('store.purchasing.queue'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('store.purchasing.cs.index'))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('store.purchasing.po.index'))->assertForbidden();
});

it('lands a Supply Chain General user on the To Purchase list', function () {
    $this->actingAs(gpUser())->get('/dashboard')->assertRedirect(route('store.purchasing.queue'));
});

// ------------------------------------------------------------------ the queue

it('lists only approved, still-to-buy lines on the To Purchase queue', function () {
    $approved = gpApproved([['Hand Sanitiser 5L', 12], ['Stapler Pins', 0]]);
    gpApproved([['Draft Only Item', 5]], ['status' => PurchaseRequisition::STATUS_SUBMITTED]);
    gpApproved([['Monthly Dept Item', 5]], ['level' => PurchaseRequisition::LEVEL_DEPARTMENT, 'requisition_type' => PurchaseRequisition::TYPE_MONTHLY]);
    gpApproved([['Individual Item', 3]], ['level' => PurchaseRequisition::LEVEL_DEPARTMENT, 'requisition_type' => PurchaseRequisition::TYPE_INDIVIDUAL]);

    $html = $this->actingAs(gpUser())->get(route('store.purchasing.queue'))->assertOk()->getContent();

    expect($html)->toContain('Hand Sanitiser 5L')->toContain($approved->requisition_no)
        ->toContain('Individual Item')
        ->not->toContain('Stapler Pins')        // nothing to procure
        ->not->toContain('Draft Only Item')     // not approved
        ->not->toContain('Monthly Dept Item');  // merged into a Final sheet, never bought from
});

// ------------------------------------------------------------- Comparative Statement

it('makes a CS from queue lines, and takes the quantity off the queue', function () {
    $sheet = gpApproved([['T/N DPx5 FFG 18', 30], ['F/L UYx128 FFG 18', 20]]);
    $maker = gpUser();

    $this->actingAs($maker)->get(route('store.purchasing.cs.create', ['lines' => $sheet->items->pluck('id')->all()]))
        ->assertOk()->assertSee('T/N DPx5 FFG 18')->assertSee('CS-26-27/0001');

    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items, [[88, 85], [175, 168]]) + ['action' => 'draft'])
        ->assertSessionHasNoErrors();

    $cs = GsComparativeStatement::firstOrFail();
    expect($cs->cs_no)->toBe('CS-26-27/0001')
        ->and($cs->status)->toBe('draft')
        ->and($cs->form_code)->toBe('HAPL-03-0345')
        ->and($cs->items)->toHaveCount(2)
        ->and($cs->suppliers)->toHaveCount(2)
        ->and($cs->suppliers->firstWhere('name', 'KT Corporation')->is_recommended)->toBeTrue();

    $cs->load('items.quotes');
    expect($cs->chosenTotal())->toBe(5910.0)   // 30*85 + 20*168
        ->and($cs->totalFor($cs->suppliers->firstWhere('name', 'Banani Trading House')))->toBe(6140.0);

    $this->actingAs($maker)->get(route('store.purchasing.queue'))->assertOk()->assertDontSee('T/N DPx5 FFG 18');
});

it('refuses a CS line asking for more than the requisition has left', function () {
    $sheet = gpApproved([['Glue Stick', 10]]);
    $payload = gpPayload($sheet->items);
    $payload['lines'][0]['qty'] = '11';

    $this->actingAs(gpUser())->post(route('store.purchasing.cs.store'), $payload + ['action' => 'draft'])
        ->assertSessionHasErrors('lines.0.qty');

    expect(GsComparativeStatement::count())->toBe(0);
});

it('lets two statements split one requisition line, and no more than its quantity', function () {
    $sheet = gpApproved([['Marker Pen', 10]]);
    $line = $sheet->items->first();
    $maker = gpUser();

    $first = gpPayload($sheet->items);
    $first['lines'][0]['qty'] = '6';
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), $first + ['action' => 'draft'])->assertSessionHasNoErrors();

    $second = gpPayload($sheet->items);
    $second['lines'][0]['qty'] = '5';
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), $second + ['action' => 'draft'])
        ->assertSessionHasErrors('lines.0.qty');

    $second['lines'][0]['qty'] = '4';
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), $second + ['action' => 'draft'])->assertSessionHasNoErrors();

    expect(GsComparativeStatement::count())->toBe(2);
    $this->actingAs($maker)->get(route('store.purchasing.queue'))->assertDontSee('Marker Pen');
});

it('will not send a CS for checking with one supplier, or with a line that has no supplier chosen', function () {
    $sheet = gpApproved([['Duster', 4]]);
    $maker = gpUser();

    $oneSupplier = gpPayload($sheet->items);
    unset($oneSupplier['suppliers'][1], $oneSupplier['lines'][0]['quotes'][1]);
    $oneSupplier['lines'][0]['selected'] = '0';
    $oneSupplier['recommended_supplier'] = '0';

    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), $oneSupplier + ['action' => 'submit'])
        ->assertSessionHasErrors('cs');

    // The draft is still saved; only the send was refused.
    $cs = GsComparativeStatement::firstOrFail();
    expect($cs->status)->toBe('draft');

    $unchosen = gpPayload($sheet->items);
    $unchosen['lines'][0]['selected'] = null;
    $this->actingAs($maker)->put(route('store.purchasing.cs.update', $cs), $unchosen + ['action' => 'submit'])
        ->assertSessionHasErrors('cs');

    expect($cs->fresh()->status)->toBe('draft');
});

it('keeps the maker, checker and approver apart, and walks the CS to Approved', function () {
    $sheet = gpApproved([['Cello Tape', 8]]);
    $maker = gpUser(['store.purchase_cs.review', 'store.purchase_cs.approve'], 'Maker');

    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items) + ['action' => 'submit'])->assertSessionHasNoErrors();
    $cs = GsComparativeStatement::firstOrFail();
    expect($cs->status)->toBe('submitted')->and($cs->prepared_by)->toBe($maker->id);

    // Even holding the permission, the person who prepared it cannot check it.
    $this->actingAs($maker)->post(route('store.purchasing.cs.check', $cs))->assertForbidden();

    // Someone without the permission cannot either.
    $this->actingAs(gpUser())->post(route('store.purchasing.cs.check', $cs))->assertForbidden();

    $checker = gpUser(['store.purchase_cs.review'], 'Checker');
    $this->actingAs($checker)->post(route('store.purchasing.cs.check', $cs))->assertSessionHasNoErrors();
    expect($cs->fresh()->status)->toBe('checked');

    // A checker without the approve permission cannot approve.
    $this->actingAs($checker)->post(route('store.purchasing.cs.approve', $cs))->assertForbidden();

    $approver = gpUser(['store.purchase_cs.approve'], 'Approver');
    $this->actingAs($approver)->post(route('store.purchasing.cs.approve', $cs), ['approver_note' => 'Take approval from internal audit first.'])
        ->assertSessionHasNoErrors();

    $cs->refresh();
    expect($cs->status)->toBe('approved')
        ->and($cs->checked_by)->toBe($checker->id)
        ->and($cs->approved_by)->toBe($approver->id)
        ->and($cs->approver_note)->toBe('Take approval from internal audit first.');
});

it('sends a CS back with a note and lets the preparer correct it', function () {
    $sheet = gpApproved([['Staple Remover', 3]]);
    $maker = gpUser();
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items) + ['action' => 'submit']);
    $cs = GsComparativeStatement::firstOrFail();
    $checker = gpUser(['store.purchase_cs.review']);

    $this->actingAs($checker)->post(route('store.purchasing.cs.send-back', $cs), ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($checker)->post(route('store.purchasing.cs.send-back', $cs), ['note' => 'Add a third quotation.'])->assertSessionHasNoErrors();

    $cs->refresh();
    expect($cs->status)->toBe('draft')->and($cs->return_note)->toBe('Add a third quotation.');

    $this->actingAs($maker)->get(route('store.purchasing.cs.edit', $cs))->assertOk()->assertSee('Add a third quotation.');
});

it('cancels a CS and gives its quantity back to the queue, unless a PO exists', function () {
    $sheet = gpApproved([['Paper Clip', 50]]);
    $maker = gpUser(['store.purchase_cs.review', 'store.purchase_cs.approve']);
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items) + ['action' => 'draft']);
    $cs = GsComparativeStatement::firstOrFail();

    $this->actingAs($maker)->get(route('store.purchasing.queue'))->assertDontSee('Paper Clip');
    $this->actingAs($maker)->post(route('store.purchasing.cs.cancel', $cs), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($maker)->post(route('store.purchasing.cs.cancel', $cs), ['reason' => 'Requirement withdrawn'])->assertSessionHasNoErrors();

    expect($cs->fresh()->status)->toBe('cancelled');
    $this->actingAs($maker)->get(route('store.purchasing.queue'))->assertSee('Paper Clip');
});

it('renders the CS screens and prints the CS as a PDF', function () {
    $cs = gpApprovedCs();
    $user = gpUser();

    $this->actingAs($user)->get(route('store.purchasing.cs.index'))->assertOk()->assertSee($cs->cs_no);
    $this->actingAs($user)->get(route('store.purchasing.cs.show', $cs))->assertOk()
        ->assertSee('Comparative Statement &amp; PO Approval Form', false)->assertSee('HAPL-03-0345')
        ->assertSee('KT Corporation')->assertSee('Banani Trading House');

    $pdf = $this->actingAs($user)->get(route('store.purchasing.cs.pdf', $cs))->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

// ------------------------------------------------------------------ Internal PO

it('makes one draft PO per chosen supplier from an approved CS, with the CS rates', function () {
    $sheet = gpApproved([['T/N DPx5 FFG 18', 30], ['B/S DPX17 R-18', 10]]);
    $maker = gpUser(['store.purchase_cs.create'], 'Maker');

    // Line 0 is cheaper at supplier 1, line 1 at supplier 0: two suppliers, two POs.
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items, [[88, 85], [119, 120]]) + ['action' => 'submit']);
    $cs = GsComparativeStatement::firstOrFail();

    // Not before approval.
    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs))->assertSessionHasErrors('cs');

    $this->actingAs(gpUser(['store.purchase_cs.review']))->post(route('store.purchasing.cs.check', $cs));
    $this->actingAs(gpUser(['store.purchase_cs.approve']))->post(route('store.purchasing.cs.approve', $cs));

    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs))->assertSessionHasNoErrors();

    $orders = GsPurchaseOrder::with('items')->orderBy('id')->get();
    expect($orders)->toHaveCount(2)
        ->and($orders->pluck('po_no')->all())->toBe(['PO-26-27/T-001', 'PO-26-27/T-002'])
        ->and($orders->pluck('status')->unique()->all())->toBe(['draft']);

    $kt = $orders->firstWhere('supplier_name', 'KT Corporation');
    expect($kt->items)->toHaveCount(1)
        ->and((float) $kt->items[0]->rate)->toBe(85.0)
        ->and((float) $kt->items[0]->qty)->toBe(30.0)
        ->and((float) $kt->total_amount)->toBe(2550.0)
        ->and($kt->destination)->toBe('Momin Nagor, Gorai, Tangail');

    // Making them again finds nothing left.
    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs))->assertSessionHasErrors('cs');
    expect(GsPurchaseOrder::count())->toBe(2);
});

it('checks then authorises a PO by two different people, then marks the requisition purchase-action-taken', function () {
    $sheet = gpApproved([['Bin Liner', 40]]);
    $cs = gpApprovedCs($cs, $sheet);
    $maker = gpUser(['store.purchase_po.approve'], 'PO Maker');

    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs))->assertSessionHasNoErrors();
    $po = GsPurchaseOrder::firstOrFail();

    expect($po->reference_no)->toBe('T-001');

    // Nobody can authorise a PO that has not been checked.
    $this->actingAs(gpUser(['store.purchase_po.approve'], 'Early'))->post(route('store.purchasing.po.authorise', $po))->assertForbidden();

    // Approval 1: the maker cannot check their own PO, nor can someone without the permission.
    $maker->givePermissionTo('store.purchase_po.review');
    $this->actingAs($maker)->post(route('store.purchasing.po.check', $po))->assertForbidden();
    $this->actingAs(gpUser())->post(route('store.purchasing.po.check', $po))->assertForbidden();
    $checker = gpUser(['store.purchase_po.review', 'store.purchase_po.approve'], 'PO Checker');
    $this->actingAs($checker)->post(route('store.purchasing.po.check', $po))->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe('checked')->and($po->fresh()->checked_by)->toBe($checker->id);

    // Approval 2: neither the maker nor the checker may authorise; nor may someone without the permission.
    $this->actingAs($maker)->post(route('store.purchasing.po.authorise', $po))->assertForbidden();
    $this->actingAs($checker)->post(route('store.purchasing.po.authorise', $po))->assertForbidden();
    $this->actingAs(gpUser())->post(route('store.purchasing.po.authorise', $po))->assertForbidden();

    // Checked but not yet issued: Store cannot receive and the requisition has not moved.
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED);
    expect(GsPurchaseOrder::receivable()->count())->toBe(0);

    $authoriser = gpUser(['store.purchase_po.approve'], 'Authoriser');
    $this->actingAs($authoriser)->post(route('store.purchasing.po.authorise', $po))->assertSessionHasNoErrors();

    $po->refresh();
    expect($po->status)->toBe('issued')->and($po->authorised_by)->toBe($authoriser->id)
        ->and($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN);

    // Cancelling the PO brings the requisition back to Approved.
    $this->actingAs($maker)->post(route('store.purchasing.po.cancel', $po), ['reason' => 'Supplier withdrew'])->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe('cancelled')
        ->and($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED);
});

it('edits only the header of a draft PO, never its items, rate, quantity or BIN', function () {
    $cs = gpApprovedCs();
    $maker = gpUser();
    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs));
    $po = GsPurchaseOrder::with('items')->firstOrFail();
    $line = $po->items->first();

    $this->actingAs($maker)->get(route('store.purchasing.po.edit', $po))->assertOk();

    $this->actingAs($maker)->put(route('store.purchasing.po.update', $po), [
        'po_date' => '2026-10-25',
        'reference_no' => 'REF-9',
        'bin_number' => '1111',
        'lines' => [$line->id => ['due_on' => '30-Oct-26', 'remarks' => 'Urgent', 'qty' => '999', 'rate' => '1']],
    ])->assertSessionHasNoErrors();

    $line->refresh();
    $po->refresh();
    expect($po->reference_no)->toBe('REF-9')
        ->and($po->bin_number)->toBe('0056353810406')
        ->and($line->due_on)->not->toBe('30-Oct-26')
        ->and($line->remarks)->not->toBe('Urgent')
        ->and((float) $line->qty)->not->toBe(999.0)
        ->and((float) $line->rate)->not->toBe(1.0);
});

it('renders the PO list and screen and prints the PO as a PDF', function () {
    $cs = gpApprovedCs();
    $this->actingAs(gpUser())->post(route('store.purchasing.po.store', $cs));
    $po = GsPurchaseOrder::firstOrFail();
    $user = gpUser();

    $this->actingAs($user)->get(route('store.purchasing.po.index'))->assertOk()->assertSee($po->po_no);
    $this->actingAs($user)->get(route('store.purchasing.po.show', $po))->assertOk()
        ->assertSee('PURCHASE ORDER')->assertSee('Five thousand nine hundred ten Taka Only.', false);

    $pdf = $this->actingAs($user)->get(route('store.purchasing.po.pdf', $po))->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

// -------------------------------------------------------- Store receiving by PO

/** An issued PO for KT Corporation with two lines (30 and 20), and its supplier record. */
function gpIssuedPo(): array
{
    $supplier = GeneralStockSupplier::create(['name' => 'KT Corporation', 'is_active' => true]);
    $sheet = gpApproved([['T/N DPx5 FFG 18', 30], ['F/L UYx128 FFG 18', 20]]);
    $maker = gpUser(['store.purchase_cs.create'], 'Maker');

    $payload = gpPayload($sheet->items, [[88, 85], [175, 168]]);
    $payload['suppliers'][1]['general_stock_supplier_id'] = $supplier->id;

    test()->actingAs($maker)->post(route('store.purchasing.cs.store'), $payload + ['action' => 'submit'])->assertSessionHasNoErrors();
    $cs = GsComparativeStatement::latest('id')->firstOrFail();
    test()->actingAs(gpUser(['store.purchase_cs.review']))->post(route('store.purchasing.cs.check', $cs));
    test()->actingAs(gpUser(['store.purchase_cs.approve']))->post(route('store.purchasing.cs.approve', $cs));
    test()->actingAs($maker)->post(route('store.purchasing.po.store', $cs))->assertSessionHasNoErrors();

    // Line 0 of the CS goes to supplier 1 (KT), line 1 as well: one PO.
    $po = GsPurchaseOrder::with('items')->latest('id')->firstOrFail();
    test()->actingAs(gpUser(['store.purchase_po.review']))->post(route('store.purchasing.po.check', $po))->assertSessionHasNoErrors();
    test()->actingAs(gpUser(['store.purchase_po.approve']))->post(route('store.purchasing.po.authorise', $po))->assertSessionHasNoErrors();

    return [$po->fresh('items'), $supplier];
}

function gpStoreUser(): User
{
    return User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));
}

function gpReceive(User $store, GsPurchaseOrder $po, GeneralStockSupplier $supplier, array $qtyByLine, array $over = [])
{
    $items = [];

    foreach ($qtyByLine as $i => $qty) {
        $line = $po->items[$i];
        $items[] = [
            'stock_item_id' => $line->stock_item_id, 'qty' => $qty, 'unit_price' => (string) $line->rate,
            'gs_purchase_order_item_id' => $line->id,
        ];
    }

    return test()->actingAs($store)->post(route('store.stock.purchases.store'), array_replace([
        'purchase_date' => '2026-10-26', 'rcv_date' => '2026-10-27', 'challan_no' => 'CH-'.uniqid(),
        'general_stock_supplier_id' => $supplier->id, 'gs_purchase_order_id' => $po->id, 'items' => $items,
    ], $over));
}

it('lets Store find an issued PO and load what is still to come', function () {
    [$po] = gpIssuedPo();
    $store = gpStoreUser();

    $this->actingAs($store)->getJson(route('store.stock.purchases.po-search', ['q' => 'T-001']))
        ->assertOk()->assertJsonPath('0.po_no', $po->po_no);

    $this->actingAs($store)->getJson(route('store.stock.purchases.po-lines', $po))
        ->assertOk()->assertJsonCount(2, 'lines')->assertJsonPath('lines.0.remaining', 30)->assertJsonPath('supplier', 'KT Corporation');

    // A draft PO is not open for receiving.
    $draft = GsPurchaseOrder::create(['po_no' => 'PO-26-27/T-999', 'po_date' => '2026-10-24', 'supplier_name' => 'X', 'status' => 'draft']);
    $this->actingAs($store)->getJson(route('store.stock.purchases.po-lines', $draft))->assertNotFound();
});

it('records goods against a PO, moves it to Partly Received then Received, and refuses over-receiving', function () {
    [$po, $supplier] = gpIssuedPo();
    $store = gpStoreUser();

    gpReceive($store, $po, $supplier, [10])->assertSessionHasNoErrors();

    $row = StockPurchase::firstOrFail();
    expect($row->gs_purchase_order_id)->toBe($po->id)->and($row->gs_purchase_order_item_id)->toBe($po->items[0]->id)
        ->and($po->fresh()->status)->toBe('partially_received');

    // 20 more of line 0 is the 30 ordered; 21 is one too many.
    gpReceive($store, $po, $supplier, [21])->assertSessionHasErrors('items.0.qty');
    expect(StockPurchase::count())->toBe(1);

    gpReceive($store, $po, $supplier, [20, 20])->assertSessionHasNoErrors();
    expect(StockPurchase::count())->toBe(3)->and($po->fresh()->status)->toBe('received');

    // Fully received: no longer offered to Store.
    $this->actingAs($store)->getJson(route('store.stock.purchases.po-search'))->assertOk()->assertJsonCount(0);
    $this->actingAs($store)->getJson(route('store.stock.purchases.po-lines', $po))->assertNotFound();
});

it('refuses receiving against a PO from the wrong supplier or for an item not on it', function () {
    [$po, $supplier] = gpIssuedPo();
    $store = gpStoreUser();
    $other = GeneralStockSupplier::create(['name' => 'Someone Else', 'is_active' => true]);

    gpReceive($store, $po, $other, [5])->assertSessionHasErrors('general_stock_supplier_id');

    $stranger = StockItem::create(['name' => 'Not On The PO', 'uom' => 'Pcs', 'is_active' => true]);
    gpReceive($store, $po, $supplier, [5], ['items' => [[
        'stock_item_id' => $stranger->id, 'qty' => 1, 'unit_price' => '10', 'gs_purchase_order_item_id' => $po->items[0]->id,
    ]]])->assertSessionHasErrors('items.0.stock_item_id');

    gpReceive($store, $po, $supplier, [5], ['items' => [[
        'stock_item_id' => $po->items[0]->stock_item_id, 'qty' => 1, 'unit_price' => '10', 'gs_purchase_order_item_id' => 999999,
    ]]])->assertSessionHasErrors('items.0.stock_item_id');

    expect(StockPurchase::count())->toBe(0);
});

it('still records goods with no PO, exactly as before', function () {
    $supplier = GeneralStockSupplier::create(['name' => 'Walk-in Vendor', 'is_active' => true]);
    $item = StockItem::create(['name' => 'Loose Item', 'uom' => 'Pcs', 'is_active' => true]);

    $this->actingAs(gpStoreUser())->post(route('store.stock.purchases.store'), [
        'purchase_date' => '2026-10-26', 'rcv_date' => '2026-10-27', 'challan_no' => 'CH-1',
        'general_stock_supplier_id' => $supplier->id,
        'items' => [['stock_item_id' => $item->id, 'qty' => 4, 'unit_price' => '12']],
    ])->assertSessionHasNoErrors();

    $row = StockPurchase::firstOrFail();
    expect($row->gs_purchase_order_id)->toBeNull()->and($row->gs_purchase_order_item_id)->toBeNull();
});

it('puts Against PO on the Record Receiving form', function () {
    [$po] = gpIssuedPo();

    $html = $this->actingAs(gpStoreUser())->get(route('store.stock.purchases.index'))->assertOk()->getContent();

    expect($html)->toContain('id="poSelect"')->toContain($po->po_no)->toContain('gs_purchase_order_item_id');
});

// ------------------------------------------------- long monthly lists: categories, bulk entry

/** An approved sheet whose items sit in the given categories: [name, qty, category]. */
function gpApprovedWithCategories(array $lines): PurchaseRequisition
{
    $sheet = gpApproved(array_map(fn ($l) => [$l[0], $l[1]], $lines));

    foreach ($lines as [$name, , $category]) {
        StockItem::where('name', $name)->update(['category' => $category]);
    }

    return $sheet;
}

it('groups the To Purchase queue by category, filters by one, and counts the categories', function () {
    gpApprovedWithCategories([['Bin Liner', 40, 'Cleaning'], ['Floor Wiper', 5, 'Cleaning'], ['Ball Pen', 60, 'Stationery'], ['Mystery', 1, '']]);
    $user = gpUser();

    $this->actingAs($user)->get(route('store.purchasing.queue'))->assertOk()
        ->assertSee('Cleaning')->assertSee('Stationery')->assertSee('Uncategorised')
        ->assertSee('Make CS for Cleaning only');

    $this->actingAs($user)->get(route('store.purchasing.queue', ['category' => 'Stationery']))->assertOk()
        ->assertSee('Ball Pen')->assertDontSee('Bin Liner')
        // The category list still offers the others, so the user can switch.
        ->assertSee('Cleaning (2)', false);
});

it('starts a CS from many ticked items by POST, in category order, with the category kept', function () {
    $sheet = gpApprovedWithCategories([['Ball Pen', 60, 'Stationery'], ['Bin Liner', 40, 'Cleaning']]);
    $user = gpUser();

    $this->actingAs($user)->post(route('store.purchasing.cs.start'), ['lines' => $sheet->items->pluck('id')->all()])
        ->assertRedirect(route('store.purchasing.cs.create'));

    $html = $this->actingAs($user)->get(route('store.purchasing.cs.create'))->assertOk()->getContent();
    expect(strpos($html, 'Bin Liner'))->toBeLessThan(strpos($html, 'Ball Pen')) // Cleaning before Stationery
        ->and($html)->toContain('id="rowSearch"')->toContain('name="lines_json"');

    $this->actingAs($user)->post(route('store.purchasing.cs.start'), [])->assertSessionHasErrors('lines');
});

it('saves a 600-item statement sent as one JSON field, with the categories', function () {
    $names = [];
    for ($n = 1; $n <= 600; $n++) {
        $names[] = ['Item '.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 2, $n % 2 ? 'Electrical' : 'Hardware'];
    }
    $sheet = gpApprovedWithCategories($names);
    $maker = gpUser();

    $payload = gpPayload($sheet->items, array_fill(0, 600, [10, 9]));
    $lines = $payload['lines'];
    foreach ($lines as $i => &$l) { $l['category'] = $names[$i][2]; }
    unset($l);
    $payload['lines_json'] = json_encode(array_values($lines));
    unset($payload['lines']);

    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), $payload + ['action' => 'draft'])->assertSessionHasNoErrors();

    $cs = GsComparativeStatement::with('items.quotes')->firstOrFail();
    expect($cs->items)->toHaveCount(600)
        ->and($cs->items->first()->category)->toBe('Electrical')
        ->and($cs->items->first()->quotes)->toHaveCount(2)
        ->and($cs->chosenTotal())->toBe(600 * 2 * 9.0);

    // Shown on the screen and the PDF, grouped under its category heading.
    $this->actingAs($maker)->get(route('store.purchasing.cs.show', $cs))->assertOk()->assertSee('Electrical');
    $this->actingAs($maker)->get(route('store.purchasing.cs.pdf', $cs))->assertOk();
});

// ------------------------------------------------------------------- dashboard

it('shows the purchasing panel on the General Stock dashboard, with real figures, only to purchasing users', function () {
    $sheet = gpApprovedWithCategories([['Bin Liner', 40, 'Cleaning'], ['Ball Pen', 60, 'Stationery']]);
    $desk = gpUser(['store.requisition.view'], 'Desk');

    $this->actingAs($desk)->get(route('store.dashboard'))->assertOk()
        ->assertSee('Items waiting to be purchased')->assertSee('Waiting to purchase, by category')
        ->assertSee('Cleaning')->assertSee('Ordered value per month');

    $summary = app(\App\Services\PurchasingDashboardService::class)->summary();
    expect($summary['waiting_items'])->toBe(2)->and($summary['trend'])->toHaveCount(6);

    // A plain stock user without purchasing rights does not get the panel.
    $store = gpStoreUser();
    $this->actingAs($store)->get(route('store.dashboard'))->assertOk()->assertDontSee('Items waiting to be purchased');
});

it('counts an authorised PO in this month on the dashboard trend', function () {
    [$po] = gpIssuedPo();
    $summary = app(\App\Services\PurchasingDashboardService::class)->summary();

    expect($summary['po']['issued'])->toBe(1)->and($summary['recent_orders'])->toHaveCount(1);
});

// ------------------------------------------------- view-only Receiving (checkers)

it('shows a view-only Receiving user the history but not the entry form, import or drafts', function () {
    StockItem::create(['name' => 'Bin Liner', 'uom' => 'Pkt', 'is_active' => true]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('store.receiving.view', 'web'));

    $this->actingAs($viewer)->get(route('store.stock.purchases.index'))->assertOk()
        ->assertSee('Purchase History')->assertSee('Receiving summary')
        ->assertDontSee('>Record Receiving</h5>', false)->assertDontSee('id="purchaseForm"', false)
        ->assertDontSee('importReceivingModal', false)->assertDontSee('purchases.drafts', false);

    // They cannot post one either.
    $this->actingAs($viewer)->post(route('store.stock.purchases.store'), [])->assertForbidden();

    // Whoever may enter receiving still gets the form.
    $entry = User::factory()->create();
    $entry->givePermissionTo(Permission::findOrCreate('store.receiving.view', 'web'), Permission::findOrCreate('store.receiving.create', 'web'));
    $this->actingAs($entry)->get(route('store.stock.purchases.index'))->assertOk()
        ->assertSee('>Record Receiving</h5>', false)->assertSee('id="purchaseForm"', false)->assertSee('importReceivingModal', false)
        ->assertDontSee('Receiving summary');
});

it('alerts the checker, then the approver, then the maker as a CS moves along', function () {
    $sheet = gpApproved([['T/N DPx5 FFG 18', 30]]);
    $maker = gpUser(['store.purchase_cs.create'], 'Maker');
    $checker = gpUser(['store.purchase_cs.review'], 'Checker');
    $approver = gpUser(['store.purchase_cs.approve'], 'Approver');

    test()->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items, [[88, 85]]) + ['action' => 'submit'])->assertSessionHasNoErrors();
    $cs = GsComparativeStatement::latest('id')->firstOrFail();

    expect(\App\Models\AppNotification::where('user_id', $checker->id)->where('type', 'purchase_cs')->count())->toBe(1)
        ->and(\App\Models\AppNotification::where('user_id', $approver->id)->count())->toBe(0);

    test()->actingAs($checker)->post(route('store.purchasing.cs.check', $cs))->assertSessionHasNoErrors();
    expect(\App\Models\AppNotification::where('user_id', $approver->id)->where('type', 'purchase_cs')->count())->toBe(1);

    test()->actingAs($approver)->post(route('store.purchasing.cs.approve', $cs))->assertSessionHasNoErrors();
    expect(\App\Models\AppNotification::where('user_id', $maker->id)->where('type', 'purchase_cs')->count())->toBe(2);
});

// ------------------------------------------------- price comparison and PO by supplier

/** A submitted CS of three machine needles: KT is cheaper on every line, yet $selected is chosen on all of them. */
function gpNeedleCs(string $selected = '0'): GsComparativeStatement
{
    $sheet = gpApproved([['P/M Needle DBX1-FFG-16', 200], ['O/L Needle DCX27-FFG-16', 300], ['F/L Needle UYX128 GAS-FFG-16', 200]]);
    $payload = gpPayload($sheet->items, [[88, 85], [149, 145], [175, 168]]);
    foreach ($payload['lines'] as $i => $line) {
        $payload['lines'][$i]['selected'] = $selected;
    }

    test()->actingAs(gpUser(['store.purchase_cs.create'], 'Maker'))->post(route('store.purchasing.cs.store'), $payload + ['action' => 'submit'])->assertSessionHasNoErrors();

    return GsComparativeStatement::latest('id')->firstOrFail()->load(['items.quotes', 'suppliers']);
}

it('compares what was chosen with the lowest price on every line, and says which lines cost more', function () {
    $cs = gpNeedleCs('0');                                   // Banani chosen everywhere
    $c = app(\App\Services\CsComparison::class)->for($cs);

    expect($c['chosen_total'])->toBe(97300.0)                // 200x88 + 300x149 + 200x175
        ->and($c['lowest_total'])->toBe(94100.0)             // 200x85 + 300x145 + 200x168
        ->and($c['extra'])->toBe(3200.0)->and($c['extra_pct'])->toBe(3.4)
        ->and($c['not_lowest'])->toHaveCount(3)->and($c['not_lowest'][1]['extra'])->toBe(1200.0)
        ->and($c['not_lowest'][0]['lowest_name'])->toBe('KT Corporation')->and($c['unchosen'])->toBe(0);

    $kt = collect($c['suppliers'])->first(fn ($r) => $r['supplier']->name === 'KT Corporation');
    expect($kt)->toMatchArray(['quoted' => 3, 'total' => 94100.0, 'complete' => true, 'lowest_on' => 3, 'chosen_on' => 0])
        ->and($c['best_single']['supplier']->name)->toBe('KT Corporation');

    $this->actingAs(gpUser())->get(route('store.purchasing.cs.show', $cs))->assertOk()
        ->assertSee('Price comparison')->assertSee('lowest full quote')->assertSee('more.', false)->assertSee('O/L Needle DCX27-FFG-16')->assertSee('+1,200.00');
});

it('is satisfied when the lowest price is chosen, and keeps the comparison off the printed form', function () {
    $cs = gpNeedleCs('1');                                   // KT chosen everywhere
    $c = app(\App\Services\CsComparison::class)->for($cs);
    expect($c['extra'])->toBe(0.0)->and($c['not_lowest'])->toBe([])->and($c['chosen_total'])->toBe(94100.0);

    $this->actingAs(gpUser())->get(route('store.purchasing.cs.show', $cs))->assertOk()->assertSee('The chosen supplier is the lowest price on every line');

    $sheet = view('store.purchasing._cs-sheet', ['cs' => $cs])->render();
    expect($sheet)->not->toContain('Price comparison')->not->toContain('lowest full quote');
});

it('makes the purchase orders one supplier at a time, or all together', function () {
    $sheet = gpApproved([['T/N DPx5 FFG 18', 30], ['B/S DPX17 R-18', 10], ['F/L UYx128 FFG 18', 5]]);
    $maker = gpUser(['store.purchase_cs.create'], 'Maker');
    // Lines 0 and 2 are cheaper at KT (supplier 1), line 1 at Banani (supplier 0).
    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), gpPayload($sheet->items, [[88, 85], [119, 120], [175, 168]]) + ['action' => 'submit']);
    $cs = GsComparativeStatement::firstOrFail();
    $this->actingAs(gpUser(['store.purchase_cs.review']))->post(route('store.purchasing.cs.check', $cs));
    $this->actingAs(gpUser(['store.purchase_cs.approve']))->post(route('store.purchasing.cs.approve', $cs));
    $cs->load('suppliers');
    $banani = $cs->suppliers->firstWhere('name', 'Banani Trading House');
    $kt = $cs->suppliers->firstWhere('name', 'KT Corporation');

    $plan = app(\App\Services\PurchaseOrderService::class)->plan($cs->fresh());
    expect($plan)->toHaveCount(2)->and($plan->firstWhere('supplier.id', $kt->id)['items'])->toBe(2)->and($plan->firstWhere('supplier.id', $banani->id)['items'])->toBe(1);

    $this->actingAs($maker)->get(route('store.purchasing.cs.show', $cs))->assertOk()
        ->assertSee('Purchase orders by supplier')->assertSee('Make PO for KT Corporation')->assertSee('Make All Purchase Orders (2)');

    // One supplier now.
    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs), ['supplier' => $kt->id])->assertSessionHasNoErrors();
    $orders = GsPurchaseOrder::with('items')->get();
    expect($orders)->toHaveCount(1)->and($orders[0]->supplier_name)->toBe('KT Corporation')->and($orders[0]->items)->toHaveCount(2)->and((float) $orders[0]->total_amount)->toBe(30 * 85.0 + 5 * 168.0);

    // The same supplier again has nothing left; an unknown supplier is refused; the other one still works.
    $this->actingAs($maker)->post(route('store.purchasing.po.store', $cs), ['supplier' => $kt->id])->assertSessionHasErrors('cs');
    $this->post(route('store.purchasing.po.store', $cs), ['supplier' => 999999])->assertSessionHasErrors('cs');
    $page = $this->get(route('store.purchasing.cs.show', $cs))->assertOk();
    $page->assertSee('All ordered')->assertSee('Make PO for Banani Trading House')->assertDontSee('Make All Purchase Orders');

    $this->post(route('store.purchasing.po.store', $cs))->assertSessionHasNoErrors();      // "all" now makes the remaining one
    expect(GsPurchaseOrder::count())->toBe(2)->and(GsPurchaseOrder::where('supplier_name', 'Banani Trading House')->count())->toBe(1);

    $this->post(route('store.purchasing.po.store', $cs))->assertSessionHasErrors('cs');
    $this->post(route('store.purchasing.po.store', $cs), ['supplier' => $banani->id])->assertSessionHasErrors('cs');
});

it('keeps making a PO from a CS to people who may create POs, supplier by supplier too', function () {
    $cs = gpApprovedCs();
    $viewer = gpUser();   // supply_chain_general holds po.create; take it away to check the per-supplier button is hidden
    $viewer->revokePermissionTo('store.purchase_po.create');
    $viewer->roles()->detach();
    $viewer->givePermissionTo(Permission::findOrCreate('store.purchase_cs.view', 'web'));

    $this->actingAs($viewer)->post(route('store.purchasing.po.store', $cs), ['supplier' => $cs->suppliers->first()->id])->assertForbidden();
});

it('prints the standard three signature boxes when none were set', function () {
    $cs = gpApprovedCs();
    $html = view('store.purchasing._cs-sheet', ['cs' => $cs->fresh()->load(['preparedBy', 'checkedBy', 'approvedBy'])])->render();

    expect($html)->toContain('Jr. Executive - SCM')->toContain('Manager - SCM')->toContain('Approved By');
});

it('lets the maker choose the signature boxes, and fills names from the workflow', function () {
    $sheet = gpApproved([['P/M Needle DBX1', 10]]);
    $maker = gpUser(['store.purchase_cs.create'], 'Maker One');
    $payload = gpPayload($sheet->items, [[88, 85]], ['signatories' => [
        ['label' => 'Merchandiser', 'role' => 'prepared'],
        ['label' => 'Head of SCM', 'role' => 'checked'],
        ['label' => 'Director', 'role' => 'blank'],
        ['label' => 'MD', 'role' => 'approved'],
        ['label' => '', 'role' => 'blank'],
    ]]);

    $this->actingAs($maker)->post(route('store.purchasing.cs.store'), $payload + ['action' => 'draft'])->assertSessionHasNoErrors();
    $cs = GsComparativeStatement::latest('id')->firstOrFail();

    expect($cs->signBoxes())->toHaveCount(4)->and($cs->signBoxes()[2])->toBe(['label' => 'Director', 'role' => 'blank']);

    $cs->update(['prepared_by' => $maker->id, 'prepared_at' => now()]);   // as if submitted
    $html = view('store.purchasing._cs-sheet', ['cs' => $cs->fresh()->load(['preparedBy', 'checkedBy', 'approvedBy'])])->render();
    expect($html)->toContain('Merchandiser')->toContain('Head of SCM')->toContain('Director')->toContain('>MD<')
        ->not->toContain('Jr. Executive - SCM')->toContain('Maker One');

    $this->actingAs($maker)->get(route('store.purchasing.cs.edit', $cs))->assertOk()->assertSee('Signature boxes on the printed form')->assertSee('Head of SCM');
});

it('highlights only the lowest unit price cell, and prints a supplier comment above the details', function () {
    $sheet = gpApproved([['P/M Needle DBX1-FFG-16', 200], ['O/L Needle DCX27-FFG-16', 300]]);
    $payload = gpPayload($sheet->items, [[88, 85], [140, 145]]);
    $payload['suppliers'][0]['comment'] = 'N.B. The model offered does not match the sample.';

    test()->actingAs(gpUser(['store.purchase_cs.create'], 'Maker'))->post(route('store.purchasing.cs.store'), $payload + ['action' => 'submit'])->assertSessionHasNoErrors();
    $cs = GsComparativeStatement::latest('id')->firstOrFail()->load(['items.quotes', 'suppliers']);

    expect($cs->suppliers[0]->comment)->toBe('N.B. The model offered does not match the sample.')
        ->and($cs->suppliers[1]->comment)->toBeNull();

    $html = view('store.purchasing._cs-sheet', ['cs' => $cs])->render();
    // One lowest cell per line: 85 (KT) on line 1, 140 (Banani) on line 2; no whole-column highlight.
    expect(substr_count($html, 'class="num lowest"'))->toBe(2)
        ->and($html)->toContain('class="num lowest">85<')->toContain('class="num lowest">140<')
        ->not->toContain('class="chosen"')
        ->toContain('class="terms note">N.B. The model offered does not match the sample.</td>');
});
