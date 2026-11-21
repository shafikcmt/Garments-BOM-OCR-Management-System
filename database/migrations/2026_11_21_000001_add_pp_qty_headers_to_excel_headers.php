<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * PP Qty billing.
 *
 * PI Amount = PI Rate x (Booking Qty + PP Qty). Some suppliers/buyers provide the
 * PP quantity free of charge, so Supply Chain marks those lines "Yes" in the new
 * PP Qty Free column (blank / No = billed). PP Qty itself is added only when no
 * such column exists yet; it is free text on purpose ("25 yds").
 *
 * Additive and idempotent. Admin can rename, move or switch them off on Excel
 * Header Control.
 */
return new class extends Migration
{
    private const HEADERS = [
        ['key' => 'pp_qty', 'name' => 'PP Qty', 'type' => 'text', 'aliases' => 'P/P Qty'],
        ['key' => 'pp_qty_free', 'name' => 'PP Qty Free', 'type' => 'text', 'aliases' => 'PP Free'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('excel_headers')) {
            return;
        }

        $ownerRoleId = DB::table('roles')->where('name', 'supply_chain')->value('id')
            ?? DB::table('roles')->where('name', 'merchant')->value('id');

        // Headers must have an owner; a fresh database gets them from the seeders.
        if (! $ownerRoleId) {
            return;
        }

        $hasAliases = Schema::hasColumn('excel_headers', 'aliases');
        $position = (int) DB::table('excel_headers')->max('position');

        foreach (self::HEADERS as $header) {
            $exists = DB::table('excel_headers')
                ->where(fn ($q) => $q->where('header_key', $header['key'])->orWhere('header_name', $header['name']))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('excel_headers')->insert(array_filter([
                'header_name' => $header['name'],
                'header_key' => $header['key'],
                'aliases' => $hasAliases ? $header['aliases'] : null,
                'owner_role_id' => $ownerRoleId,
                'position' => ++$position,
                'field_type' => $header['type'],
                'is_required' => false,
                'is_active' => true,
                'can_view_all' => true,
                'can_edit_owner_only' => true,
                'merchant_can_upload' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
        }
    }

    public function down(): void
    {
        DB::table('excel_headers')->where('header_key', 'pp_qty_free')->delete();
    }
};
