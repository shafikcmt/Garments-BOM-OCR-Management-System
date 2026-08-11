<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase B of making permissions explicit per user.
 *
 * Every permission a user currently gets from their ROLE is copied down as a
 * DIRECT grant, on top of whatever direct grants they already hold. Roles keep
 * their bundles untouched, so nobody's effective access changes by a single
 * ability — this migration is deliberately a no-op from the user's point of
 * view, and the assertion at the end is what proves it.
 *
 * Why it exists: a later phase strips the bundles from the roles. Anyone whose
 * access came only from a bundle would be locked out at that moment, and one
 * user — the only holder of a narrow Store role — would lose General Stock
 * entirely, since no route guard admits that role by name. Doing the copy now,
 * as its own shippable step, means the stripping phase can be verified against
 * a state where nothing depends on a bundle any more.
 *
 * ADDITIVE ONLY. It grants; it never revokes. If the phases after this one are
 * abandoned, this can be left in place indefinitely with no effect, or reversed
 * exactly — see down().
 *
 * WHY A RECEIPT. down() cannot simply drop the direct grants that a role also
 * provides: two management users already held approve-pra BOTH directly and
 * through their role before this ran, and that rule would strip a grant this
 * migration never created — silently removing their PRA approval rights, which
 * nothing else grants back. So up() records exactly which pairs it inserted and
 * down() removes exactly those.
 */
return new class extends Migration
{
    /** app_settings key holding the list of grants this migration created. */
    private const RECEIPT_KEY = 'phase_b_permission_backfill';

    public function up(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $receipt = [];

            foreach (User::with('roles', 'permissions')->orderBy('id')->get() as $user) {
                $direct = $user->getDirectPermissions()->pluck('name');
                $toAdd = $user->getPermissionsViaRoles()->pluck('name')->diff($direct)->values();

                if ($toAdd->isEmpty()) {
                    continue;
                }

                $user->givePermissionTo($toAdd->all());

                $receipt[(string) $user->id] = $toAdd->all();
            }

            DB::table('app_settings')->updateOrInsert(
                ['key' => self::RECEIPT_KEY],
                ['value' => json_encode($receipt), 'created_at' => now(), 'updated_at' => now()]
            );

            $this->assertUnchanged($before);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $row = DB::table('app_settings')->where('key', self::RECEIPT_KEY)->first();

            // No receipt means up() never ran here. Removing anything on a
            // guess is how the approve-pra grants would be lost, so do nothing.
            if (! $row) {
                return;
            }

            $receipt = json_decode($row->value, true) ?: [];

            foreach ($receipt as $userId => $names) {
                $user = User::find($userId);

                if (! $user) {
                    continue;
                }

                foreach ($names as $name) {
                    $user->revokePermissionTo($name);
                }
            }

            DB::table('app_settings')->where('key', self::RECEIPT_KEY)->delete();

            // Reversing must be as invisible as applying was: the roles still
            // carry the bundles, so effective access is unchanged here too.
            $this->assertUnchanged($before);
        });
    }

    /**
     * Every user's effective permission set, sorted, keyed by user id.
     *
     * This is the number that must not move. It spans role-derived and direct
     * grants together, which is exactly what an authorization check sees.
     *
     * @return array<int, list<string>>
     */
    private function effectivePermissions(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $out = [];

        foreach (User::orderBy('id')->get() as $user) {
            $out[$user->id] = $user->getAllPermissions()
                ->pluck('name')->sort()->values()->all();
        }

        return $out;
    }

    /**
     * Abort — rolling the whole transaction back — if any user's effective
     * access moved. A backfill that changes what someone can do has failed,
     * however plausible the change looks.
     */
    private function assertUnchanged(array $before): void
    {
        $after = $this->effectivePermissions();

        foreach ($before as $id => $names) {
            $now = $after[$id] ?? [];

            if ($names !== $now) {
                throw new RuntimeException(sprintf(
                    'Backfill aborted: effective permissions changed for user %d. Gained [%s], lost [%s].',
                    $id,
                    implode(', ', array_diff($now, $names)) ?: 'none',
                    implode(', ', array_diff($names, $now)) ?: 'none'
                ));
            }
        }

        if (count($before) !== count($after)) {
            throw new RuntimeException('Backfill aborted: the set of users changed while running.');
        }
    }
};
