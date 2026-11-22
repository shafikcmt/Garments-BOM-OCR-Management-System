<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * One person an Admin chose to act at one step of a departmental PRA.
 * See the migration for the rule; PraStageResolver is the only reader.
 */
class PraStepApprover extends Model
{
    protected $fillable = ['sequence', 'buyer_id', 'user_id'];

    protected $casts = ['sequence' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** Whether the table exists yet (a deploy that has not migrated falls back to automatic routing). */
    public static function isAvailable(): bool
    {
        return Schema::hasTable('pra_step_approvers');
    }

    /**
     * Replace the people for one (step, buyer) with exactly these users.
     * An empty list removes the rule, which hands the step back to the
     * automatic routing (or to the all-buyers rule, for a buyer rule).
     *
     * Each chosen user is also granted the departmental-PRA permission, because
     * the approval screens are gated by it and a person chosen here may come
     * from outside the departments that normally hold it.
     *
     * @param  list<int>  $userIds
     */
    public static function replaceFor(int $sequence, ?int $buyerId, array $userIds): void
    {
        $userIds = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        static::query()
            ->where('sequence', $sequence)
            ->when($buyerId === null, fn ($q) => $q->whereNull('buyer_id'), fn ($q) => $q->where('buyer_id', $buyerId))
            ->delete();

        foreach ($userIds as $userId) {
            static::create(['sequence' => $sequence, 'buyer_id' => $buyerId, 'user_id' => $userId]);
        }

        $permission = Permission::where('name', 'pra.departmental.act')->where('guard_name', 'web')->first();

        if ($permission) {
            User::whereIn('id', $userIds)->get()->each(function (User $user) use ($permission) {
                if (! $user->hasPermissionTo($permission)) {
                    $user->givePermissionTo($permission);
                }
            });
        }
    }
}
