<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the printed quotation needs beyond the price: whether the price is per
 * piece or per dozen, the terms, the validity and a remark. All nullable (the
 * basis defaults to piece), so existing inquiries are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('price_basis', 2)->default('pc')->after('quoted_price');
            $table->string('payment_terms', 255)->nullable()->after('quoted_date');
            $table->string('delivery_terms', 255)->nullable()->after('payment_terms');
            $table->unsignedSmallInteger('validity_days')->nullable()->after('delivery_terms');
            $table->text('quote_remark')->nullable()->after('validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['price_basis', 'payment_terms', 'delivery_terms', 'validity_days', 'quote_remark']);
        });
    }
};
