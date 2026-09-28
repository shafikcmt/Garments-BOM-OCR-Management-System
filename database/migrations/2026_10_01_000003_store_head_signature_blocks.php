<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Checked by Store Head" boxes were set to "Anyone with a role: store", so
 * any Store user could act as Store Head. They now name the Store
 * department head (Admin > Departments) instead — the new assignee type
 * `store_head`. Only rows filled from the Store Head step are touched.
 *
 * Settings rows only, no schema change. down() puts them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('requisition_signature_blocks')
            ->where('assignee_type', 'role')
            ->where('role_name', 'store')
            ->where('stamp_source', 'store_head')
            ->update(['assignee_type' => 'store_head', 'role_name' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('requisition_signature_blocks')
            ->where('assignee_type', 'store_head')
            ->update(['assignee_type' => 'role', 'role_name' => 'store', 'updated_at' => now()]);
    }
};
