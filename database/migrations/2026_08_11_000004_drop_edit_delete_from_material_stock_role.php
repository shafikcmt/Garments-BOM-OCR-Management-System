<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Store — Buyer / Style Stock keeps View, Create/Entry and Export.
 *
 * The same rule already applied to General Stock in 2026_08_11_000003:
 * correcting or removing a record that other departments already read is a
 * per-person decision, not something a role hands out on day one. Six
 * permissions go — edit and delete across Receiving, Bulk Issuing and
 * Requisitions. Closing Stock has only view and export, so there is nothing
 * there to remove.
 *
 * Unlike the General Stock change, this role currently has NO HOLDERS, so it
 * affects nobody's access today and only shapes what a future Buyer / Style
 * Stock user starts with. The assertion below still runs: it costs nothing and
 * it is the check that would catch a holder appearing between the dry run and
 * the deploy.
 *
 * This role bundles no approval-stage permissions, so unlike the General Stock
 * migration there is no named list here — the suffix rule covers all six.
 *
 * Only store_material_stock is touched. admin and management keep their own
 * edit/delete across every module: corrective access is the point of those
 * roles, not an oversight.
 */
return new class extends Migration
{
    private const ROLE = 'store_material_stock';

    /** app_settings key recording exactly what was removed, so down() is exact. */
    private const RECEIPT_KEY = 'material_stock_role_edit_delete_removed';

    public function up(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $role = Role::where('name', self::ROLE)->first();

            if (! $role) {
                return;
            }

            $removed = $role->permissions
                ->pluck('name')
                ->filter(fn (string $n) => str_ends_with($n, '.edit') || str_ends_with($n, '.delete'))
                ->sort()
                ->values();

            foreach ($removed as $name) {
                $role->revokePermissionTo($name);
            }

            DB::table('app_settings')->updateOrInsert(
                ['key' => self::RECEIPT_KEY],
                ['value' => json_encode($removed->all()), 'created_at' => now(), 'updated_at' => now()]
            );

            $this->assertUnchanged($before);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $row = DB::table('app_settings')->where('key', self::RECEIPT_KEY)->first();
            $role = Role::where('name', self::ROLE)->first();

            if (! $row || ! $role) {
                return;
            }

            foreach (json_decode($row->value, true) ?: [] as $name) {
                $role->givePermissionTo($name);
            }

            DB::table('app_settings')->where('key', self::RECEIPT_KEY)->delete();

            $this->assertUnchanged($before);
        });
    }

    /** @return array<int, list<string>> */
    private function effectivePermissions(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $out = [];

        foreach (User::orderBy('id')->get() as $user) {
            $out[$user->id] = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
        }

        return $out;
    }

    /**
     * No user's effective access may move. A holder who turned up since the dry
     * run, and who was relying on the role rather than a direct grant, shows up
     * here as a loss and the throw rolls the change back.
     */
    private function assertUnchanged(array $before): void
    {
        $after = $this->effectivePermissions();

        foreach ($before as $id => $names) {
            $now = $after[$id] ?? [];

            if ($names !== $now) {
                throw new RuntimeException(sprintf(
                    'Aborted: effective permissions changed for user %d. Gained [%s], lost [%s].',
                    $id,
                    implode(', ', array_diff($now, $names)) ?: 'none',
                    implode(', ', array_diff($names, $now)) ?: 'none'
                ));
            }
        }
    }
};
