<?php

use App\Http\Controllers\SupplyChain\BookingController;
use App\Models\BookingInstruction;
use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\User;

/**
 * Buyer scope on the Notes / Instructions block of a booking PO.
 *
 * WHAT THIS PINS. Every instruction used to print on every PO, so a buyer's own
 * requirement — the live table carries a Hugo Boss RSL / PFC-free declaration —
 * went out to suppliers working other buyers' orders. buyer_id now narrows a
 * row to one buyer; null still means every buyer.
 *
 * THE CASE MOST LIKELY TO REGRESS is the soft-deleted buyer. Six of the nine
 * live buyers are soft-deleted and two of those still appear on booking POs, so
 * a resolver that forgets withTrashed() resolves them to null and quietly drops
 * their buyer-specific clauses. It fails silently — a PO with one paragraph
 * missing looks exactly like a correct PO — which is why it is asserted twice
 * below, once for resolution and once for the printed text.
 *
 * The other silent direction is an UNKNOWN buyer name. Many BOM buyer names
 * have no row in `buyers` at all. Those must fall back to globals only, never
 * to "no filter", or the leak reopens for exactly the POs nobody is watching.
 */

/** Reach the two protected resolvers without booting a request. */
function instructionScopeController(): array
{
    $class = new ReflectionClass(BookingController::class);
    $controller = $class->newInstanceWithoutConstructor();

    $resolve = $class->getMethod('instructionBuyerIdForBooking');
    $resolve->setAccessible(true);

    $defaults = $class->getMethod('defaultInstructionTexts');
    $defaults->setAccessible(true);

    $options = $class->getMethod('bookingInstructionOptions');
    $options->setAccessible(true);

    return [$controller, $resolve, $defaults, $options];
}

/** Resolved buyer id, then the default instruction texts, for one PO. */
function instructionsForPo(BookingPo $po, array $bookingData = []): array
{
    [$controller, $resolve, $defaults] = instructionScopeController();

    $buyerId = $resolve->invoke($controller, $po, $bookingData);

    return [$buyerId, $defaults->invoke($controller, $buyerId)];
}

function instructionPo(string $buyerName, ?int $fileBuyerId = null): BookingPo
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $fileBuyerId,
        'total_rows' => 1,
    ]);

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    return BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'po_no' => 'PO-'.uniqid(),
        'buyer_name' => $buyerName,
        'vendor_name' => 'Vendor',
        'status' => 'generated',
    ]);
}

function instruction(string $text, ?int $buyerId = null, bool $isDefault = true): BookingInstruction
{
    return BookingInstruction::create([
        'instruction' => $text,
        'buyer_id' => $buyerId,
        'is_default' => $isDefault,
        'is_active' => true,
        'sort_order' => 10,
    ]);
}

beforeEach(function () {
    BookingInstruction::query()->delete();
});

it('prints global instructions on every buyer', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    Buyer::create(['buyer_code' => 'MC', 'buyer_name' => 'MACYS', 'is_active' => true]);

    instruction('Shipping documents must mention buyer name and PO no.');
    instruction('Hugo Boss RSL documents must be signed.', $hugoBoss->id);

    [, $hugoBossNotes] = instructionsForPo(instructionPo('Hugo Boss'));
    [, $macysNotes] = instructionsForPo(instructionPo('MACYS'));

    expect($hugoBossNotes)->toContain('Shipping documents must mention buyer name and PO no.')
        ->and($macysNotes)->toContain('Shipping documents must mention buyer name and PO no.');
});

it('keeps a buyer-specific instruction off other buyers POs', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    Buyer::create(['buyer_code' => 'MC', 'buyer_name' => 'MACYS', 'is_active' => true]);

    instruction('Global clause.');
    instruction('Hugo Boss RSL documents must be signed.', $hugoBoss->id);

    [$hugoBossId, $hugoBossNotes] = instructionsForPo(instructionPo('Hugo Boss'));
    [, $macysNotes] = instructionsForPo(instructionPo('MACYS'));

    expect($hugoBossId)->toBe($hugoBoss->id)
        ->and($hugoBossNotes)->toContain('Hugo Boss RSL documents must be signed.')
        ->and($macysNotes)->not->toContain('Hugo Boss RSL documents must be signed.')
        ->and($macysNotes)->toBe(['Global clause.']);
});

it('gives an unresolvable buyer name globals only', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);

    instruction('Global clause.');
    instruction('Hugo Boss RSL documents must be signed.', $hugoBoss->id);

    // "Luna Apparel" is a live booking_pos buyer name with no row in `buyers`.
    [$buyerId, $notes] = instructionsForPo(instructionPo('Luna Apparel'));

    expect($buyerId)->toBeNull()
        ->and($notes)->toBe(['Global clause.']);
});

it('still resolves a soft-deleted buyer', function () {
    $next = Buyer::create(['buyer_code' => 'NX', 'buyer_name' => 'NEXT', 'is_active' => true]);
    instruction('Global clause.');
    instruction('NEXT carton marking rule.', $next->id);

    $next->delete();

    // HUMANA and NEXT are both soft-deleted and both still on live POs.
    [$buyerId, $notes] = instructionsForPo(instructionPo('NEXT'));

    expect($buyerId)->toBe($next->id)
        ->and($notes)->toContain('NEXT carton marking rule.');
});

it('matches the buyer name regardless of case', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    instruction('Hugo Boss RSL documents must be signed.', $hugoBoss->id);

    [$buyerId] = instructionsForPo(instructionPo('HUGO BOSS'));

    expect($buyerId)->toBe($hugoBoss->id);
});

it('prefers the BOM file buyer_id over the free-text name', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    Buyer::create(['buyer_code' => 'MC', 'buyer_name' => 'MACYS', 'is_active' => true]);

    [$buyerId] = instructionsForPo(instructionPo('MACYS', $hugoBoss->id));

    expect($buyerId)->toBe($hugoBoss->id);
});

it('returns no default text when the table is empty instead of stale hardcoded text', function () {
    // The removed fallback used to return six retired instructions here.
    [, $notes] = instructionsForPo(instructionPo('Hugo Boss'));

    expect($notes)->toBe([]);
});

it('scopes the suggestion tick list to the buyer', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    Buyer::create(['buyer_code' => 'MC', 'buyer_name' => 'MACYS', 'is_active' => true]);

    instruction('Global suggestion.', null, false);
    instruction('Hugo Boss only suggestion.', $hugoBoss->id, false);

    [$controller, , , $options] = instructionScopeController();

    $hugoBossList = $options->invoke($controller, instructionPo('Hugo Boss'), [])->pluck('instruction')->all();
    $macysList = $options->invoke($controller, instructionPo('MACYS'), [])->pluck('instruction')->all();

    expect($hugoBossList)->toContain('Hugo Boss only suggestion.')
        ->and($macysList)->toBe(['Global suggestion.']);
});

it('hides a suggestion from the tick list once it is already on the PO', function () {
    Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    instruction('Global suggestion.', null, false);

    [$controller, , , $options] = instructionScopeController();
    $po = instructionPo('Hugo Boss');

    $before = $options->invoke($controller, $po, ['notes' => []])->pluck('instruction')->all();

    // Punctuation and case differ — the same instruction, not a second one.
    $after = $options->invoke($controller, $po, ['notes' => ['global suggestion']])->pluck('instruction')->all();

    expect($before)->toBe(['Global suggestion.'])
        ->and($after)->toBe([]);
});

it('offers a removed default back on the tick list', function () {
    Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    instruction('Default clause.');

    [$controller, , , $options] = instructionScopeController();
    $po = instructionPo('Hugo Boss');

    // The user deleted the line from Current Instructions; it must be tickable
    // again rather than gone, which is why the filter is computed against the
    // notes rather than against is_default.
    $list = $options->invoke($controller, $po, ['notes' => []])->pluck('instruction')->all();

    expect($list)->toBe(['Default clause.']);
});

it('refuses a ticked instruction id belonging to another buyer', function () {
    $hugoBoss = Buyer::create(['buyer_code' => 'HB', 'buyer_name' => 'Hugo Boss', 'is_active' => true]);
    $macys = Buyer::create(['buyer_code' => 'MC', 'buyer_name' => 'MACYS', 'is_active' => true]);

    $global = instruction('Global clause.', null, false);
    $hugoBossOnly = instruction('Hugo Boss RSL documents must be signed.', $hugoBoss->id, false);

    $class = new ReflectionClass(BookingController::class);
    $controller = $class->newInstanceWithoutConstructor();
    $byIds = $class->getMethod('instructionTextsByIds');
    $byIds->setAccessible(true);

    // A hand-edited form post naming Hugo Boss's instruction on a MACYS PO.
    $texts = $byIds->invoke($controller, [$global->id, $hugoBossOnly->id], $macys->id);

    expect($texts)->toBe(['Global clause.']);
});

/**
 * The admin edit screen, which AdminAreaPagesRenderTest does not reach — it
 * covers index and create only, and edit is the one that has to preselect an
 * existing buyer, including a soft-deleted one.
 */
it('renders the admin edit form with a soft-deleted buyer still selected', function () {
    \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create()->assignRole('admin');

    $next = Buyer::create(['buyer_code' => 'NX', 'buyer_name' => 'NEXT', 'is_active' => true]);
    $row = instruction('NEXT carton marking rule.', $next->id);
    $next->delete();

    $this->actingAs($admin)
        ->get(route('admin.booking-instructions.edit', $row))
        ->assertOk()
        ->assertSee('All buyers (global)')
        ->assertSee('NEXT');
});

it('does not create a near-duplicate suggestion that differs only in punctuation or case', function () {
    instruction('Supplier must share the inspection report before shipment.', null, false);

    $class = new ReflectionClass(BookingController::class);
    $controller = $class->newInstanceWithoutConstructor();
    $save = $class->getMethod('saveCommonInstruction');
    $save->setAccessible(true);

    $save->invoke($controller, 'supplier must share the inspection report before shipment');

    expect(BookingInstruction::count())->toBe(1);
});
