<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A row may carry its own PI Amount precision (0-6 decimals). Null = use the
     * default (2, or what Admin set). Additive and nullable: every existing row
     * keeps behaving as before.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('excel_rows', 'pi_decimals')) {
            Schema::table('excel_rows', function (Blueprint $table) {
                $table->unsignedTinyInteger('pi_decimals')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('excel_rows', 'pi_decimals')) {
            Schema::table('excel_rows', function (Blueprint $table) {
                $table->dropColumn('pi_decimals');
            });
        }
    }
};
