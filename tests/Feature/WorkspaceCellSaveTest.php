<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Saving a cell on the Excel Worksheet.
 *
 * These exist because of a live data-loss bug: the sheet posted one form input
 * per editable cell, a normal 50-row page went past PHP's max_input_vars, PHP
 * silently discarded the tail of the POST, and the controller still answered
 * "File updated successfully". The user saw green and lost the edit.
 *
 * Two halves are guarded here. First, that an edit that reaches the server is
 * actually written to excel_cells — the plain persistence assertion that was
 * missing. Second, that a SHORT payload can never be reported as a success:
 * the form now declares how many cell inputs it sent, and a request carrying
 * fewer than it promised is refused outright.
 *
 * The truncation itself happens in PHP's request parser, below anything a test
 * request can reach, so it cannot be reproduced here. What is reproduced is
 * its signature — a payload smaller than declared — which is what the guard
 * keys on.
 */
function sheetUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

function sheetFile(User $uploader): ExcelFile
{
    return ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => $uploader->id,
        'buyer_id' => null,
        'total_rows' => 0,
    ]);
}

/** A plain editable column: owned by the role, and not a formula output. */
function sheetHeader(string $role, string $name, int $position = 1): ExcelHeader
{
    return ExcelHeader::create([
        'header_name' => $name,
        'header_key' => Str::snake($name),
        'owner_role_id' => Role::findOrCreate($role, 'web')->id,
        'position' => $position,
        'field_type' => 'text',
        'value_mode' => 'manual',
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->user = sheetUser('merchant');
    $this->file = sheetFile($this->user);
    $this->header = sheetHeader('merchant', 'Merchant Remark');
    $this->row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 1]);

    ExcelCell::create([
        'row_id' => $this->row->id,
        'header_id' => $this->header->id,
        'value' => 'old value',
    ]);
});

function saveCells(array $payload)
{
    return test()->actingAs(test()->user)->put(
        route('uploaded-files.update', test()->file->id),
        $payload
    );
}

// --- Bug 2: the edit must actually land in the database --------------------

it('persists an edited cell to the database', function () {
    saveCells(['cells' => [$this->row->id => [$this->header->id => 'new value']]]);

    // Read it back from storage, not from a model already in memory — the bug
    // was precisely that the screen and the database disagreed.
    expect(ExcelCell::where('row_id', $this->row->id)
        ->where('header_id', $this->header->id)
        ->value('value'))->toBe('new value');
});

it('reports success only when a cell was really written', function () {
    saveCells(['cells' => [$this->row->id => [$this->header->id => 'new value']]])
        ->assertSessionHas('success');
});

it('does not claim success when the save changed nothing', function () {
    // Same value as already stored: nothing to write. This used to flash
    // "File updated successfully", which is what hid the data loss.
    saveCells(['cells' => [$this->row->id => [$this->header->id => 'old value']]])
        ->assertSessionMissing('success')
        ->assertSessionHas('info');
});

it('writes every cell of a multi-row save', function () {
    $rows = collect(range(2, 12))->map(fn ($n) => ExcelRow::create([
        'excel_file_id' => $this->file->id,
        'row_number' => $n,
    ]));

    $cells = $rows->mapWithKeys(fn ($row) => [$row->id => [$this->header->id => "row {$row->row_number}"]]);

    saveCells(['cells' => $cells->all()]);

    // The old bug lost the TAIL of the payload, so assert the last row too,
    // not just that something was saved.
    $rows->each(function ($row) {
        expect(ExcelCell::where('row_id', $row->id)
            ->where('header_id', $this->header->id)
            ->value('value'))->toBe("row {$row->row_number}");
    });
});

// --- Bug 2: a truncated payload must never look like a success ------------

it('refuses a payload smaller than the form said it sent', function () {
    saveCells([
        'cells_count' => 40,   // the form intended to send 40 cells...
        'cells' => [$this->row->id => [$this->header->id => 'new value']], // ...only 1 arrived
    ])
        ->assertSessionMissing('success')
        ->assertSessionHas('error');
});

it('does not write anything from a truncated payload', function () {
    saveCells([
        'cells_count' => 40,
        'cells' => [$this->row->id => [$this->header->id => 'new value']],
    ]);

    expect(ExcelCell::where('row_id', $this->row->id)
        ->where('header_id', $this->header->id)
        ->value('value'))->toBe('old value');
});

it('accepts a payload that carries everything the form declared', function () {
    saveCells([
        'cells_count' => 1,
        'cells' => [$this->row->id => [$this->header->id => 'new value']],
    ])->assertSessionHas('success');

    expect(ExcelCell::where('row_id', $this->row->id)
        ->where('header_id', $this->header->id)
        ->value('value'))->toBe('new value');
});

it('still accepts a request that declares no count', function () {
    // Older clients, and every other caller of this route, send no
    // cells_count. The guard must not turn them into failures.
    saveCells(['cells' => [$this->row->id => [$this->header->id => 'new value']]])
        ->assertSessionHas('success');
});

// --- Bug 3: the status recount must stay correct after the keyBy rewrite ---
//
// refreshFileStatus() used to scan each row's whole cell collection once per
// target header — 23 seconds on a 499-row file. It now hashes each row's cells
// once. That is a pure speed change, so what is guarded here is that every
// status it can produce still comes out the same.

function statusAfterSave(User $user, ExcelFile $file, array $cells = []): string
{
    test()->actingAs($user)->put(route('uploaded-files.update', $file->id), ['cells' => $cells]);

    return $file->fresh()->status;
}

it('marks a file pending while no non-merchant cell is filled', function () {
    sheetHeader('store', 'Store Receipt Qty', 2);

    expect(statusAfterSave($this->user, $this->file))->toBe('pending');
});

it('marks a file processing while only some non-merchant cells are filled', function () {
    $storeHeader = sheetHeader('store', 'Store Receipt Qty', 2);
    $secondRow = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 2]);

    ExcelCell::create(['row_id' => $this->row->id, 'header_id' => $storeHeader->id, 'value' => '10']);
    // $secondRow's store cell is left empty, so the file is part-done.

    expect($secondRow)->not->toBeNull()
        ->and(statusAfterSave($this->user, $this->file))->toBe('processing');
});

it('marks a file completed once every non-merchant cell is filled', function () {
    $storeHeader = sheetHeader('store', 'Store Receipt Qty', 2);

    ExcelCell::create(['row_id' => $this->row->id, 'header_id' => $storeHeader->id, 'value' => '10']);

    expect(statusAfterSave($this->user, $this->file))->toBe('completed');
});

it('counts a whitespace-only cell as empty, as it always did', function () {
    $storeHeader = sheetHeader('store', 'Store Receipt Qty', 2);

    ExcelCell::create(['row_id' => $this->row->id, 'header_id' => $storeHeader->id, 'value' => '   ']);

    expect(statusAfterSave($this->user, $this->file))->toBe('pending');
});
