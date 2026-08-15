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

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
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
     *   another department Commercial, Store, Accounts, Supply Chain and
     *                      Production have no buyer concept. This is the line
     *                      that keeps them provably untouched — they return
     *                      here, before the file's buyer is ever read.
     *   unscoped merchant  a merchant with no buyer assignment, which is every
     *                      merchant that existed before this feature.
     *   untagged file      a file uploaded before buyer tagging existed. It
     *                      belongs to no buyer, so no buyer's admin can claim
     *                      it, and locking everyone out of the entire existing
     *                      archive is not a scope rule — it is an outage.
     */
    public function isBuyerLockedForUser(?User $user = null): bool
    {
        if (! $user || ! $user->isMerchantDepartment()) {
            return false;
        }

        $theirs = $user->merchantBuyerId();

        if ($theirs === null || $this->buyer_id === null) {
            return false;
        }

        return (int) $this->buyer_id !== (int) $theirs;
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
