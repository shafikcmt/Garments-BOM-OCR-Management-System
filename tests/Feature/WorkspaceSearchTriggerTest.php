<?php

use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * When the worksheet search is allowed to fire.
 *
 * The column search boxes used to submit on a 700ms typing debounce. That
 * submit is a FULL PAGE NAVIGATION, not an in-place filter, so a normal
 * mid-word pause reloaded the sheet, every keystroke typed during the reload
 * was thrown away, and "bilaram" reached the server as "bilar". It also
 * discarded any unsaved cell edits on the way out.
 *
 * No debounce value fixes that, so search now fires only when the user says
 * they are done: Enter, or the Search button. These assertions read the
 * rendered page because the behaviour lives in its inline script — they are
 * here to stop a typing trigger being reintroduced, not to prove the script
 * runs.
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

    ExcelHeader::create([
        'header_name' => 'Style Name',
        'header_key' => Str::snake('Style Name'),
        'owner_role_id' => Role::findOrCreate('merchant', 'web')->id,
        'position' => 1,
        'field_type' => 'text',
        'value_mode' => 'manual',
        'is_active' => true,
    ]);

    ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 1]);

    $this->page = $this->actingAs($this->user)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk()
        ->getContent();
});

it('does not submit the sheet search while the user is typing', function () {
    expect($this->page)
        ->not->toContain("addEventListener('input', scheduleServerSheetSearch)")
        ->not->toContain('scheduleServerSheetSearch');
});

it('keeps no debounce timer that could fire the search on its own', function () {
    expect($this->page)->not->toContain('sheetSearchTimer');
});

it('still submits the search on Enter', function () {
    expect($this->page)
        ->toContain("if (event.key === 'Enter')")
        ->toContain('submitServerSheetSearch();');
});

it('still submits the search from the Search button', function () {
    expect($this->page)
        ->toContain('id="applySheetSearchButton"')
        ->toContain("applySheetSearchButton.addEventListener('click', submitServerSheetSearch)");
});

it('tells the user in the search boxes that Enter runs the search', function () {
    expect($this->page)
        ->toContain('placeholder="Search + Enter"')
        ->toContain('placeholder="Search entire document, then press Enter"');
});

/**
 * The other half of the save fix: the sheet must declare how many cell inputs
 * it is sending, and it must prune the untouched ones before it does.
 */
it('renders the cell count field before the sheet so truncation cannot eat it', function () {
    $countPos = strpos($this->page, 'name="cells_count"');
    $firstCellPos = strpos($this->page, 'name="cells[');

    expect($countPos)->not->toBeFalse()
        ->and($firstCellPos)->not->toBeFalse()
        ->and($countPos)->toBeLessThan($firstCellPos);
});

it('prunes unchanged cells from the payload on submit', function () {
    expect($this->page)
        ->toContain('function pruneUnchangedCells()')
        ->toContain('input.dataset.originalValue');
});
