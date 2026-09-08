<?php

use App\Models\ActivityLog;
use App\Models\BookingPo;
use App\Models\ExcelFile;
use App\Models\ExcelCell;
use App\Models\ExcelFileChangeLog;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Deleting a row from the Excel Work Sheet.
 *
 * This is the first row-level deletion the worksheet has ever had, and it is
 * DELIBERATELY NARROW: it undoes a row somebody has just added and did not
 * want. It is not a general "remove any line".
 *
 * The reason is in the schema. Eleven tables point at excel_rows, and
 * booking_pos.excel_row_id CASCADES — deleting a row would silently delete the
 * purchase order raised from it. The rest are nullOnDelete, which is quieter
 * and worse: the receiving or ledger entry survives, having lost the order line
 * it came from. So a row that anything depends on, or that anyone has already
 * worked on, is refused.
 *
 * The first group tests ExcelRow::deletionBlockers() DIRECTLY, because it is
 * the single place that question is answered — the controller refuses on it and
 * the worksheet will offer the button on it. Testing it only through the
 * endpoint would leave the model free to drift from what the screen shows,
 * which is precisely the shape of the file-level Delete bug this project has
 * already had once.
 */
function rdUser(string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

/**
 * A file whose rows were ADDED BY A USER, numbered past the padded minimum.
 *
 * Two things this fixture has to get right:
 *
 *   origin. A row is deletable only if it was recorded as user-added when it
 *   was created. The column defaults to 'imported', so a fixture that omits it
 *   builds rows nothing may delete — which is the safe default working, but
 *   would make every test below pass for the wrong reason.
 *
 *   numbering. show() tops a file up to ExcelRow::MINIMUM_VISIBLE_ROWS with
 *   padding rows, so a fixture numbering from 1 would have its rows interleaved
 *   with padding on the page tests.
 *
 * rdImportedFile() below is the counterpart: rows exactly as the upload writes
 * them.
 */
function rdFile(int $rowCount = 3, ?int $buyerId = null): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'sheet.xlsx',
        'original_file_name' => 'sheet.xlsx',
        'file_path' => 'sheet.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => $rowCount,
    ]);

    $base = ExcelRow::MINIMUM_VISIBLE_ROWS;

    foreach (range(1, $rowCount) as $number) {
        ExcelRow::create([
            'excel_file_id' => $file->id,
            'row_number' => $base + $number,
            'origin' => ExcelRow::ORIGIN_ADDED,
        ]);
    }

    return $file->fresh();
}

/**
 * A file whose rows came from the UPLOAD, written the way the importer writes
 * them: real values in the cells and not a single change-log entry.
 *
 * That combination is the bug this origin column exists for. Before it, such a
 * row answered "never edited" and was offered a Delete button on a sheet full
 * of live order data.
 */
function rdImportedFile(int $rowCount = 3): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'uploaded.xlsx',
        'original_file_name' => 'uploaded.xlsx',
        'file_path' => 'uploaded.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'total_rows' => $rowCount,
    ]);

    $header = ExcelHeader::firstOrCreate(
        ['header_key' => 'buyer_name'],
        [
            'header_name' => 'Buyer Name',
            'owner_role_id' => Role::findOrCreate('merchant', 'web')->id,
            'position' => 1,
            'is_active' => true,
        ]
    );

    $base = ExcelRow::MINIMUM_VISIBLE_ROWS;

    foreach (range(1, $rowCount) as $number) {
        $row = ExcelRow::create([
            'excel_file_id' => $file->id,
            'row_number' => $base + $number,
            'origin' => ExcelRow::ORIGIN_IMPORTED,
        ]);

        // Real uploaded data, exactly as it arrives: cells written directly,
        // no change log.
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => $header->id,
            'value' => 'HUGO BOSS',
        ]);
    }

    return $file->fresh();
}

// --- The shared check, tested directly --------------------------------------

it('reports no blockers for a plain untouched row', function () {
    $row = rdFile()->rows()->orderBy('row_number')->first();

    expect($row->deletionBlockers(rdUser()))->toBe([])
        ->and($row->isDeletableBy(rdUser()))->toBeTrue();
});

it('blocks a row a booking PO was raised from', function () {
    // The dangerous one: this foreign key CASCADES, so without the check a
    // merchant tidying a row would take a purchase order with it.
    $file = rdFile();
    $row = $file->rows()->orderBy('row_number')->first();

    BookingPo::create([
        'excel_row_id' => $row->id,
        'excel_file_id' => $file->id,
        // po_no is NOT NULL and unique.
        'po_no' => 'PO-BLOCKER-1',
    ]);

    expect($row->deletionBlockers(rdUser()))->toContain('it is used by a booking PO')
        ->and($row->isDeletableBy(rdUser()))->toBeFalse();
});

it('blocks a row that has been edited since it was created', function () {
    $row = rdFile()->rows()->orderBy('row_number')->first();

    ExcelFileChangeLog::create([
        'excel_file_id' => $row->excel_file_id,
        'excel_row_id' => $row->id,
        'user_id' => rdUser()->id,
    ]);

    expect($row->deletionBlockers(rdUser()))
        ->toContain('it has been edited since it was created');
});

it('blocks a row that something was duplicated from', function () {
    // duplicated_from_row_id is nullOnDelete, so removing the source would
    // quietly strip the copy's lineage and its badge.
    $file = rdFile();
    $source = $file->rows()->orderBy('row_number')->first();

    ExcelRow::create([
        'excel_file_id' => $file->id,
        'row_number' => 99,
        'duplicated_from_row_id' => $source->id,
        'duplicated_at' => now(),
    ]);

    expect($source->deletionBlockers(rdUser()))
        ->toContain('another row was duplicated from it');
});

it('blocks every role that may not add rows', function () {
    $row = rdFile()->rows()->first();

    foreach (['store', 'commercial', 'account', 'supply_chain'] as $role) {
        expect($row->deletionBlockers(rdUser($role)))
            ->toContain('your role cannot delete rows');
    }

    // And allows the two that may.
    foreach (['merchant', 'admin'] as $role) {
        expect($row->deletionBlockers(rdUser($role)))->toBe([]);
    }
});

it('blocks a file the admin lock has made read-only', function () {
    $file = rdFile();
    $file->forceFill(['is_locked' => true, 'lock_scope' => 'all_users'])->save();

    $row = $file->fresh()->rows()->first();

    expect($row->deletionBlockers(rdUser()))->toContain('this file is locked by admin');
});

it('reports every reason at once, not just the first', function () {
    // The screen shows the merchant why the option is missing, so one reason
    // out of three would send them to fix the wrong thing.
    $file = rdFile();
    $file->forceFill(['is_locked' => true, 'lock_scope' => 'all_users'])->save();
    $row = $file->fresh()->rows()->first();

    ExcelFileChangeLog::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'user_id' => rdUser()->id,
    ]);

    expect($row->deletionBlockers(rdUser('store')))->toHaveCount(3);
});

// --- The endpoint refuses on exactly the same answer ------------------------

it('deletes an unreferenced, unedited row', function () {
    $file = rdFile(3);
    $row = $file->rows()->where('row_number', ExcelRow::MINIMUM_VISIBLE_ROWS + 2)->first();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]))
        ->assertRedirect(route('uploaded-files.show', $file->id));

    expect(ExcelRow::find($row->id))->toBeNull()
        ->and($file->rows()->count())->toBe(2);
});

it('refuses to delete a row carrying a booking PO, and says so', function () {
    $file = rdFile();
    $row = $file->rows()->orderBy('row_number')->first();

    BookingPo::create([
        'excel_row_id' => $row->id,
        'excel_file_id' => $file->id,
        'po_no' => 'PO-BLOCKER-2',
    ]);

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'booking PO'));

    // Still there, and so is the PO.
    expect(ExcelRow::find($row->id))->not->toBeNull()
        ->and(BookingPo::where('excel_row_id', $row->id)->exists())->toBeTrue();
});

it('refuses to delete an edited row, and says so', function () {
    $file = rdFile();
    $row = $file->rows()->orderBy('row_number')->first();

    ExcelFileChangeLog::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'user_id' => rdUser()->id,
    ]);

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'edited since'));

    expect(ExcelRow::find($row->id))->not->toBeNull();
});

it('refuses to delete from a locked file, and says so', function () {
    $file = rdFile();
    $file->forceFill(['is_locked' => true, 'lock_scope' => 'all_users'])->save();
    $row = $file->fresh()->rows()->first();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'locked by admin'));

    expect(ExcelRow::find($row->id))->not->toBeNull();
});

it('refuses a role that cannot delete rows, with a 403', function () {
    $file = rdFile();
    $row = $file->rows()->first();

    $this->actingAs(rdUser('store'))
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]))
        ->assertForbidden();

    expect(ExcelRow::find($row->id))->not->toBeNull();
});

it('will not delete a row belonging to another workbook', function () {
    $file = rdFile();
    $otherFile = rdFile();
    $foreignRow = $otherFile->rows()->first();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $foreignRow->id]))
        ->assertNotFound();

    expect(ExcelRow::find($foreignRow->id))->not->toBeNull();
});

// --- Renumbering ------------------------------------------------------------

it('closes the gap left behind, keeping row numbers contiguous', function () {
    $file = rdFile(5);
    $rows = $file->rows()->orderBy('row_number')->get();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $rows[1]->id]));

    // Five rows with the second removed leaves four CONTIGUOUS numbers —
    // not a hole where the deleted one was.
    $b = ExcelRow::MINIMUM_VISIBLE_ROWS;

    expect($file->rows()->orderBy('row_number')->pluck('row_number')->all())
        ->toBe([$b + 1, $b + 2, $b + 3, $b + 4]);

    // And each surviving row kept its identity while moving up.
    expect($rows[0]->fresh()->row_number)->toBe($b + 1)
        ->and($rows[2]->fresh()->row_number)->toBe($b + 2)
        ->and($rows[3]->fresh()->row_number)->toBe($b + 3)
        ->and($rows[4]->fresh()->row_number)->toBe($b + 4);
});

it('handles deleting the very last row, where nothing shifts', function () {
    $file = rdFile(3);
    $last = $file->rows()->orderByDesc('row_number')->first();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $last->id]));

    expect($file->rows()->orderBy('row_number')->pluck('row_number')->all())
        ->toBe([ExcelRow::MINIMUM_VISIBLE_ROWS + 1, ExcelRow::MINIMUM_VISIBLE_ROWS + 2]);
});

it('handles deleting the first row', function () {
    $file = rdFile(3);
    $first = $file->rows()->orderBy('row_number')->first();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $first->id]));

    // The gap closes upward, so survivors take the deleted row's number on.
    expect($file->rows()->orderBy('row_number')->pluck('row_number')->all())
        ->toBe([ExcelRow::MINIMUM_VISIBLE_ROWS + 1, ExcelRow::MINIMUM_VISIBLE_ROWS + 2]);
});

it('leaves another workbook untouched while renumbering this one', function () {
    $file = rdFile(3);
    $otherFile = rdFile(3);
    $before = $otherFile->rows()->orderBy('row_number')->pluck('row_number', 'id')->all();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [
            $file->id,
            $file->rows()->orderBy('row_number')->first()->id,
        ]));

    expect($otherFile->rows()->pluck('row_number', 'id')->all())->toEqual($before);
});

// --- Audit ------------------------------------------------------------------

it('records the deletion, and the entry survives the row', function () {
    // activity_logs.row_id is nullOnDelete, so the entry outlives its row and
    // simply loses its anchor — which is what an audit trail of a deletion
    // should look like. Mirrors the file_deleted entry destroy() writes.
    $file = rdFile(3);
    $row = $file->rows()->where('row_number', ExcelRow::MINIMUM_VISIBLE_ROWS + 2)->first();
    $user = rdUser();

    $this->actingAs($user)
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]));

    $log = ActivityLog::where('excel_file_id', $file->id)
        ->where('action', 'row_deleted')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->old_value)->toBe('Row '.(ExcelRow::MINIMUM_VISIBLE_ROWS + 2))
        ->and($log->user_id)->toBe($user->id)
        ->and($log->row_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The batched check, and what the worksheet does with it
|--------------------------------------------------------------------------
|
| The screen renders up to 150 rows. Asking deletionBlockers() per row is
| eleven queries each — about 1,650 for one page load — for a button most rows
| will not show. deletableIdsFor() answers for the whole page in a fixed number
| of queries instead, and the single-row method delegates to it so there is
| still only one implementation of the rule.
*/

it('answers for a whole page in a fixed number of queries', function () {
    $file = rdFile(60);
    $user = rdUser();
    $rows = $file->rows()->orderBy('row_number')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();
    ExcelRow::deletableIdsFor($rows, $user);
    $batched = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The point is that it does not scale with the row count: 60 rows must not
    // cost 60 times anything. A generous ceiling, so the test pins the SHAPE
    // rather than an exact number that would churn on any refactor.
    expect($batched)->toBeLessThan(30)
        ->and($batched)->toBeLessThan($rows->count());
});

it('gives the same answer batched as it does one row at a time', function () {
    // If these ever disagree, the screen and the server disagree.
    $file = rdFile(4);
    $user = rdUser();
    $rows = $file->rows()->orderBy('row_number')->get();

    // Make one row ineligible, so the two paths have something to differ on.
    BookingPo::create([
        'excel_row_id' => $rows[1]->id,
        'excel_file_id' => $file->id,
        'po_no' => 'PO-BATCH-1',
    ]);

    $batched = ExcelRow::deletionBlockersFor($rows, $user);

    foreach ($rows as $row) {
        expect($batched[$row->id])->toEqual($row->deletionBlockers($user));
    }

    expect(array_keys(ExcelRow::deletableIdsFor($rows, $user)))
        ->toEqual([$rows[0]->id, $rows[2]->id, $rows[3]->id]);
});

it('applies the shared role and lock reasons to every row at once', function () {
    $file = rdFile(3);
    $file->forceFill(['is_locked' => true, 'lock_scope' => 'all_users'])->save();
    $rows = $file->fresh()->rows()->get();

    $blockers = ExcelRow::deletionBlockersFor($rows, rdUser());

    foreach ($rows as $row) {
        expect($blockers[$row->id])->toContain('this file is locked by admin');
    }

    expect(ExcelRow::deletableIdsFor($rows, rdUser()))->toBe([]);
});

it('handles being given no rows at all', function () {
    expect(ExcelRow::deletableIdsFor(collect(), rdUser()))->toBe([])
        ->and(ExcelRow::deletionBlockersFor(collect(), rdUser()))->toBe([]);
});

// --- What the worksheet renders ---------------------------------------------

it('offers Delete only on a row that qualifies', function () {
    $file = rdFile(3);
    $rows = $file->rows()->orderBy('row_number')->get();

    // Row 2 is spoken for; rows 1 and 3 are not.
    BookingPo::create([
        'excel_row_id' => $rows[1]->id,
        'excel_file_id' => $file->id,
        'po_no' => 'PO-VIEW-1',
    ]);

    $page = $this->actingAs(rdUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    // The BUTTON, not the bare class — the click handler in the page script
    // also mentions .js-delete-row, so asserting on that alone would pass for
    // the wrong reason.
    expect(substr_count($page, 'class="row-delete-btn js-delete-row"'))->toBe(2);

    // data-row-number appears only on delete buttons in this fixture (no
    // duplicate badges), so it says which rows were offered one.
    $b = ExcelRow::MINIMUM_VISIBLE_ROWS;

    /* Read the numbers off the BUTTONS, not off the page. Every <tr> also
     * carries data-row-number now (script needs it, because the first cell's
     * text is no longer just the number), so asserting on that attribute alone
     * would match the row itself and pass whether a button existed or not. */
    preg_match_all('/row-delete-btn js-delete-row"\s+data-row-id="\d+"\s+data-row-number="(\d+)"/', $page, $m);
    $offered = array_map('intval', $m[1]);

    expect($offered)->toBe([$b + 1, $b + 3]);
});

it('offers Delete on no row at all to a role that may not delete', function () {
    $file = rdFile(3);

    $page = $this->actingAs(rdUser('supply_chain'))
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    expect($page)->not->toContain('class="row-delete-btn js-delete-row"');
});

it('ships one delete form for the page, not one per row', function () {
    // 150 rows must not render 150 forms. The action is rewritten per click,
    // the same way the dismiss-badge button works.
    $file = rdFile(8);

    $page = $this->actingAs(rdUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    expect(substr_count($page, 'id="delete-row-form"'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Origin: an uploaded row is never deletable
|--------------------------------------------------------------------------
|
| THE BUG THIS FIXES. Deletion asked "has anyone edited this row" and answered
| it from excel_file_change_logs. The importer writes rows and cells straight
| into the database and logs nothing, so a row that arrived from the upload
| carrying forty-two filled cells of live order data looked exactly like a
| blank row created a moment ago — and was offered a Delete button. On the real
| FA-27 file that was 499 rows.
|
| Nothing about a row's contents or timestamps separates the two reliably, so
| origin is recorded at creation. 'imported' is the column DEFAULT, which means
| a code path that creates rows without saying how produces rows that cannot be
| deleted rather than rows that can.
*/

it('never offers to delete a row that came from the upload', function () {
    $file = rdImportedFile(3);
    $row = $file->rows()->orderBy('row_number')->first();

    // Real data, and — exactly as the importer leaves it — no change log at all.
    expect($row->origin)->toBe(ExcelRow::ORIGIN_IMPORTED)
        ->and(ExcelFileChangeLog::where('excel_row_id', $row->id)->exists())->toBeFalse()
        ->and(ExcelCell::where('row_id', $row->id)->whereNotNull('value')->exists())->toBeTrue();

    expect($row->deletionBlockers(rdUser()))
        ->toContain('it came from the uploaded file, so it is part of the original order');
});

it('refuses at the server too, not just in the markup', function () {
    // "Structurally impossible", not "usually hidden": a hand-made request has
    // to be refused as firmly as the button is withheld.
    $file = rdImportedFile(3);
    $row = $file->rows()->orderBy('row_number')->first();

    $this->actingAs(rdUser())
        ->delete(route('uploaded-files.rows.destroy', [$file->id, $row->id]))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'came from the uploaded file'));

    expect(ExcelRow::find($row->id))->not->toBeNull();
});

it('keeps an uploaded row undeletable however much it is edited', function () {
    // The old rule turned on edit history. This one does not consult it for an
    // imported row at all — edited or untouched, it is part of the order.
    $file = rdImportedFile(2);
    $rows = $file->rows()->orderBy('row_number')->get();

    ExcelFileChangeLog::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $rows[0]->id,
        'user_id' => rdUser()->id,
    ]);

    foreach ($rows as $row) {
        expect($row->isDeletableBy(rdUser()))->toBeFalse();
    }
});

it('defaults an unspecified row to undeletable', function () {
    // The fail-safe direction. A future code path that creates rows and forgets
    // to say how must not produce deletable ones.
    $file = rdImportedFile(1);

    $row = ExcelRow::create([
        'excel_file_id' => $file->id,
        'row_number' => 500,
    ]);

    expect($row->fresh()->origin)->toBe(ExcelRow::ORIGIN_IMPORTED)
        ->and($row->fresh()->isDeletableBy(rdUser()))->toBeFalse();
});

it('lets a duplicate be deleted, until it is edited', function () {
    // The whole point of the feature, and the moment it ends.
    $file = rdFile(2);
    $source = $file->rows()->orderBy('row_number')->first();

    $copy = ExcelRow::create([
        'excel_file_id' => $file->id,
        'row_number' => 500,
        'duplicated_from_row_id' => $source->id,
        'duplicated_at' => now(),
        'origin' => ExcelRow::ORIGIN_DUPLICATED,
    ]);

    expect($copy->isDeletableBy(rdUser()))->toBeTrue();

    // First edit, and the option is gone for good.
    ExcelFileChangeLog::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $copy->id,
        'user_id' => rdUser()->id,
    ]);

    expect($copy->fresh()->deletionBlockers(rdUser()))
        ->toContain('it has been edited since it was created');
});

/*
|--------------------------------------------------------------------------
| Delete Selected
|--------------------------------------------------------------------------
*/

it('deletes only the eligible rows of a mixed selection', function () {
    $file = rdFile(4);
    $rows = $file->rows()->orderBy('row_number')->get();

    // Two of the four cannot go: one carries a PO, one is imported.
    BookingPo::create([
        'excel_row_id' => $rows[1]->id,
        'excel_file_id' => $file->id,
        'po_no' => 'PO-BULK-1',
    ]);
    $rows[3]->update(['origin' => ExcelRow::ORIGIN_IMPORTED]);

    $this->actingAs(rdUser())
        ->post(route('uploaded-files.rows.destroy_many', $file->id), [
            'row_ids' => $rows->pluck('id')->all(),
        ])
        ->assertRedirect(route('uploaded-files.show', $file->id));

    // The two eligible ones went; the two refused are still here.
    expect(ExcelRow::find($rows[0]->id))->toBeNull()
        ->and(ExcelRow::find($rows[2]->id))->toBeNull()
        ->and(ExcelRow::find($rows[1]->id))->not->toBeNull()
        ->and(ExcelRow::find($rows[3]->id))->not->toBeNull();
});

it('says what it deleted and what it would not', function () {
    // A selection is made for duplication, so it routinely holds rows nothing
    // may delete. Saying so is the difference between a partial result and a
    // silent one.
    $file = rdFile(3);
    $rows = $file->rows()->orderBy('row_number')->get();

    BookingPo::create([
        'excel_row_id' => $rows[2]->id,
        'excel_file_id' => $file->id,
        'po_no' => 'PO-BULK-2',
    ]);

    $this->actingAs(rdUser())
        ->post(route('uploaded-files.rows.destroy_many', $file->id), [
            'row_ids' => $rows->pluck('id')->all(),
        ])
        ->assertSessionHas('warning', function ($m) {
            return str_contains($m, '2 rows were deleted')
                && str_contains($m, '1 of the 3')
                && str_contains($m, 'booking PO');
        });
});

it('closes every gap in one pass', function () {
    $file = rdFile(5);
    $rows = $file->rows()->orderBy('row_number')->get();
    $b = ExcelRow::MINIMUM_VISIBLE_ROWS;

    // Two holes, not adjacent.
    $this->actingAs(rdUser())
        ->post(route('uploaded-files.rows.destroy_many', $file->id), [
            'row_ids' => [$rows[1]->id, $rows[3]->id],
        ]);

    expect($file->rows()->orderBy('row_number')->pluck('row_number')->all())
        ->toBe([$b + 1, $b + 2, $b + 3]);
});

it('refuses the whole batch when nothing in it may go', function () {
    $file = rdImportedFile(3);
    $rows = $file->rows()->get();

    $this->actingAs(rdUser())
        ->post(route('uploaded-files.rows.destroy_many', $file->id), [
            'row_ids' => $rows->pluck('id')->all(),
        ])
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'None of the 3 selected rows'));

    expect($file->rows()->count())->toBe(3);
});

it('will not bulk-delete rows out of another workbook', function () {
    $file = rdFile(2);
    $otherFile = rdFile(2);
    $foreign = $otherFile->rows()->get();

    $this->actingAs(rdUser())
        ->post(route('uploaded-files.rows.destroy_many', $file->id), [
            'row_ids' => $foreign->pluck('id')->all(),
        ])
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'No matching row'));

    expect($otherFile->rows()->count())->toBe(2);
});

it('refuses bulk delete for a role that may not delete', function () {
    $file = rdFile(2);

    $this->actingAs(rdUser('store'))
        ->post(route('uploaded-files.rows.destroy_many', $file->id), [
            'row_ids' => $file->rows()->pluck('id')->all(),
        ])
        ->assertForbidden();

    expect($file->rows()->count())->toBe(2);
});

// --- The buttons ------------------------------------------------------------

it('ships both selection buttons hidden, to be revealed by the selection', function () {
    // Progressive disclosure: a greyed "(0)" is a control the merchant reads
    // and dismisses on every page load for something not yet available.
    $file = rdFile(3);

    $page = $this->actingAs(rdUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->getContent();

    expect($page)->toContain('id="duplicateSelectedButton" class="btn btn-primary btn-sm d-none"')
        ->toContain('id="deleteSelectedButton" class="btn btn-outline-danger btn-sm d-none"');

    // One bulk form for the page, not one per row.
    expect(substr_count($page, 'id="delete-selected-form"'))->toBe(1);
});
