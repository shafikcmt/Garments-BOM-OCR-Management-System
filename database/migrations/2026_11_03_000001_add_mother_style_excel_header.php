<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Standard model, Phase 1: a "Mother Style" column for buyers whose sheet names
 * the style family itself (Ann Taylor's PETITE/CURVY styles belong to a
 * different mother number than their own). Optional: sheets without it keep
 * working, the mother then comes from the style code. Additive and idempotent;
 * Admin can rename, hide or turn it off on Excel Header Control.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('excel_headers') || DB::table('excel_headers')->where('header_key', 'mother_style')->exists()) {
            return;
        }

        $merchantRoleId = DB::table('roles')->where('name', 'merchant')->value('id');

        // Headers must have an owner. A fresh database gets its roles and headers
        // from the seeders; the header is added here only where Merchandising exists.
        if (! $merchantRoleId) {
            return;
        }

        // After the existing columns, so no current sheet is reordered.
        $position = (int) DB::table('excel_headers')->max('position') + 1;

        DB::table('excel_headers')->insert([
            'header_name' => 'Mother Style',
            'header_key' => 'mother_style',
            'owner_role_id' => $merchantRoleId,
            'position' => $position,
            'field_type' => 'text',
            'is_required' => false,
            'is_active' => true,
            'can_view_all' => true,
            'can_edit_owner_only' => true,
            'merchant_can_upload' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('excel_headers')->where('header_key', 'mother_style')->delete();
    }
};
