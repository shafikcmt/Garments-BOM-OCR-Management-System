<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_headers', function (Blueprint $table) {
            $table->string('value_mode')
                ->default('input')
                ->after('field_type'); // input, formula, conditional

            $table->string('formula_key')
                ->nullable()
                ->after('value_mode');

            $table->json('formula_meta')
                ->nullable()
                ->after('formula_key');
        });
    }

    public function down(): void
    {
        Schema::table('excel_headers', function (Blueprint $table) {
            $table->dropColumn([
                'value_mode',
                'formula_key',
                'formula_meta',
            ]);
        });
    }
};