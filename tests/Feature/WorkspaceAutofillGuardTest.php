<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Browser autofill must never reach a worksheet cell.
 *
 * A cell input's name is stable across page loads — cells[<row id>][<header id>]
 * is the same string every time that row is rendered — which is exactly the
 * signature a browser's form history keys on. Chrome was refilling empty cells
 * with a value somebody had once submitted for that same cell. The fill fires
 * `change`, which marks the sheet dirty and writes a draft; the next navigating
 * action then offers "you have unsaved changes, save first?" and an OK there
 * writes values nobody typed. The change log carries several such rows.
 *
 * The search and filter inputs on this screen already carried autocomplete="off".
 * The 1,600 cell inputs did not.
 *
 * These assertions pin the markup. Whether a given Chrome build honours the
 * attribute cannot be asserted from PHP — that is why the JS guard exists as a
 * second layer, and why the manual steps are in the commit message.
 */
beforeEach(function () {
    Role::findOrCreate('merchant', 'web');
    $this->user = User::factory()->create()->assignRole('merchant');

    $this->file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => $this->user->id,
        'buyer_id' => null,
        'total_rows' => 1,
    ]);

    // A text column and a number column: the attribute has to land on both
    // branches of the input-type switch, not just the default one.
    foreach ([['Merchant Remark', 'text', 1], ['Materials Ordered', 'number', 2]] as [$name, $type, $pos]) {
        ExcelHeader::create([
            'header_name' => $name,
            'header_key' => Str::snake($name),
            'owner_role_id' => Role::findOrCreate('merchant', 'web')->id,
            'position' => $pos,
            'field_type' => $type,
            'value_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    $row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 1]);

    ExcelCell::create([
        'row_id' => $row->id,
        'header_id' => ExcelHeader::where('header_name', 'Merchant Remark')->value('id'),
        'value' => 'keep me',
    ]);

    $this->page = $this->actingAs($this->user)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk()
        ->getContent();
});

/** Every rendered editable cell input, as raw tag text. */
function cellInputTags(string $page): array
{
    preg_match_all('/<input[^>]*excel-editable-cell[^>]*>/', $page, $matches);

    return $matches[0];
}

it('renders at least one editable cell to assert against', function () {
    expect(cellInputTags($this->page))->not->toBeEmpty();
});

it('turns autocomplete off on every editable cell input', function () {
    // Every one, not "some" — a single un-suppressed cell is a cell the browser
    // can quietly refill, and it will be a different one on each file.
    foreach (cellInputTags($this->page) as $tag) {
        expect($tag)->toContain('autocomplete="off"');
    }
});

it('turns autocomplete off on number cells as well as text cells', function () {
    $tags = cellInputTags($this->page);

    $number = array_filter($tags, fn ($t) => str_contains($t, 'type="number"'));
    $text = array_filter($tags, fn ($t) => str_contains($t, 'type="text"'));

    expect($number)->not->toBeEmpty()
        ->and($text)->not->toBeEmpty();

    foreach ([...$number, ...$text] as $tag) {
        expect($tag)->toContain('autocomplete="off"');
    }
});

it('opts every cell out of third-party password managers too', function () {
    // LastPass, Dashlane and 1Password ignore autocomplete outright and need
    // their own opt-out attributes.
    foreach (cellInputTags($this->page) as $tag) {
        expect($tag)->toContain('data-lpignore="true"')
            ->and($tag)->toContain('data-form-type="other"')
            ->and($tag)->toContain('data-1p-ignore');
    }
});

it('turns autocomplete off on the worksheet form itself', function () {
    // Covers any input added to this sheet later without one of its own.
    // Matched by id, because several hidden forms are rendered before this one.
    preg_match('/<form[^>]*id="excelFileForm"[^>]*>/', $this->page, $matches);

    expect($matches)->not->toBeEmpty()
        ->and($matches[0])->toContain('autocomplete="off"');
});

it('keeps the original value on each cell so the guard has something to compare', function () {
    // The JS net reverts anything differing from data-original-value before the
    // first user interaction. Without that attribute it has no baseline.
    foreach (cellInputTags($this->page) as $tag) {
        expect($tag)->toContain('data-original-value');
    }

    expect($this->page)->toContain('data-original-value="keep me"');
});

it('ships the pre-interaction revert guard', function () {
    expect($this->page)->toContain('function revertUntouchedAutofill()')
        ->toContain('userHasInteracted');
});

it('leaves the search and filter inputs suppressed as they already were', function () {
    // These were never the problem; this is a guard against a later edit
    // removing them while tidying the ones just added.
    expect(substr_count($this->page, 'autocomplete="off"'))->toBeGreaterThan(4);
});
