<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Restores the login-less System (Auto Sync) user.
 *
 * The account is created by 2026_07_12_000001_create_system_sync_user, which
 * the Material Stock Ledger -> Workspace sync depends on: it attributes
 * excel_cells.updated_by to this row so an auto-write is never blamed on the
 * human who happened to trigger the recompute.
 *
 * The row went missing. The migration is already marked Ran, so `migrate` will
 * not put it back, and MaterialStockLedgerCellSyncService::systemUserId() looks
 * the account up by email and returns null when it is absent — which is why new
 * auto-writes were landing with a null updated_by.
 *
 * A seeder rather than a new migration, because the schema is not changing and
 * because the original migration is the one that owns this row; this only
 * repairs its effect. The field values are copied from that migration exactly
 * rather than invented, and the existence check is the same one, so running
 * this twice is harmless.
 *
 *     php artisan db:seed --class=SystemSyncUserSeeder
 *
 * Deliberately assigns no role and no permission. status = 0 means
 * LoginRequest refuses it, and the password is a throwaway random hash nobody
 * holds or records.
 */
class SystemSyncUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('stock.system_user_email', 'system@garments-ocr.local');

        if (DB::table('users')->where('email', $email)->exists()) {
            $this->command?->info('System sync user already present — nothing to do.');

            return;
        }

        DB::table('users')->insert([
            'name' => config('stock.system_user_name', 'System (Auto Sync)'),
            'email' => $email,
            'password' => bcrypt(Str::random(40)),
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info('System sync user restored: '.$email);
    }
}
