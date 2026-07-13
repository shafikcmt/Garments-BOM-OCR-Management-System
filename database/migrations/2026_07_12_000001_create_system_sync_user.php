<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Login-less system user that the Material Stock Ledger -> Workspace cell sync
// attributes excel_cells.updated_by to, so auto-writes are never blamed on the
// human who happened to trigger the recompute. status = 0 (inactive) so it can
// never sign in; the password is a throwaway random hash.
return new class extends Migration
{
    public function up(): void
    {
        $email = config('stock.system_user_email', 'system@garments-ocr.local');

        if (DB::table('users')->where('email', $email)->exists()) {
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
    }

    public function down(): void
    {
        // excel_cells.updated_by is nullOnDelete, so removing the user is safe.
        DB::table('users')
            ->where('email', config('stock.system_user_email', 'system@garments-ocr.local'))
            ->delete();
    }
};
