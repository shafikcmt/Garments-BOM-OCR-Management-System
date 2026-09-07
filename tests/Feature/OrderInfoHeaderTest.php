<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * The Order Information header boxes.
 *
 * Every box used to read the FIRST ROW and present that value as if it
 * described the whole file. On the real FA-27 outerwear file (499 rows) the
 * header announced style "Anetinna-1" and contract "4800095665" — each true of
 * 31 rows, 6% of the file. Buyer Name and Season Name were genuinely one value
 * throughout, so the fix is not "convert every box" but "let each box describe
 * its own field": one distinct value keeps the plain box, more than one gets a
 * count and a filter.
 *
 * The Contract Number box carried a second bug. It looked up a header named
 * "Contract Number", which does not exist in the database — the real column is
 * "Initial Contract Number" — so the box could never fill and always showed a
 * dash. Fixing that lookup ALONE would have made the screen worse: the box
 * would have stopped saying "unknown" and started confidently naming a value
 * true of 6% of rows. That is why the two changes ship together, and why the
 * last group here pins the pairing.
 */
function oiUser(string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

function oiHeader(string $name, int $position, string $role = 'merchant'): ExcelHeader
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

/**
 * @param  array<string, array<int, string>>  $columns  header name => value per row
 */
function oiFile(array $columns): ExcelFile
{
    $user = User::factory()->create();

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => $user->id,
        'buyer_id' => null,
    ]);

    $position = 1;
    $headers = [];

    foreach ($columns as $name => $values) {
        $headers[$name] = oiHeader($name, $position++);
    }

    $rowCount = max(array_map('count', $columns ?: [[]]));

    for ($i = 0; $i < $rowCount; $i++) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);

        foreach ($columns as $name => $values) {
            ExcelCell::create([
                'row_id' => $row->id,
                'header_id' => $headers[$name]->id,
                'value' => $values[$i] ?? '',
            ]);
        }
    }

    return $file;
}

function oiOrderInfo(ExcelFile $file, ?User $user = null): array
{
    $response = test()->actingAs($user ?: oiUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk();

    return $response->viewData('orderInfo');
}

// --- A field that really is one value keeps its plain box -------------------

it('leaves a uniform field as a static value with no filter', function () {
    $file = oiFile([
        'Buyer Name' => ['HUGO BOSS', 'HUGO BOSS', 'HUGO BOSS'],
    ]);

    $box = oiOrderInfo($file)['Buyer Name'];

    expect($box['distinct'])->toBe(1)
        ->and($box['value'])->toBe('HUGO BOSS')
        // No header id is the signal the view reads to render a static box.
        ->and($box['header_id'])->toBeNull()
        ->and($box['options'])->toBe([]);
});

it('leaves an entirely empty field static rather than offering an empty dropdown', function () {
    // Contract Shipment Date is unpopulated on every real file we have. That is
    // not "uniform", it is absent — and an empty filter control is worse than a
    // dash, because it looks broken.
    $file = oiFile([
        'Buyer Name' => ['HUGO BOSS', 'HUGO BOSS'],
        'Contract Shipment Date' => ['', ''],
    ]);

    $box = oiOrderInfo($file)['Contract Shipment Date'];

    expect($box['distinct'])->toBe(0)
        ->and($box['header_id'])->toBeNull()
        ->and($box['options'])->toBe([]);
});

// --- A varied field is described by a count, not by row one ----------------

it('describes a varied field by its distinct count with per-value row counts', function () {
    $file = oiFile([
        'Style Name' => ['Anetinna-1', 'Orevo', 'Orevo', 'Bijo2731', 'Orevo'],
    ]);

    $box = oiOrderInfo($file)['Style Name'];

    expect($box['distinct'])->toBe(3)
        ->and($box['noun'])->toBe('styles')
        ->and($box['header_id'])->not->toBeNull();

    // Ordered by how much of the file each value accounts for, so the value
    // that describes most rows is offered first.
    expect($box['options'])->toBe([
        ['value' => 'Orevo', 'count' => 3],
        ['value' => 'Anetinna-1', 'count' => 1],
        ['value' => 'Bijo2731', 'count' => 1],
    ]);
});

it('counts blank rows as no value rather than as a value of their own', function () {
    // The real file leaves 173 of 499 rows blank in these columns. A file with
    // three styles has three styles, not four.
    $file = oiFile([
        'Style Name' => ['Orevo', '', 'Bijo2731', '', 'Anetinna-1'],
    ]);

    $box = oiOrderInfo($file)['Style Name'];

    expect($box['distinct'])->toBe(3)
        ->and(collect($box['options'])->pluck('value')->all())
        ->not->toContain('');
});

it('renders the count and the filter dropdown for a varied field', function () {
    $file = oiFile([
        'Style Name' => ['Orevo', 'Orevo', 'Bijo2731'],
    ]);

    $page = test()->actingAs(oiUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    // The figure and its noun are separate elements so the figure can carry the
    // visual weight, so they are asserted separately rather than as one string.
    expect($page)->toContain('>2</span>')
        ->toContain('order-info-count-noun">styles<')
        ->toContain('data-order-info-filter')
        // The per-value counts reach the dropdown as data, for the badge...
        ->toContain('data-order-info-counts')
        // ...and stay in the option label too, which is what a browser shows if
        // Tom Select fails to load and the plain <select> is drawn instead.
        ->toContain('Orevo (2)');
});

// --- The dropdown may only appear where the filter will actually work ------

it('withholds the dropdown when the column is hidden from this role', function () {
    // show() applies filters[] for VISIBLE headers only, so offering a dropdown
    // for a column this role cannot see would be a control that silently does
    // nothing.
    $file = oiFile([
        'Style Name' => ['Orevo', 'Bijo2731'],
    ]);

    // Columns are readable by every role unless can_view_all is off, so the
    // fixture has to actually close it — a merchant-owned column is otherwise
    // still visible to Store.
    ExcelHeader::where('header_name', 'Style Name')->update(['can_view_all' => false]);

    $box = oiOrderInfo($file, oiUser('store'))['Style Name'];

    expect($box['distinct'])->toBe(2)
        ->and($box['header_id'])->toBeNull()
        ->and($box['options'])->toBe([]);
});

// --- The Contract Number lookup, and why it ships with the count ----------

it('reads Contract Number from the Initial Contract Number column', function () {
    // The bug: the box looked up "Contract Number", a header that does not
    // exist, so it always showed a dash however much data the file held.
    $file = oiFile([
        'Initial Contract Number' => ['4800095661', '4800095661', '4800095665'],
    ]);

    $box = oiOrderInfo($file)['Contract Number'];

    expect($box['distinct'])->toBe(2)
        ->and($box['noun'])->toBe('contract numbers')
        ->and($box['options'])->toBe([
            ['value' => '4800095661', 'count' => 2],
            ['value' => '4800095665', 'count' => 1],
        ]);
});

it('still reads a file that uses the older Contract Number column name', function () {
    $file = oiFile([
        'Contract Number' => ['CN-1', 'CN-1'],
    ]);

    expect(oiOrderInfo($file)['Contract Number']['value'])->toBe('CN-1');
});

it('does not name one contract number as if it described the file', function () {
    // The pairing this whole change exists for. With the lookup fixed but the
    // count absent, this box would read "4800095665" — one row in three.
    $file = oiFile([
        'Initial Contract Number' => ['4800095661', '4800095663', '4800095665'],
    ]);

    $page = test()->actingAs(oiUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    expect($page)->toContain('>3</span>')
        ->toContain('order-info-count-noun">contract numbers<');

    // The label users know is kept; the underlying column is the real one.
    expect($page)->toContain('Contract Number');
});

// --- The filter reuses the existing mechanism ------------------------------

it('preselects the dropdown from the filter already applied', function () {
    $file = oiFile([
        'Style Name' => ['Orevo', 'Bijo2731'],
    ]);

    $headerId = ExcelHeader::where('header_name', 'Style Name')->value('id');

    $box = test()->actingAs(oiUser())
        ->get(route('uploaded-files.show', $file->id).'?filters['.$headerId.']=Orevo')
        ->assertOk()
        ->viewData('orderInfo')['Style Name'];

    expect($box['filter_value'])->toBe('Orevo')
        ->and($box['header_id'])->toBe($headerId);
});

it('routes the dropdown through the existing filters[] query and the unsaved guard', function () {
    $file = oiFile([
        'Style Name' => ['Orevo', 'Bijo2731'],
    ]);

    $page = test()->actingAs(oiUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    // Not a new filtering mechanism — the same query key the column search
    // boxes post, so one dropdown choice sits on top of any column filter.
    expect($page)->toContain("'filters[' + headerId + ']'")
        // And a reload discards unsaved cell edits, so it takes the same guard
        // every other navigating control on this page takes.
        ->toContain('function applyOrderInfoFilter')
        ->toContain('if (!passesUnsavedGuard()) {');
});
