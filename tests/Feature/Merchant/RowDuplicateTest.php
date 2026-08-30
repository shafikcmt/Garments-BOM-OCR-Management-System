<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Row duplication on the Excel Work Sheet.
 *
 * WHY THESE EXIST. The behaviour they cover cannot be reached from the browser
 * during development without signing in as a merchant, so the server contract
 * is pinned here instead: who may duplicate, what gets copied, and where the
 * copy lands.
 *
 * The rule that matters most is the LAST one. The worksheet is an EAV store —
 * every business value is a row in excel_cells with no unique index behind it —
 * so nothing in the schema stops a duplicate from copying, say, a contract
 * number. Which columns come through blank is a business decision held in
 * ExcelFileController::DUPLICATE_BLANK_HEADER_KEYS, and today that list is
 * deliberately empty: copy everything, let the merchant edit what differs.
 */
function worksheetUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

function worksheetFileWithRows(int $rowCount = 2): ExcelFile
{
    // ExcelFile has no factory; the rest of the suite builds it inline.
    $file = ExcelFile::create([
        'file_name' => 'sheet.xlsx',
        'original_file_name' => 'sheet.xlsx',
        'file_path' => 'sheet.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'total_rows' => $rowCount,
    ]);

    // owner_role_id is NOT NULL — it is what canEditHeader() reads to decide
    // which role owns a column.
    $ownerRole = Role::findOrCreate('merchant', 'web');

    // firstOrCreate, because header_key is UNIQUE — headers are column
    // definitions shared by every file, not per-file data. That index is
    // precisely the one this feature does NOT interact with: duplication copies
    // excel_cells.value, which has no uniqueness of any kind.
    $headers = collect(['buyer_name', 'style_name', 'initial_contract_number'])
        ->map(fn ($key, $i) => ExcelHeader::firstOrCreate(
            ['header_key' => $key],
            [
                'header_name' => ucfirst(str_replace('_', ' ', $key)),
                'owner_role_id' => $ownerRole->id,
                'position' => $i,
                'is_active' => true,
            ]
        ));

    foreach (range(1, $rowCount) as $number) {
        $row = ExcelRow::create([
            'excel_file_id' => $file->id,
            'row_number' => $number,
        ]);

        foreach ($headers as $header) {
            ExcelCell::create([
                'row_id' => $row->id,
                'header_id' => $header->id,
                'value' => $header->header_key . '-row' . $number,
            ]);
        }
    }

    return $file->fresh();
}

it('lets a merchant duplicate one row, values included', function () {
    $file = worksheetFileWithRows(2);
    $source = $file->rows()->orderBy('row_number')->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]])
        ->assertRedirect(route('uploaded-files.show', $file->id));

    expect($file->rows()->count())->toBe(3);

    // Found by LINEAGE, not by position. Copies are inserted below their source
    // now, so "the last row" is the shifted original, not the copy.
    $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

    expect($copy->row_number)->toBe(2);

    $sourceValues = $source->cells->pluck('value', 'header_id')->all();
    $copyValues = $copy->cells->pluck('value', 'header_id')->all();

    expect($copyValues)->toEqual($sourceValues);
});

it('copies the contract number too, because no constraint forbids it', function () {
    $file = worksheetFileWithRows(1);
    $source = $file->rows()->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]]);

    $contractHeader = ExcelHeader::where('header_key', 'initial_contract_number')->first();
    $copy = $file->rows()->orderByDesc('row_number')->first();

    $copied = $copy->cells->firstWhere('header_id', $contractHeader->id)->value;

    // The decision on record: duplicate with values, merchant edits what
    // differs. Add the key to DUPLICATE_BLANK_HEADER_KEYS to flip this.
    expect($copied)->toBe('initial_contract_number-row1');
});

it('duplicates several selected rows in one go, keeping their order', function () {
    $file = worksheetFileWithRows(3);
    $ids = $file->rows()->orderBy('row_number')->pluck('id')->all();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => $ids]);

    expect($file->rows()->count())->toBe(6);

    $copies = $file->rows()->where('row_number', '>', 3)->orderBy('row_number')->get();

    expect($copies)->toHaveCount(3);
    expect($copies->pluck('row_number')->all())->toEqual([4, 5, 6]);
});

it('refuses a role that cannot add rows', function () {
    $file = worksheetFileWithRows(1);
    $source = $file->rows()->first();

    $this->actingAs(worksheetUser('supply_chain'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]])
        ->assertForbidden();

    expect($file->rows()->count())->toBe(1);
});

it('allows an admin, matching who may already add a row', function () {
    $file = worksheetFileWithRows(1);
    $source = $file->rows()->first();

    $this->actingAs(worksheetUser('admin'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]])
        ->assertRedirect(route('uploaded-files.show', $file->id));

    expect($file->rows()->count())->toBe(2);
});

/**
 * Ordering. row_number is unique per file, so every one of these also asserts
 * that the renumbering never collided — a duplicate key would fail the request
 * outright.
 */
function rowOrder(ExcelFile $file): array
{
    return ExcelRow::where('excel_file_id', $file->id)
        ->orderBy('row_number')
        ->pluck('row_number')
        ->all();
}

it('inserts the copy directly below its source, not at the end', function () {
    $file = worksheetFileWithRows(5);
    $source = $file->rows()->where('row_number', 2)->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]]);

    // 1..6 with no gaps and no repeats.
    expect(rowOrder($file))->toEqual([1, 2, 3, 4, 5, 6]);

    $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

    expect($copy->row_number)->toBe(3);          // straight after row 2
    expect($source->fresh()->row_number)->toBe(2); // source did not move
});

it('shifts every following row down by one', function () {
    $file = worksheetFileWithRows(4);
    $rows = $file->rows()->orderBy('row_number')->get();
    $source = $rows[0];                       // row 1
    $lastBefore = $rows[3];                   // row 4

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]]);

    expect($lastBefore->fresh()->row_number)->toBe(5);
    expect(rowOrder($file))->toEqual([1, 2, 3, 4, 5]);
});

it('places each copy below its own source when several are duplicated', function () {
    $file = worksheetFileWithRows(5);
    $rows = $file->rows()->orderBy('row_number')->get()->keyBy('row_number');

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), [
            'row_ids' => [$rows[2]->id, $rows[4]->id],
        ]);

    expect(rowOrder($file))->toEqual([1, 2, 3, 4, 5, 6, 7]);

    // 1, 2, copy-of-2, 3, 4, copy-of-4, 5
    expect(ExcelRow::where('duplicated_from_row_id', $rows[2]->id)->first()->row_number)->toBe(3);
    expect($rows[3]->fresh()->row_number)->toBe(4);
    expect($rows[4]->fresh()->row_number)->toBe(5);
    expect(ExcelRow::where('duplicated_from_row_id', $rows[4]->id)->first()->row_number)->toBe(6);
    expect($rows[5]->fresh()->row_number)->toBe(7);
});

it('handles duplicating the very last row, where nothing shifts', function () {
    $file = worksheetFileWithRows(3);
    $last = $file->rows()->orderByDesc('row_number')->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$last->id]]);

    expect(rowOrder($file))->toEqual([1, 2, 3, 4]);
    expect(ExcelRow::where('duplicated_from_row_id', $last->id)->first()->row_number)->toBe(4);
});

it('handles duplicating the same row twice in one request', function () {
    $file = worksheetFileWithRows(3);
    $source = $file->rows()->where('row_number', 1)->first();

    // The same id twice — the query returns it once, so one copy, no collision.
    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), [
            'row_ids' => [$source->id, $source->id],
        ]);

    expect(rowOrder($file))->toEqual([1, 2, 3, 4]);
});

it('leaves another file untouched while renumbering this one', function () {
    $file = worksheetFileWithRows(3);
    $other = worksheetFileWithRows(3);
    $source = $file->rows()->where('row_number', 1)->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]]);

    // The shift is scoped by excel_file_id.
    expect(rowOrder($other))->toEqual([1, 2, 3]);
});

it('records lineage and the badge flag, so the marker survives a refresh', function () {
    $file = worksheetFileWithRows(1);
    $source = $file->rows()->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]]);

    // Re-read from the database, which is exactly what a refresh does.
    $copy = ExcelRow::where('excel_file_id', $file->id)->orderByDesc('row_number')->first();

    expect($copy->duplicated_from_row_id)->toBe($source->id);
    expect($copy->duplicated_at)->not->toBeNull();
    expect($copy->isFlaggedDuplicate())->toBeTrue();

    // And the source is not retroactively marked.
    expect($source->fresh()->duplicated_at)->toBeNull();
});

it('clears the badge but keeps the lineage when dismissed', function () {
    $file = worksheetFileWithRows(1);
    $source = $file->rows()->first();

    $merchant = worksheetUser('merchant');

    $this->actingAs($merchant)
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$source->id]]);

    $copy = ExcelRow::where('excel_file_id', $file->id)->orderByDesc('row_number')->first();

    $this->actingAs($merchant)
        ->patch(route('uploaded-files.rows.dismiss_duplicate', ['excelFile' => $file->id, 'row' => $copy->id]))
        ->assertRedirect(route('uploaded-files.show', $file->id));

    $copy->refresh();

    // The badge is gone...
    expect($copy->duplicated_at)->toBeNull();
    expect($copy->isFlaggedDuplicate())->toBeFalse();
    // ...but where it came from is still on record.
    expect($copy->duplicated_from_row_id)->toBe($source->id);
});

it('refuses to dismiss a badge on a row from another workbook', function () {
    $file = worksheetFileWithRows(1);
    $otherFile = worksheetFileWithRows(1);
    $foreignRow = $otherFile->rows()->first();

    $this->actingAs(worksheetUser('merchant'))
        ->patch(route('uploaded-files.rows.dismiss_duplicate', ['excelFile' => $file->id, 'row' => $foreignRow->id]))
        ->assertNotFound();
});

it('leaves rows that predate the feature unflagged', function () {
    // Rows created directly, as every existing row in the database was.
    $file = worksheetFileWithRows(2);

    foreach ($file->rows as $row) {
        expect($row->duplicated_at)->toBeNull();
        expect($row->duplicated_from_row_id)->toBeNull();
        expect($row->isFlaggedDuplicate())->toBeFalse();
    }
});

it('will not copy a row out of another workbook into this one', function () {
    $file = worksheetFileWithRows(1);
    $otherFile = worksheetFileWithRows(1);
    $foreignRow = $otherFile->rows()->first();

    $this->actingAs(worksheetUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate', $file->id), ['row_ids' => [$foreignRow->id]]);

    // Scoped by excel_file_id, so a hand-made id is simply not found.
    expect($file->rows()->count())->toBe(1);
    expect($otherFile->rows()->count())->toBe(1);
});
