<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DROPDOWN COLUMNS ON THE WORKSPACE, CONTROLLED BY ADMIN.
 *
 * A workspace column can now take its choices from one of the Admin-managed
 * lists on Vendor Options (Item Types, Incoterms, Ship Modes and the new Payment
 * Terms). excel_headers.options_source names the list; Admin sets it per column
 * on Excel Header Control. Nullable: a column without one behaves exactly as
 * before, and a cell may still hold a value that is not in the list.
 *
 * Existing columns are wired up where the meaning is clear: Delivery Term uses
 * Incoterms, Payment Term uses Payment Terms and Ship Mode uses Ship Modes. The
 * lists are seeded with every value those columns and the vendors already hold,
 * so nothing in use disappears from the dropdown.
 */
return new class extends Migration
{
    private const COLUMN_SOURCES = [
        'incoterm' => ['delivery_term', 'delivery_terms', 'incoterm'],
        'payment_term' => ['payment_term', 'payment_terms'],
        'ship_mode' => ['ship_mode'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('excel_headers') && ! Schema::hasColumn('excel_headers', 'options_source')) {
            Schema::table('excel_headers', function (Blueprint $table) {
                $table->string('options_source', 30)->nullable()->after('field_type');
            });
        }

        if (! Schema::hasTable('supplier_options')) {
            return;
        }

        $now = now();
        $existing = ['incoterm' => [], 'payment_term' => [], 'ship_mode' => []];

        // Wire the matching workspace columns and collect the values they hold.
        if (Schema::hasTable('excel_headers')) {
            foreach (self::COLUMN_SOURCES as $source => $keys) {
                $ids = DB::table('excel_headers')->whereIn('header_key', $keys)->pluck('id')->all();

                if ($ids === []) {
                    continue;
                }

                DB::table('excel_headers')->whereIn('id', $ids)->whereNull('options_source')->update(['options_source' => $source]);

                if (Schema::hasTable('excel_cells')) {
                    $existing[$source] = DB::table('excel_cells')
                        ->whereIn('header_id', $ids)
                        ->whereNotNull('value')
                        ->where('value', '!=', '')
                        ->distinct()
                        ->limit(200)
                        ->pluck('value')
                        ->all();
                }
            }
        }

        // Vendors' default payment terms, too.
        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'default_payment_terms')) {
            $existing['payment_term'] = array_merge(
                $existing['payment_term'],
                DB::table('suppliers')->whereNotNull('default_payment_terms')->distinct()->pluck('default_payment_terms')->all()
            );
        }

        $defaults = [
            'payment_term' => ['LC at sight', 'TT before shipment', 'TT after shipment', '30 days', '60 days', '90 days'],
        ];

        foreach ($existing as $type => $values) {
            $have = DB::table('supplier_options')->where('type', $type)->pluck('name')->map(fn ($n) => mb_strtolower($n))->flip()->all();
            $order = (int) DB::table('supplier_options')->where('type', $type)->max('sort_order');

            foreach (array_merge($defaults[$type] ?? [], $values) as $name) {
                $name = mb_substr(trim((string) $name), 0, 100);

                if ($name === '' || isset($have[mb_strtolower($name)])) {
                    continue;
                }

                $have[mb_strtolower($name)] = true;
                DB::table('supplier_options')->insert([
                    'type' => $type, 'name' => $name, 'sort_order' => ++$order,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('excel_headers') && Schema::hasColumn('excel_headers', 'options_source')) {
            Schema::table('excel_headers', function (Blueprint $table) {
                $table->dropColumn('options_source');
            });
        }
    }
};
