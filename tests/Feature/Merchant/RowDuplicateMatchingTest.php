<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * "Duplicate All Matching Rows" — duplicating a whole style as one block.
 *
 * WHY THESE EXIST, separately from RowDuplicateTest. This action is the only
 * duplication path that can touch rows the merchant cannot see on screen, and
 * the only one whose scope is decided by the SERVER from three filter values
 * rather than by an explicit list of row ids. Both of those are safety
 * properties, and neither is visible from the browser during ordinary
 * development, so they are pinned here.
 *
 * The three rules under test:
 *
 *   1. EXACT match, not the substring match the column filter uses. Typing
 *      "ABC" must never sweep in "ABC-2" — over-matching here silently
 *      duplicates another style's work.
 *   2. BLOCK placement, after the LAST row of the matched group, so a style
 *      reads as originals-then-copies rather than alternating lines.
 *   3. ALL THREE filters required. Two of three is how a style group quietly
 *      grows to include another season's rows.
 */
function styleMatchUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

/**
 * The three style columns plus a payload column, created once and shared.
 *
 * header_key is UNIQUE — headers are column definitions shared by every file,
 * not per-file data — so firstOrCreate, exactly as the sibling test does.
 *
 * @return Collection<string, ExcelHeader>
 */
function styleMatchHeaders(): Collection
{
    $ownerRole = Role::findOrCreate('merchant', 'web');

    return collect(['buyer_name', 'style_name', 'season_name', 'initial_contract_number'])
        ->mapWithKeys(fn ($key, $i) => [$key => ExcelHeader::firstOrCreate(
            ['header_key' => $key],
            [
                'header_name' => ucfirst(str_replace('_', ' ', $key)),
                'owner_role_id' => $ownerRole->id,
                'position' => $i,
                'is_active' => true,
            ]
        )]);
}

/**
 * A file whose rows are given explicit style values, in the order listed.
 *
 * $rows is a list of [buyer, style, season]; row_number follows the array
 * order, so a test can place a non-matching row between two matching ones and
 * assert what block placement does with it.
 */
function styleMatchFile(array $rows): ExcelFile
{
    // ExcelFile has no factory; the rest of the suite builds it inline.
    $file = ExcelFile::create([
        'file_name' => 'sheet.xlsx',
        'original_file_name' => 'sheet.xlsx',
        'file_path' => 'sheet.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'total_rows' => count($rows),
    ]);

    $headers = styleMatchHeaders();

    foreach ($rows as $index => [$buyer, $style, $season]) {
        $row = ExcelRow::create([
            'excel_file_id' => $file->id,
            'row_number' => $index + 1,
        ]);

        $values = [
            'buyer_name' => $buyer,
            'style_name' => $style,
            'season_name' => $season,
            'initial_contract_number' => 'contract-'.($index + 1),
        ];

        foreach ($values as $key => $value) {
            ExcelCell::create([
                'row_id' => $row->id,
                'header_id' => $headers[$key]->id,
                'value' => $value,
            ]);
        }
    }

    return $file->fresh();
}

/**
 * The request payload as the page builds it: filters keyed by HEADER ID, never
 * row ids. Building it the same way here is the point — if the server ever
 * started trusting a client row list, these tests would have to change to match.
 */
function styleMatchFilters(array $values): array
{
    $headers = styleMatchHeaders();

    $filters = [];

    foreach ($values as $key => $value) {
        $filters[$headers[$key]->id] = $value;
    }

    return ['filters' => $filters];
}

function styleMatchRowOrder(ExcelFile $file): array
{
    return ExcelRow::where('excel_file_id', $file->id)
        ->orderBy('row_number')
        ->pluck('row_number')
        ->all();
}

/** The buyer/style/season triple a row currently holds, for readable assertions. */
function styleMatchTripleAt(ExcelFile $file, int $rowNumber): array
{
    $headers = styleMatchHeaders();

    $row = ExcelRow::where('excel_file_id', $file->id)
        ->where('row_number', $rowNumber)
        ->firstOrFail();

    $cells = $row->cells->pluck('value', 'header_id');

    return [
        $cells[$headers['buyer_name']->id],
        $cells[$headers['style_name']->id],
        $cells[$headers['season_name']->id],
    ];
}

/*
 * 1. EXACT-MATCH RESOLUTION — the prefix-collision case.
 */

it('matches the style exactly and leaves a longer name that merely starts the same alone', function () {
    // "ABC" is a prefix of "ABC-2". The ordinary column filter would show both;
    // this action must copy only the exact ones.
    $file = styleMatchFile([
        ['Zara', 'ABC', 'Summer'],     // 1 — matches
        ['Zara', 'ABC-2', 'Summer'],   // 2 — prefix collision, must NOT match
        ['Zara', 'ABC', 'Summer'],     // 3 — matches
    ]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        )
        ->assertRedirect(route('uploaded-files.show', $file->id));

    // Two copies, not three: "ABC-2" was not swept in.
    expect($file->rows()->count())->toBe(5);
    expect(ExcelRow::where('excel_file_id', $file->id)->whereNotNull('duplicated_from_row_id')->count())->toBe(2);

    $abc2 = ExcelRow::where('excel_file_id', $file->id)->where('row_number', 2)->first();

    expect(ExcelRow::where('duplicated_from_row_id', $abc2->id)->count())->toBe(0);
});

it('will not match a different season even when buyer and style agree', function () {
    $file = styleMatchFile([
        ['Zara', 'ABC', 'Summer'],
        ['Zara', 'ABC', 'Winter'],
    ]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        );

    expect($file->rows()->count())->toBe(3);
    expect(styleMatchTripleAt($file, 2))->toEqual(['Zara', 'ABC', 'Summer']); // the copy
    expect(styleMatchTripleAt($file, 3))->toEqual(['Zara', 'ABC', 'Winter']); // pushed down, untouched
});

it('warns and copies nothing when no row matches all three exactly', function () {
    $file = styleMatchFile([
        ['Zara', 'ABC-2', 'Summer'],
    ]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        )
        ->assertRedirect(route('uploaded-files.show', $file->id))
        ->assertSessionHas('warning');

    expect($file->rows()->count())->toBe(1);
});

it('resolves the rows itself and ignores any row ids the request tries to add', function () {
    $file = styleMatchFile([
        ['Zara', 'ABC', 'Summer'],
        ['H&M', 'XYZ', 'Winter'],   // another style's row, named explicitly
    ]);

    $foreign = ExcelRow::where('excel_file_id', $file->id)->where('row_number', 2)->first();

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
                + ['row_ids' => [$foreign->id]]
        );

    // One copy — of the matched row only. The row_ids in the payload did nothing.
    expect($file->rows()->count())->toBe(3);
    expect(ExcelRow::where('duplicated_from_row_id', $foreign->id)->count())->toBe(0);
});

it('does not reach into another workbook that holds the same style', function () {
    $file = styleMatchFile([['Zara', 'ABC', 'Summer']]);
    $other = styleMatchFile([['Zara', 'ABC', 'Summer']]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        );

    expect($file->rows()->count())->toBe(2);
    expect($other->rows()->count())->toBe(1);
});

/*
 * 2. BLOCK PLACEMENT — all copies together, after the LAST matched row.
 */

it('inserts the copies as one block after the last matched row, not below each source', function () {
    // Matches at 1 and 3, with a non-matching row wedged between them at 2.
    // Block placement puts the copies at 4 and 5 — after row 3 — and NOT
    // interleaved at 2 and 5 the way duplicateRows() would place them.
    $file = styleMatchFile([
        ['Zara', 'ABC', 'Summer'],   // 1 — matches
        ['H&M', 'XYZ', 'Winter'],    // 2 — does not match, stays put
        ['Zara', 'ABC', 'Summer'],   // 3 — matches, the LAST of the group
        ['H&M', 'QRS', 'Winter'],    // 4 — tail, must shift to 6
    ]);

    $rows = ExcelRow::where('excel_file_id', $file->id)->orderBy('row_number')->get();

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        );

    // 1..6, no gaps and no repeats — which also proves the renumbering never
    // collided with the unique index on (excel_file_id, row_number).
    expect(styleMatchRowOrder($file))->toEqual([1, 2, 3, 4, 5, 6]);

    // The originals did not move.
    expect($rows[0]->fresh()->row_number)->toBe(1);
    expect($rows[1]->fresh()->row_number)->toBe(2);
    expect($rows[2]->fresh()->row_number)->toBe(3);

    // The copies landed together, straight after row 3, in source order.
    expect(ExcelRow::where('duplicated_from_row_id', $rows[0]->id)->first()->row_number)->toBe(4);
    expect(ExcelRow::where('duplicated_from_row_id', $rows[2]->id)->first()->row_number)->toBe(5);

    // The tail shifted down by the size of the block.
    expect($rows[3]->fresh()->row_number)->toBe(6);
    expect(styleMatchTripleAt($file, 6))->toEqual(['H&M', 'QRS', 'Winter']);
});

it('copies values and records lineage on every row of the block', function () {
    $file = styleMatchFile([
        ['Zara', 'ABC', 'Summer'],
        ['Zara', 'ABC', 'Summer'],
    ]);

    $sources = ExcelRow::where('excel_file_id', $file->id)->orderBy('row_number')->get();

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        );

    foreach ($sources as $source) {
        $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

        expect($copy)->not->toBeNull();
        expect($copy->duplicated_at)->not->toBeNull();
        expect($copy->isFlaggedDuplicate())->toBeTrue();
        expect($copy->cells->pluck('value', 'header_id')->all())
            ->toEqual($source->cells->pluck('value', 'header_id')->all());
    }
});

it('appends the block at the end when the group is already last', function () {
    $file = styleMatchFile([
        ['H&M', 'XYZ', 'Winter'],
        ['Zara', 'ABC', 'Summer'],
    ]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        );

    expect(styleMatchRowOrder($file))->toEqual([1, 2, 3]);
    expect(styleMatchTripleAt($file, 3))->toEqual(['Zara', 'ABC', 'Summer']);
});

/*
 * 3. THE ALL-THREE-FILTERS GATE.
 */

it('refuses when only two of the three style filters are set', function () {
    $file = styleMatchFile([
        ['Zara', 'ABC', 'Summer'],
        ['Zara', 'ABC', 'Winter'],
    ]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC'])
        )
        ->assertRedirect(route('uploaded-files.show', $file->id))
        ->assertSessionHas('warning');

    // Without the gate both seasons would have been duplicated.
    expect($file->rows()->count())->toBe(2);
});

it('treats a whitespace-only filter as missing', function () {
    $file = styleMatchFile([['Zara', 'ABC', 'Summer']]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => '   '])
        )
        ->assertSessionHas('warning');

    expect($file->rows()->count())->toBe(1);
});

it('refuses when no filters are sent at all', function () {
    $file = styleMatchFile([['Zara', 'ABC', 'Summer']]);

    $this->actingAs(styleMatchUser('merchant'))
        ->post(route('uploaded-files.rows.duplicate_matching', $file->id))
        ->assertRedirect(route('uploaded-files.show', $file->id))
        ->assertSessionHas('warning');

    expect($file->rows()->count())->toBe(1);
});

it('ignores unrelated columns and still requires the three style ones', function () {
    $file = styleMatchFile([['Zara', 'ABC', 'Summer']]);

    // Three filters, but one of them is the contract column — not a style key.
    $this->actingAs(styleMatchUser('merchant'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters([
                'buyer_name' => 'Zara',
                'style_name' => 'ABC',
                'initial_contract_number' => 'contract-1',
            ])
        )
        ->assertSessionHas('warning');

    expect($file->rows()->count())->toBe(1);
});

/*
 * The gate is shared with duplicateRows(), so who may press this is pinned too.
 */

it('refuses a role that cannot add rows', function () {
    $file = styleMatchFile([['Zara', 'ABC', 'Summer']]);

    $this->actingAs(styleMatchUser('supply_chain'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        )
        ->assertForbidden();

    expect($file->rows()->count())->toBe(1);
});

it('allows an admin, matching who may already duplicate a row', function () {
    $file = styleMatchFile([['Zara', 'ABC', 'Summer']]);

    $this->actingAs(styleMatchUser('admin'))
        ->post(
            route('uploaded-files.rows.duplicate_matching', $file->id),
            styleMatchFilters(['buyer_name' => 'Zara', 'style_name' => 'ABC', 'season_name' => 'Summer'])
        )
        ->assertRedirect(route('uploaded-files.show', $file->id));

    expect($file->rows()->count())->toBe(2);
});
