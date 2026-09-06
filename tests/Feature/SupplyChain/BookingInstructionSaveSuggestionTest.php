<?php

use App\Http\Controllers\SupplyChain\BookingController;
use App\Models\BookingInstruction;
use App\Models\BookingPo;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\User;

/**
 * "Save as suggestion" on a booking instruction line.
 *
 * The PO Preview used to offer two ways to add an instruction: a blank row in
 * Current Instructions, and a separate "New Instruction" box below it with its
 * own save-as-suggestion checkbox. They did the same thing, except the box
 * could only ever carry ONE line, and only the box could promote its text into
 * the shared library. Two controls, one job, different powers — so the box was
 * removed and the checkbox moved onto the row.
 *
 * That turned `notes[]` into keyed `notes[i]`, paired with `save_suggestion[i]`.
 * The pairing is the whole risk: the index is what decides WHICH line gets
 * saved to the library, so an off-by-one here quietly publishes the wrong text
 * to every future booking. These assertions pin the pairing.
 */
function saveSuggestionController(): array
{
    $class = new ReflectionClass(BookingController::class);
    $controller = $class->newInstanceWithoutConstructor();

    $apply = $class->getMethod('applyBookingEditDataToPo');
    $apply->setAccessible(true);

    return [$controller, $apply];
}

function saveSuggestionPo(): BookingPo
{
    $user = User::factory()->create();

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => $user->id,
        'total_rows' => 1,
    ]);

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    return BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'po_no' => 'PO-1',
        'created_by' => $user->id,
    ]);
}

/** Run the edit exactly as the preview form submits it. */
function applyBookingNotes(array $notes, array $saveFlags = []): BookingPo
{
    [$controller, $apply] = saveSuggestionController();

    $po = saveSuggestionPo();

    $apply->invoke($controller, $po, [
        'notes' => $notes,
        'save_suggestion' => $saveFlags,
    ], null);

    return $po;
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    // The suite seeds a starter set of instructions, so every assertion below
    // is about what THIS edit added, not about the table's absolute size.
    $this->existingInstructionIds = BookingInstruction::pluck('id')->all();
});

/** Instruction texts created by the edit under test, in creation order. */
function newlySavedInstructions(): array
{
    return BookingInstruction::whereNotIn('id', test()->existingInstructionIds)
        ->orderBy('id')
        ->pluck('instruction')
        ->all();
}

it('keeps a typed instruction on the PO without saving it to the library', function () {
    applyBookingNotes([0 => 'One-off packing note for this PO']);

    expect(newlySavedInstructions())->toBe([]);
});

it('saves a ticked line to the shared library', function () {
    applyBookingNotes(
        [0 => 'Always ship with the carton list on top'],
        [0 => '1']
    );

    expect(newlySavedInstructions())->toBe(['Always ship with the carton list on top']);
});

it('saves the ticked line and not its neighbours', function () {
    // The pairing test. Three lines, only the middle one ticked.
    applyBookingNotes(
        [0 => 'First line', 1 => 'Second line', 2 => 'Third line'],
        [1 => '1']
    );

    expect(newlySavedInstructions())->toBe(['Second line']);
});

it('saves more than one line, which the old single New Instruction box could not', function () {
    applyBookingNotes(
        [0 => 'First line', 1 => 'Second line', 2 => 'Third line'],
        [0 => '1', 2 => '1']
    );

    expect(newlySavedInstructions())->toBe(['First line', 'Third line']);
});

it('does not duplicate a line that is already in the library', function () {
    BookingInstruction::create([
        'instruction' => 'Always ship with the carton list on top',
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 10,
    ]);

    // This one is part of the "before" picture, not something the edit added.
    $this->existingInstructionIds = BookingInstruction::pluck('id')->all();

    // Same wording, different spacing and case — normalise() should match it.
    applyBookingNotes(
        [0 => 'ALWAYS SHIP  with the carton list on top'],
        [0 => '1']
    );

    expect(newlySavedInstructions())->toBe([]);
});

it('ignores a tick whose line was left blank', function () {
    applyBookingNotes([0 => '   '], [0 => '1']);

    expect(newlySavedInstructions())->toBe([]);
});

it('writes every typed line onto the PO notes', function () {
    $po = applyBookingNotes([0 => 'First line', 1 => 'Second line']);

    expect($po->fresh()->booking_data['notes'] ?? [])
        ->toContain('First line')
        ->toContain('Second line');
});
