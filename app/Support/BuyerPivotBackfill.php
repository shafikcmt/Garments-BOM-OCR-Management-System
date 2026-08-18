<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Copies every existing single-buyer team assignment into the buyer_user pivot.
 *
 * A class rather than a few lines inside the migration, for one reason: on the
 * development database NOT ONE USER has users.buyer_id set, so running the
 * migration locally proves nothing about the backfill. Production may hold
 * real rows. Extracting it means a test can build the data local does not have
 * and assert what actually happens to it, and run() returns a count so the
 * result is checkable on the server where the rows really are.
 *
 * Idempotent. insertOrIgnore against the pivot's unique (buyer_id, user_id)
 * makes a second run a no-op, which is what makes it safe to re-run after a
 * failed deploy rather than a thing to be careful about.
 *
 * No raw SQL: insertOrIgnore compiles to INSERT IGNORE on MySQL and
 * ON CONFLICT DO NOTHING on PostgreSQL, and the test suite's SQLite handles it
 * too — see the database rules in CLAUDE.md.
 */
final class BuyerPivotBackfill
{
    /** @return int rows actually inserted */
    public function run(): int
    {
        $now = now();
        $inserted = 0;

        User::query()
            ->whereNotNull('buyer_id')
            ->select(['id', 'buyer_id'])
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now, &$inserted) {
                $rows = $users->map(fn (User $user) => [
                    'user_id' => $user->id,
                    'buyer_id' => $user->buyer_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                $inserted += DB::table('buyer_user')->insertOrIgnore($rows);
            });

        return $inserted;
    }
}
