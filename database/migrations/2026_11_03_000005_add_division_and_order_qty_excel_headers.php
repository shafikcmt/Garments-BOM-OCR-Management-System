<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Standard model, Phase 2: two merchant input columns the buyer files carry.
 *
 *   Division   -> the mother style's product group (MOP "Division", HB "un";
 *                 give the buyer's own name under Other Names)
 *   Order Qty  -> the order line's quantity. Buyer files hold it in a formula
 *                 column (Customer Contract Quantity), which an upload skips, so
 *                 the merchant needs an input column for it.
 *
 * Additive and idempotent; Admin can rename, move or switch them off on Excel
 * Header Control.
 */
return new class extends Migration
{
    private const HEADERS = [
        ['key' => 'division', 'name' => 'Division', 'type' => 'text', 'aliases' => 'Product Group'],
        ['key' => 'order_qty', 'name' => 'Order Qty', 'type' => 'number', 'aliases' => null],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('excel_headers')) {
            return;
        }

        $merchantRoleId = DB::table('roles')->where('name', 'merchant')->value('id');

        // Headers must have an owner; a fresh database gets them from the seeders.
        if (! $merchantRoleId) {
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
                'owner_role_id' => $merchantRoleId,
                'position' => ++$position,
                'field_type' => $header['type'],
                'is_required' => false,
                'is_active' => true,
                'can_view_all' => true,
                'can_edit_owner_only' => true,
                'merchant_can_upload' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
        }
    }

    public function down(): void
    {
        DB::table('excel_headers')->whereIn('header_key', array_column(self::HEADERS, 'key'))->delete();
    }
};
