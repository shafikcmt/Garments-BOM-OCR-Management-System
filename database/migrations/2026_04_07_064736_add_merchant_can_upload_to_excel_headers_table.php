<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_headers', function (Blueprint $table) {
            $table->boolean('merchant_can_upload')->default(false)->after('can_edit_owner_only');
        });
    }

    public function down(): void
    {
        Schema::table('excel_headers', function (Blueprint $table) {
            $table->dropColumn('merchant_can_upload');
        });
    }
};