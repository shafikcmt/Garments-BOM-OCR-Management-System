<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExcelRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'excel_file_id',
        'row_number',
        'duplicated_from_row_id',
        'duplicated_at',
        'origin',
        'excel_file_import_id',
    ];

    protected $casts = [
        'duplicated_at' => 'datetime',
    ];

    /**
     * Whether the "Duplicated" badge should show for this row.
     *
     * Reads duplicated_at, NOT duplicated_from_row_id: dismissing the badge
     * clears the timestamp and deliberately keeps the lineage, so a dismissed
     * duplicate is still traceable but no longer flagged.
     */
    public function isFlaggedDuplicate(): bool
    {
        return $this->duplicated_at !== null;
    }

    /** The row this one was copied from, if it still exists. */
    public function duplicatedFrom()
    {
        return $this->belongsTo(self::class, 'duplicated_from_row_id');
    }

    public function excelFile()
    {
        return $this->belongsTo(ExcelFile::class);
    }

    public function cells()
    {
        return $this->hasMany(ExcelCell::class, 'row_id');
    }

    public function logs()
    {
        return $this->hasMany(ActivityLog::class, 'row_id');
    }

    public function bookingPo()
    {
        return $this->hasOne(BookingPo::class, 'excel_row_id');
    }

    /**
     * Mirrors the $minimumVisibleRows ExcelFileController::show() pads to.
     */
    public const MINIMUM_VISIBLE_ROWS = 20;

    /*
     * HOW A ROW CAME INTO BEING, recorded at creation rather than inferred
     * afterwards.
     *
     * Deletion asks whether a row is one somebody just added and has not worked
     * on. The second half used to be answered from excel_file_change_logs, and
     * that silently mis-answered the case that matters: the import writes rows
     * and cells directly and logs no changes, so a row that arrived from the
     * upload with forty-two filled cells looked identical to a blank row added
     * a moment ago — and was offered a Delete button.
     *
     * ORIGIN_IMPORTED is the column default, so a code path that creates rows
     * without saying how produces rows that CANNOT be deleted. The unsafe
     * direction has to be opted into explicitly, in the two places that mean
     * it.
     */
    public const ORIGIN_IMPORTED = 'imported';
    public const ORIGIN_ADDED = 'added';
    public const ORIGIN_DUPLICATED = 'duplicated';

    /** The only origins a Delete may ever be offered for. */
    public const USER_ADDED_ORIGINS = [self::ORIGIN_ADDED, self::ORIGIN_DUPLICATED];

    /**
     * Every table that points at a row, and what to call it when it is the
     * reason a row cannot be deleted.
     *
     * These are the DEPENDENTS — records elsewhere in the system that were
     * created from this row and would be damaged by its removal. Deliberately
     * NOT in this list:
     *
     *   excel_cells            the row's own values; they go with it.
     *   activity_logs          the row's own history.
     *   excel_file_change_logs the row's own edit history, which is instead
     *                          how "has it been edited" is answered below.
     *
     * booking_pos is the dangerous one and the reason this list exists at all:
     * its foreign key CASCADES, so deleting a row silently deletes the purchase
     * order raised from it. The rest are nullOnDelete, which is quieter and
     * worse — the receiving or ledger entry survives, having silently lost the
     * order line it came from.
     */
    private const DELETION_DEPENDENTS = [
        'booking_pos' => 'a booking PO',
        'payment_request_items' => 'a payment request',
        'material_requisitions' => 'a material requisition',
        'material_requisition_items' => 'a material requisition line',
        'material_receivings' => 'a material receiving',
        'material_bulk_issues' => 'a bulk issue',
        'material_liability_movements' => 'a liability movement',
        'material_dead_movements' => 'a dead-stock movement',
        'material_stock_ledgers' => 'a stock ledger entry',
    ];

    /**
     * Why this row may NOT be deleted. Empty means it may.
     *
     * THE ONE PLACE THIS QUESTION IS ANSWERED. The controller refuses on it and
     * the worksheet decides whether to offer the button on it, so the screen
     * and the server cannot disagree about a destructive action — which is
     * exactly how the file-level Delete came to render a live red button on a
     * locked file and then answer 403.
     *
     * Deletion here is deliberately narrow: undoing a row you have just added
     * and did not want. It is NOT a general "remove any line" — a row that
     * anything downstream depends on, or that somebody has already worked on,
     * is refused, and the reason is returned rather than a bare false so the
     * screen can say why the option is missing.
     *
     * @return list<string> reasons, each a sentence fragment
     */
    public function deletionBlockers(?User $user): array
    {
        // Delegates to the batch rather than repeating it. One row is just a
        // batch of one, and having a second implementation for the single case
        // is exactly how the screen and the server come to disagree.
        return static::deletionBlockersFor(collect([$this]), $user)[$this->id] ?? [];
    }

    /**
     * The same question for a whole page of rows, in a fixed number of queries.
     *
     * THE WORKSHEET RENDERS UP TO 150 ROWS AT A TIME. Asking per row would be
     * eleven queries each — about 1,650 for one page load — for a button most
     * of them will not show. This asks once per dependent table for the whole
     * set instead: eleven queries whether the page holds one row or a hundred
     * and fifty.
     *
     * The permission and lock half is decided ONCE. Every row on a page belongs
     * to the same file, so the role check and the two locks cannot vary between
     * them — resolving that per row would be 450 wasted calls to hasRole().
     *
     * Portable on purpose: whereIn + distinct + pluck, no raw SQL, no LIKE and
     * no GROUP BY. Nothing here can count differently on PostgreSQL than it
     * does on MySQL.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $rows
     * @return array<int, list<string>> row id => reasons, empty list = deletable
     */
    public static function deletionBlockersFor($rows, ?User $user): array
    {
        $rows = collect($rows);
        $ids = $rows->pluck('id')->filter()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $blockers = $ids->mapWithKeys(fn ($id) => [(int) $id => []])->all();

        if (! $user) {
            return $ids->mapWithKeys(fn ($id) => [(int) $id => ['you are not signed in']])->all();
        }

        // --- Decided once for the whole page -------------------------------
        $shared = [];

        if (! $user->hasRole('admin') && ! $user->hasRole('merchant')) {
            $shared[] = 'your role cannot delete rows';
        }

        // Every row on a worksheet page belongs to one file; loading it once
        // also avoids a lazy `excelFile` read per row.
        $file = $rows->first()->relationLoaded('excelFile')
            ? $rows->first()->excelFile
            : ExcelFile::find($rows->first()->excel_file_id);

        if ($file) {
            if ($file->isLockedForUser($user)) {
                $shared[] = 'this file is locked by admin';
            }

            if ($file->isBuyerLockedForUser($user)) {
                $shared[] = 'this file belongs to another buyer';
            }
        }

        /*
         * THE ROW HAS TO BE ONE A USER ADDED. Not "probably", not "looks
         * unedited" — recorded as such when it was created. An imported row is
         * refused here and can never reach the checks below, which is what
         * makes deleting one structurally impossible rather than merely
         * unlikely.
         */
        foreach ($rows as $row) {
            if (! in_array($row->origin, self::USER_ADDED_ORIGINS, true)) {
                $blockers[(int) $row->id][] = 'it came from the uploaded file, so it is part of the original order';
            }
        }

        // --- One query per dependent, for every row at once ----------------
        foreach (self::DELETION_DEPENDENTS as $table => $label) {
            if (! self::dependentTableExists($table)) {
                continue;
            }

            $hits = DB::table($table)
                ->whereIn('excel_row_id', $ids->all())
                ->distinct()
                ->pluck('excel_row_id');

            foreach ($hits as $rowId) {
                $blockers[(int) $rowId][] = 'it is used by '.$label;
            }
        }

        // A copy carries duplicated_from_row_id with nullOnDelete, so removing
        // the source would quietly strip the copy's lineage and its badge.
        $sources = self::whereIn('duplicated_from_row_id', $ids->all())
            ->distinct()
            ->pluck('duplicated_from_row_id');

        foreach ($sources as $rowId) {
            $blockers[(int) $rowId][] = 'another row was duplicated from it';
        }

        // Whether anyone has worked on the row since it appeared. A row created
        // and then filled in is somebody's work, not a misclick — duplication
        // writes cells but logs no CHANGE, so a fresh copy is correctly
        // untouched by this test.
        $edited = ExcelFileChangeLog::whereIn('excel_row_id', $ids->all())
            ->distinct()
            ->pluck('excel_row_id');

        foreach ($edited as $rowId) {
            $blockers[(int) $rowId][] = 'it has been edited since it was created';
        }

        // The shared reasons lead, so the first thing a merchant reads is the
        // one that applies to everything they are looking at.
        return collect($blockers)
            ->map(fn (array $own) => array_merge($shared, $own))
            ->all();
    }

    /**
     * Whether a dependent table exists, asked at most once per request.
     *
     * The guard is here so an environment part-way through migrating skips a
     * table it does not have yet rather than dying. But Schema::hasTable()
     * queries the information schema every time, which was nine of the queries
     * this batch cost — for an answer that cannot change while the request
     * runs.
     */
    private static array $dependentTableExists = [];

    private static function dependentTableExists(string $table): bool
    {
        return self::$dependentTableExists[$table] ??= Schema::hasTable($table);
    }

    /**
     * Just the ids that may be deleted, for a view that only needs membership.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $rows
     * @return array<int, true> keyed by row id, so the Blade does an isset()
     */
    public static function deletableIdsFor($rows, ?User $user): array
    {
        return collect(static::deletionBlockersFor($rows, $user))
            ->filter(fn (array $reasons) => $reasons === [])
            ->map(fn () => true)
            ->all();
    }

    /** Convenience for the places that only need the yes/no. */
    public function isDeletableBy(?User $user): bool
    {
        return $this->deletionBlockers($user) === [];
    }
}
