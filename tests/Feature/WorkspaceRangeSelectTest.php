<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * The DOM contract that sheet-range-select.js is built on.
 *
 * The selection module deliberately owns no markup. It reads the coordinates
 * and the values the Blade already renders, which is what lets it exist
 * without a grid library and without changing how a single cell is drawn. The
 * flip side is that a Blade change could silently break it: drop a
 * data-col-index and the range maths quietly stops finding cells, drop a
 * data-cell-value and copied blocks come back with blank formula columns. In
 * both cases the sheet still looks perfectly correct.
 *
 * So what is asserted here is the contract, not the behaviour. The gesture
 * itself — mousedown, drag, Shift+click, Ctrl+C — is browser-only and there is
 * no JS test runner in this project; adding one for this is not worth a new
 * dependency. That half is verified by hand, and the steps are in the commit.
 *
 * The last group matters most: this phase is READ-ONLY, and these pin the
 * things it must not have disturbed.
 */
function rangeSelectUser(string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

function rangeSelectHeader(string $role, string $name, int $position, string $valueMode = 'manual'): ExcelHeader
{
    return ExcelHeader::create([
        'header_name' => $name,
        'header_key' => Str::snake($name),
        'owner_role_id' => Role::findOrCreate($role, 'web')->id,
        'position' => $position,
        'field_type' => 'text',
        'value_mode' => $valueMode,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->user = rangeSelectUser();

    $this->file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => $this->user->id,
        'buyer_id' => null,
        'total_rows' => 2,
    ]);

    // One column this merchant owns, and one owned by Store — so the page has
    // both an editable <input> cell and a read-only display cell, which are the
    // two shapes the copy path has to read.
    $this->ownColumn = rangeSelectHeader('merchant', 'Merchant Remark', 1);
    $this->otherColumn = rangeSelectHeader('store', 'Store Receipt Qty', 2);

    foreach ([1, 2] as $number) {
        $row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => $number]);

        ExcelCell::create(['row_id' => $row->id, 'header_id' => $this->ownColumn->id, 'value' => "own {$number}"]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $this->otherColumn->id, 'value' => "other {$number}"]);
    }

    $this->page = $this->actingAs($this->user)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk()
        ->getContent();
});

// --- The opt-in ------------------------------------------------------------

it('opts the sheet into range selection', function () {
    expect($this->page)->toContain('data-cell-select');
});

it('renders the status chip the module fills in, hidden until something is selected', function () {
    expect($this->page)->toContain('data-cell-select-status');
});

// --- The coordinate system -------------------------------------------------

it('gives every data cell both coordinates', function () {
    // A cell carrying one index but not the other would be skipped by the
    // module's selector and leave a hole in the middle of a dragged range.
    preg_match_all('/<td[^>]*>/', $this->page, $matches);

    $withRow = array_filter($matches[0], fn ($td) => str_contains($td, 'data-row-index'));
    $withCol = array_filter($matches[0], fn ($td) => str_contains($td, 'data-col-index'));

    expect($withRow)->not->toBeEmpty()
        ->and(count($withRow))->toBe(count($withCol));
});

it('numbers cells from zero on both axes', function () {
    // The module treats the indices as a dense grid it can walk between two
    // corners, so the origin has to actually exist.
    expect($this->page)->toContain('data-row-index="0"')
        ->and($this->page)->toContain('data-col-index="0"');
});

it('leaves the row-number column outside the grid', function () {
    // That first <td> holds the duplicate-row checkbox. It carries no
    // coordinates, which is exactly why a drag can never sweep it up and
    // start ticking rows for duplication.
    expect($this->page)->toContain('class="form-check-input row-select"');

    $firstCell = Str::before(Str::after($this->page, '<tr data-row-id='), '</td>');

    expect($firstCell)->not->toContain('data-col-index');
});

// --- What the copy path reads ---------------------------------------------

it('exposes an editable cell value on its input', function () {
    expect($this->page)->toContain('class="form-control form-control-sm excel-editable-cell"')
        ->and($this->page)->toContain('value="own 1"');
});

it('exposes a read-only cell value as a data attribute', function () {
    // Store's column is read-only for this merchant. Without data-cell-value a
    // copied block would come back with that column blank, which is worse than
    // refusing to copy it at all.
    expect($this->page)->toContain('data-cell-value="other 1"');
});

// --- Phase 1a is read-only: none of this may have moved --------------------

it('leaves single-cell editing intact', function () {
    expect($this->page)->toContain('excel-editable-cell')
        ->and($this->page)->toContain('data-original-value');
});

it('leaves the unchanged-cell pruning of the save fix in place', function () {
    expect($this->page)->toContain('function pruneUnchangedCells()')
        ->toContain('name="cells_count"');
});

it('leaves the existing external Excel paste handler in place', function () {
    expect($this->page)->toContain('function handleMultiPaste(')
        ->toContain("addEventListener('paste'");
});

it('leaves arrow-key navigation in place', function () {
    expect($this->page)->toContain('function handleArrowNavigation(')
        ->toContain('function focusEditableCell(');
});

it('leaves the draft restore system in place', function () {
    expect($this->page)->toContain('function markWorksheetChanged()')
        ->toContain('draftRestoreModal');
});

it('leaves the duplicate-row selection in place', function () {
    expect($this->page)->toContain('id="rowSelectAll"')
        ->toContain('class="form-check-input row-select"');
});
