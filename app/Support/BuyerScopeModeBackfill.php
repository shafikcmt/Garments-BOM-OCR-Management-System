<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Writes buyer_scope_mode = 'specific' for every user who is confined TODAY,
 * so that turning the column on changes nobody's access.
 *
 * A class rather than a few lines inside the migration, for the same reason
 * BuyerPivotBackfill is one: on the development database NOT ONE USER holds a
 * buyer, so running the migration locally exercises none of this. Production
 * may hold real rows. Extracting it means a test can build the data local does
 * not have and assert what actually happens to it, and run() returns a count
 * so the result is checkable on the server where the rows really are.
 *
 * Idempotent — it only ever writes 'specific' over rows that qualify, so a
 * second run is a no-op and it is safe to re-run after a failed deploy.
 *
 * No raw SQL, and no LIKE: every clause here is a plain whereIn, which needs
 * no thought about MySQL vs PostgreSQL vs the test suite's SQLite.
 */
final class BuyerScopeModeBackfill
{
    /**
     * Roles buyer scoping applied to when this backfill was written.
     *
     * Frozen, and deliberately NOT read from BuyerScope::SCOPED_DEPARTMENTS.
     * That const grows — Supply Chain is next — and a backfill whose result
     * depends on when it runs is not a backfill. A user in a department
     * switched on later must arrive as 'all' (unconfined, exactly as they are
     * today) and become 'specific' only when somebody deliberately assigns
     * them buyers. Switching a department on must never confine anyone by
     * itself.
     *
     * @var list<string>
     */
    public const ROLES_SCOPED_AT_WRITE_TIME = ['merchant'];

    /** @return int rows actually updated */
    public function run(): int
    {
        $ids = $this->confinedUserIds();

        if ($ids === []) {
            return 0;
        }

        return DB::table('users')
            ->whereIn('id', $ids)
            ->where('buyer_scope_mode', '!=', BuyerScope::MODE_SPECIFIC)
            ->update(['buyer_scope_mode' => BuyerScope::MODE_SPECIFIC]);
    }

    /**
     * Users confined today: they hold at least one buyer — by column OR by
     * pivot — AND their role is one buyer scoping currently applies to.
     *
     * Both sources, because either alone under-reports. A user created before
     * the pivot existed has only the column; one added straight to the pivot
     * has only the row. Missing either would leave a confined user written as
     * 'all', which is the one error here that widens access.
     *
     * @return list<int>
     */
    public function confinedUserIds(): array
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', self::ROLES_SCOPED_AT_WRITE_TIME)
            ->pluck('id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $inScope = DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->whereIn('role_id', $roleIds)
            ->pluck('model_id');

        if ($inScope->isEmpty()) {
            return [];
        }

        $byColumn = DB::table('users')
            ->whereIn('id', $inScope)
            ->whereNotNull('buyer_id')
            ->pluck('id');

        $byPivot = DB::table('buyer_user')
            ->whereIn('user_id', $inScope)
            ->pluck('user_id');

        return $byColumn
            ->concat($byPivot)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Users holding a buyer whose department buyer scoping does NOT apply to.
     *
     * Not used by run() — this is the production audit that must be read
     * before another department is switched on. BuyerPivotBackfill copies
     * users.buyer_id into the pivot for EVERY user regardless of department,
     * and a stray value on, say, a Supply Chain account is inert only because
     * the department gate refuses to read it. Widen that gate and the stray
     * value becomes a live assignment.
     *
     * @return array<int, object>
     */
    public function strayBuyerHolders(): array
    {
        $scopedRoleIds = DB::table('roles')
            ->whereIn('name', self::ROLES_SCOPED_AT_WRITE_TIME)
            ->pluck('id');

        $inScope = DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->whereIn('role_id', $scopedRoleIds)
            ->pluck('model_id');

        $holders = DB::table('users')
            ->whereNotNull('buyer_id')
            ->pluck('id')
            ->concat(DB::table('buyer_user')->pluck('user_id'))
            ->map(fn ($id) => (int) $id)
            ->unique();

        $stray = $holders->diff($inScope)->values();

        if ($stray->isEmpty()) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', $stray)
            ->get(['id', 'name', 'email', 'buyer_id', 'buyer_scope_mode'])
            ->all();
    }
}
