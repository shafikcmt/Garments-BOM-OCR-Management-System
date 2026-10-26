<?php

use App\Services\HeaderAliasResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns whose edits appear in a style's "Order Changes" box (who changed
 * the order quantity, ship date, PO ... and when). Set per column on Admin >
 * Excel Header Control. Starts ticked on the usual order columns, found by
 * header name; Admin can change it.
 */
return new class extends Migration
{
    private const START_WITH = [
        'customer_contract_quantity', 'gmts_order_qty', 'contract_shipment_date', 'contract_number',
        'gmts_color_name', 'bom_quantity', 'initial_consumption', 'consumption_incl_yy',
    ];

    public function up(): void
    {
        Schema::table('excel_headers', function (Blueprint $table) {
            $table->boolean('track_order_changes')->default(false)->after('can_edit_owner_only');
        });

        $resolver = app(HeaderAliasResolver::class);
        $ids = collect(self::START_WITH)->flatMap(fn ($key) => $resolver->headerIdsForCanonical($key))->unique()->values()->all();
        if ($ids !== []) {
            DB::table('excel_headers')->whereIn('id', $ids)->update(['track_order_changes' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('excel_headers', function (Blueprint $table) {
            $table->dropColumn('track_order_changes');
        });
    }
};
