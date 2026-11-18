<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed pick lists for the vendor form: Item Type, Incoterm, Ship Mode.
 * They used to be hard-coded in the Blade file. Additive: suppliers.item_type /
 * incoterm / ship_mode stay plain text columns, so every PO, PI and payment
 * request that prints them is untouched.
 *
 * The lists start from the old hard-coded values plus every value vendors already
 * hold, so nothing a vendor has today disappears from the dropdowns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_options', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['type', 'name']);
        });

        $defaults = [
            'item_type' => ['General', 'Fabric', 'Accessories', 'Zipper', 'Hook & Bar', 'Trim', 'Interlining', 'Scrim', 'Thread', 'Elastic', 'Lining'],
            'incoterm' => ['FOB', 'CIF', 'CFR', 'Ex-Works', 'CPT', 'EXW', 'CNF'],
            'ship_mode' => ['SEA', 'AIR', 'ROAD', 'COURIER', 'TRUCK', 'AIR + SEA', 'SEA + AIR'],
        ];

        // Values vendors already hold. An Item Type may hold several, comma separated.
        $existing = ['item_type' => [], 'incoterm' => [], 'ship_mode' => []];
        if (Schema::hasTable('suppliers')) {
            foreach (DB::table('suppliers')->select('item_type', 'incoterm', 'ship_mode')->get() as $row) {
                foreach (explode(',', (string) $row->item_type) as $part) {
                    $existing['item_type'][] = trim($part);
                }
                $existing['incoterm'][] = trim((string) $row->incoterm);
                $existing['ship_mode'][] = trim((string) $row->ship_mode);
            }
        }

        $now = now();
        foreach ($defaults as $type => $names) {
            $seen = [];
            $order = 0;
            foreach (array_merge($names, $existing[$type]) as $name) {
                $name = mb_substr(trim((string) $name), 0, 100);
                if ($name === '' || isset($seen[mb_strtolower($name)])) {
                    continue;
                }
                $seen[mb_strtolower($name)] = true;
                DB::table('supplier_options')->insert([
                    'type' => $type, 'name' => $name, 'sort_order' => ++$order,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_options');
    }
};
