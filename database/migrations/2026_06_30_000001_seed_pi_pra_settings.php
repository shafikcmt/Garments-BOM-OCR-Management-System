<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default PI / PRA settings into the existing app_settings key/value
 * table. Additive only — no schema changes and existing values are never
 * overwritten (insertOrIgnore on the unique "key" column).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $defaults = [
            'pra_working_days' => '7',

            'pra_sign_prepared_name' => null,
            'pra_sign_prepared_designation' => null,
            'pra_sign_prepared_image' => null,
            'pra_sign_prepared_enabled' => '0',

            'pra_sign_checked_name' => null,
            'pra_sign_checked_designation' => null,
            'pra_sign_checked_image' => null,
            'pra_sign_checked_enabled' => '0',

            'pra_sign_approved_name' => null,
            'pra_sign_approved_designation' => null,
            'pra_sign_approved_image' => null,
            'pra_sign_approved_enabled' => '0',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('app_settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'pra_working_days',
            'pra_sign_prepared_name',
            'pra_sign_prepared_designation',
            'pra_sign_prepared_image',
            'pra_sign_prepared_enabled',
            'pra_sign_checked_name',
            'pra_sign_checked_designation',
            'pra_sign_checked_image',
            'pra_sign_checked_enabled',
            'pra_sign_approved_name',
            'pra_sign_approved_designation',
            'pra_sign_approved_image',
            'pra_sign_approved_enabled',
        ])->delete();
    }
};
