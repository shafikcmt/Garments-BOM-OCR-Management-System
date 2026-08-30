<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * A locked file must be un-mutable through EVERY entry point, not only the
 * ones a button is currently rendered for.
 *
 * WHY THIS FILE EXISTS. Deletion used to gate on ROLE ALONE. Every other
 * mutation on a worksheet — save, add row, duplicate, duplicate-matching, add
 * order — already refused both an admin lock and another buyer's file, but
 * destroy() refused neither, so the screen showed the read-only "belongs to
 * another buyer" banner and an active red Delete button at the same time, and
 * the button worked. The single irreversible action was the unguarded one.
 * dismissDuplicateFlag() had the same shape of hole, one severity down.
 *
 * So this file walks the whole surface rather than testing the one bug: each
 * mutating route is asserted against a merchant confined to ANOTHER buyer, and
 * the assertions check the DATA, not just the status code — a guard that
 * returns a warning while still writing would pass a status-only test.
 *
 * The happy path (the file's own buyer team can still do all of this) is
 * asserted too. A lock that refuses everyone is not a fix.
 */
function lfUser(string $role, array $buyerIds = []): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create([
        'buyer_scope_mode' => $buyerIds === [] ? BuyerScope::MODE_ALL : BuyerScope::MODE_SPECIFIC,
        'buyer_id' => $buyerIds[0] ?? null,
    ])->assignRole($role);

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

function lfFile(?int $buyerId): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);

    $ownerRole = Role::findOrCreate('merchant', 'web');

    $position = 0;

    $headers = collect(['buyer_name', 'style_name', 'season_name'])
        ->map(fn ($key) => ExcelHeader::firstOrCreate(
            ['header_key' => $key],
            [
                'header_name' => ucwords(str_replace('_', ' ', $key)),
                'owner_role_id' => $ownerRole->id,
                'position' => $position++,
                'is_active' => true,
            ]
        ));

    $row = ExcelRow::create([
        'excel_file_id' => $file->id,
        'row_number' => 1,
        'duplicated_at' => now(),
    ]);

    foreach ($headers as $header) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => $header->id,
            'value' => 'Zara',
        ]);
    }

    return $file->fresh();
}

beforeEach(function () {
    Role::findOrCreate('merchant', 'web');

    $this->macys = Buyer::create(['buyer_name' => 'MACYS', 'is_active' => true]);
    $this->annTaylor = Buyer::create(['buyer_name' => 'ANNTAYLOR', 'is_active' => true]);

    // The file belongs to ANNTAYLOR; the actor holds MACYS only.
    $this->file = lfFile($this->annTaylor->id);
    $this->row = $this->file->rows()->first();
    $this->outsider = lfUser('merchant', [$this->macys->id]);
});

/*
 * THE REPORTED BUG.
 */

it('refuses to delete another buyers file, even by direct request', function () {
    // No button renders this any more, so a request reaching it did not come
    // from the screen. 403, not a redirect.
    $this->actingAs($this->outsider)
        ->delete(route('uploaded-files.destroy', $this->file->id))
        ->assertForbidden();

    // The assertion that matters: the file, its rows and its cells survive.
    expect(ExcelFile::find($this->file->id))->not->toBeNull();
    expect(ExcelRow::where('excel_file_id', $this->file->id)->count())->toBe(1);
    expect(ExcelCell::where('row_id', $this->row->id)->count())->toBe(3);
});

it('refuses to delete a file an admin has locked', function () {
    // The other half of the same hole: an admin lock is not a buyer lock, and
    // destroy() honoured neither.
    $file = lfFile(null);
    $file->forceFill(['is_locked' => true, 'lock_scope' => 'all_users'])->save();

    $this->actingAs(lfUser('merchant'))
        ->delete(route('uploaded-files.destroy', $file->id))
        ->assertForbidden();

    expect(ExcelFile::find($file->id))->not->toBeNull();
});

it('hides the Delete and Print buttons on a foreign buyer file', function () {
    $response = $this->actingAs($this->outsider)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk();

    // The UI layer, which is the SECOND line of defence — the 403 above is the
    // first. Both are asserted because either alone is a partial fix.
    $response->assertViewHas('canDeleteFile', false);
    $response->assertViewHas('canPrintFile', false);

    // The Delete BUTTON specifically. The id 'delete-file-form' also appears in
    // a getElementById lookup that is always rendered, so asserting on the bare
    // id would pass or fail for the wrong reason; `form="delete-file-form"` is
    // the submit button's own attribute and nothing else carries it.
    $response->assertDontSee('form="delete-file-form"', false);
    $response->assertDontSee('window.print()', false);

    // And the read-only banner IS shown, so the user is told why.
    $response->assertViewHas('isBuyerLockedForUser', true);
});

it('still lets the files own buyer team delete and print it', function () {
    // A lock that refuses everyone is not a fix.
    $insider = lfUser('merchant', [$this->annTaylor->id]);

    $this->actingAs($insider)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk()
        ->assertViewHas('canDeleteFile', true)
        ->assertViewHas('canPrintFile', true);

    $this->actingAs($insider)
        ->delete(route('uploaded-files.destroy', $this->file->id))
        ->assertRedirect();

    expect(ExcelFile::find($this->file->id))->toBeNull();
});

/*
 * EXFILTRATION, not mutation.
 *
 * These two take nothing away from the file and so are not "mutating routes" —
 * which is exactly how they were missed. Downloading hands over the complete
 * original workbook as a file; the history panel names staff and lays out
 * another buyer's edit timeline. Both cross the buyer boundary.
 */

it('refuses to download another buyers original workbook', function () {
    $this->actingAs($this->outsider)
        ->get(route('uploaded-files.download', $this->file->id))
        ->assertForbidden();
});

it('hides the Download button on a foreign buyer file', function () {
    $this->actingAs($this->outsider)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk()
        ->assertViewHas('canDownloadFile', false)
        ->assertDontSee(route('uploaded-files.download', $this->file->id), false);
});

it('still lets the files own buyer team download it', function () {
    // The admin lock is untouched by this change, so a plain owner still gets
    // their file. Storage has no real object behind file_path in a test, so the
    // guard passing is the assertion: it redirects back with the "no longer
    // stored" warning instead of a 403.
    $this->actingAs(lfUser('merchant', [$this->annTaylor->id]))
        ->get(route('uploaded-files.download', $this->file->id))
        ->assertRedirect();
});

it('withholds the change history entirely, not just its button', function () {
    $response = $this->actingAs($this->outsider)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk();

    $response->assertViewHas('canViewHistory', false);

    // The panel is an inline Bootstrap collapse, so hiding only the button
    // would leave every staff name and old→new value in the page source. The
    // controller must not load the rows at all.
    $response->assertViewHas('activityLog', fn ($log) => $log->isEmpty());
    $response->assertDontSee('fileActivityLog', false);
    $response->assertDontSee('Change history', false);
});

it('still shows the change history to the files own buyer team', function () {
    // A guard that withholds it from everyone is not a fix.
    $insider = lfUser('merchant', [$this->annTaylor->id]);

    // Give the file something to have a history of.
    $this->actingAs($insider)
        ->post(route('uploaded-files.rows.store', $this->file->id));

    $this->actingAs($insider)
        ->get(route('uploaded-files.show', $this->file->id))
        ->assertOk()
        ->assertViewHas('canViewHistory', true)
        ->assertViewHas('activityLog', fn ($log) => $log->isNotEmpty())
        ->assertSee('Change history', false);
});

/*
 * THE REST OF THE MUTATING SURFACE.
 *
 * Each of these asserts the DATA is unchanged, not merely that a response came
 * back — a guard that warns and writes anyway would pass a status-only check.
 */

it('refuses to save cell edits on another buyers file', function () {
    $header = ExcelHeader::where('header_key', 'style_name')->first();

    $this->actingAs($this->outsider)
        ->put(route('uploaded-files.update', $this->file->id), [
            'cells' => [$this->row->id => [$header->id => 'TAMPERED']],
        ])
        ->assertRedirect();

    expect(ExcelCell::where('row_id', $this->row->id)->where('header_id', $header->id)->value('value'))
        ->toBe('Zara');
});

it('refuses to add a row to another buyers file', function () {
    $this->actingAs($this->outsider)
        ->post(route('uploaded-files.rows.store', $this->file->id))
        ->assertRedirect();

    expect($this->file->rows()->count())->toBe(1);
});

it('refuses to duplicate a row on another buyers file', function () {
    $this->actingAs($this->outsider)
        ->post(route('uploaded-files.rows.duplicate', $this->file->id), ['row_ids' => [$this->row->id]])
        ->assertRedirect();

    expect($this->file->rows()->count())->toBe(1);
});

it('refuses to duplicate a whole style on another buyers file', function () {
    $headerIds = ExcelHeader::whereIn('header_key', ['buyer_name', 'style_name', 'season_name'])
        ->pluck('id', 'header_key');

    $filters = [];

    foreach ($headerIds as $id) {
        $filters[$id] = 'Zara';
    }

    $this->actingAs($this->outsider)
        ->post(route('uploaded-files.rows.duplicate_matching', $this->file->id), ['filters' => $filters])
        ->assertRedirect();

    expect($this->file->rows()->count())->toBe(1);
});

it('refuses to add an order on another buyers file', function () {
    $this->actingAs($this->outsider)
        ->post(route('uploaded-files.rows.add_order', $this->file->id), [
            'source_row_id' => $this->row->id,
        ])
        ->assertRedirect();

    expect($this->file->rows()->count())->toBe(1);
});

it('refuses the add-order source picker on another buyers file', function () {
    $this->actingAs($this->outsider)
        ->getJson(route('uploaded-files.rows.order_sources', $this->file->id))
        ->assertForbidden();
});

it('refuses to dismiss a duplicate badge on another buyers file', function () {
    // This route had the role check and NEITHER lock, so a confined merchant
    // could clear badges on a file they cannot otherwise touch.
    $this->actingAs($this->outsider)
        ->patch(route('uploaded-files.rows.dismiss_duplicate', [
            'excelFile' => $this->file->id,
            'row' => $this->row->id,
        ]))
        ->assertRedirect();

    // The badge is still there.
    expect($this->row->fresh()->duplicated_at)->not->toBeNull();
});

it('refuses to lock or unlock a file as a non-admin', function () {
    $this->actingAs($this->outsider)
        ->patch(route('uploaded-files.lock', $this->file->id), [
            'locked' => true,
            'lock_scope' => 'all_users',
        ])
        ->assertForbidden();

    expect($this->file->fresh()->is_locked)->toBeFalsy();
});
