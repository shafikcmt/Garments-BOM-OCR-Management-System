<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExcelFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_name',
        'original_file_name',
        'file_path',
        'uploaded_by',
        'buyer_id',
        'buyer_season_id',
        'mother_source',
        'fit_words',
        'upload_batch_no',
        'total_rows',
        'status',
        'remarks',
        'submitted_at',
        'completed_at',
        'is_locked',
        'lock_scope',
        'locked_user_ids',
        'locked_role_ids',
        'lock_reason',
        'locked_by',
        'locked_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_locked' => 'boolean',
        'locked_user_ids' => 'array',
        'locked_role_ids' => 'array',
        'locked_at' => 'datetime',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The buyer this file belongs to. Nullable — files uploaded before buyer
     * tagging existed have none, and that is a supported state everywhere.
     *
     * KNOWN LIMITATION, logged deliberately rather than solved.
     *
     * buyer_id is FILE-level; "Buyer Name" is a ROW-level cell. That works only
     * while a file holds one buyer. A BOM file mixing several — one already
     * exists in the test data, BOM_FORMAT_50_DUMMY_ROWS.xlsx, with eight buyers
     * across 50 rows — cannot be represented by this column at all, and there
     * is no correct value to put in it: any single buyer picked would be wrong
     * for the other seven.
     *
     * What that costs today, concretely:
     *
     *   edit-lock         isBuyerLockedForUser() treats a mixed file as
     *                     untagged, so every scoped merchant may edit all of
     *                     it, including rows belonging to buyers they do not
     *                     hold.
     *   report visibility MerchantReportService's buyer scope leaves it visible
     *                     to everyone for the same reason.
     *
     * Neither is a live problem while such files are only test data, and
     * buyer:tag-excel-files deliberately refuses to guess at them. It becomes a
     * real one the first time a production BOM arrives with several buyers in
     * one workbook. The fix is a decision, not a patch — split such files on
     * upload, or move the buyer to the row and scope on that instead — so it is
     * recorded here rather than half-made.
     */
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /** The season this file was uploaded for (import wizard); null for older files. */
    public function season()
    {
        return $this->belongsTo(BuyerSeason::class, 'buyer_season_id');
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function rows()
    {
        return $this->hasMany(ExcelRow::class);
    }

    public function logs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function isLockedForUser(?User $user = null): bool
    {
        if (! $this->is_locked) {
            return false;
        }

        $scope = $this->lock_scope ?: 'all_users';

        if ($scope === 'specific_users') {
            if (! $user) {
                return false;
            }

            $lockedUserIds = collect($this->locked_user_ids ?? [])->map(fn ($id) => (int) $id)->all();

            return in_array((int) $user->id, $lockedUserIds, true);
        }

        if ($scope === 'specific_roles') {
            if (! $user) {
                return false;
            }

            $lockedRoleIds = collect($this->locked_role_ids ?? [])->map(fn ($id) => (int) $id);

            if ($lockedRoleIds->isEmpty()) {
                return false;
            }

            $userRoleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id);

            return $userRoleIds->intersect($lockedRoleIds)->isNotEmpty();
        }

        return true;
    }

    /**
     * Whether buyer scoping makes this file read-only for a merchant.
     *
     * The sibling of isLockedForUser() above: same question shape, same two
     * callers (the controller and the workspace view), a different reason. It
     * is applied IN ADDITION to the admin lock and to canEditHeader — none of
     * the three replaces another, and a file is editable only when all three
     * allow it.
     *
     * Five ways to be unrestricted, checked before any buyer is compared:
     *
     *   no user            nothing to scope.
     *   super admin        oversees every buyer; never scoped.
     *   another department a department buyer scoping is not switched on for
     *                      has no buyer concept. This is the line that keeps
     *                      them provably untouched — they answer here, before
     *                      the file's buyer is ever read.
     *   unconfined user    no buyer assignment, which is every merchant that
     *                      existed before this feature.
     *   untagged file      a file uploaded before buyer tagging existed. It
     *                      belongs to no buyer, so no buyer's admin can claim
     *                      it, and locking everyone out of the entire existing
     *                      archive is not a scope rule — it is an outage.
     *
     * All five now live in User::hasBuyerAccess(), which every department's
     * controllers ask directly. This stays as the file's own way of putting
     * the question — its four call sites and three Blade usages read a lock,
     * not an access — and because inverting a boolean at seven call sites to
     * save one method is a poor trade.
     */
    public function isBuyerLockedForUser(?User $user = null): bool
    {
        if (! $user) {
            return false;
        }

        // EVERY buyer they hold, not just the primary. A department admin
        // owning two buyers is scoped to both, and comparing against one would
        // lock them out of the other's files while the screen told them they
        // owned it. hasBuyerAccess() reads the whole set.
        return ! $user->hasBuyerAccess(
            $this->buyer_id === null ? null : (int) $this->buyer_id
        );
    }

    public function lockScopeLabel(): string
    {
        return match ($this->lock_scope ?: 'all_users') {
            'specific_users' => 'Specific users',
            'specific_roles' => 'Specific roles',
            default => 'All users',
        };
    }
}
