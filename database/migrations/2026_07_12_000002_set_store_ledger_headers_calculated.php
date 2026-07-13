<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

// Mark the 7 overlapping Store headers as value_mode = 'calculated' so the
// existing "Auto" badge and isCalculatedHeader() logic treat them as read-only
// (they are now auto-filled from the Material Stock Ledger). 'calculated' is an
// already-supported value_mode enum value — no schema change.
//
// Reversible: down() restores the exact pre-migration live modes
// (invoiced_qty_store / invoiced_rate_store / receipt_qty were 'input';
//  invoiced_amount_store / liability / dead_stock_quantity / liability_stock_value
//  were 'formula').
return new class extends Migration
{
    private array $calculated = [
        'invoiced_qty_store',
        'invoiced_rate_store',
        'invoiced_amount_store',
        'receipt_qty',
        'liability',
        'dead_stock_quantity',
        'liability_stock_value',
    ];

    private array $previousModes = [
        'invoiced_qty_store' => 'input',
        'invoiced_rate_store' => 'input',
        'receipt_qty' => 'input',
        'invoiced_amount_store' => 'formula',
        'liability' => 'formula',
        'dead_stock_quantity' => 'formula',
        'liability_stock_value' => 'formula',
    ];

    public function up(): void
    {
        $storeRoleId = Role::where('name', 'store')->value('id');
        if (! $storeRoleId) {
            return;
        }

        DB::table('excel_headers')
            ->where('owner_role_id', $storeRoleId)
            ->whereIn('header_key', $this->calculated)
            ->update(['value_mode' => 'calculated', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $storeRoleId = Role::where('name', 'store')->value('id');
        if (! $storeRoleId) {
            return;
        }

        foreach ($this->previousModes as $key => $mode) {
            DB::table('excel_headers')
                ->where('owner_role_id', $storeRoleId)
                ->where('header_key', $key)
                ->update(['value_mode' => $mode, 'updated_at' => now()]);
        }
    }
};
