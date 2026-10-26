<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Standard model, Phase 3: per-buyer import profiles for the upload wizard, and
 * the season a file was uploaded for. Additive; nothing existing is altered
 * except one new nullable column on excel_files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('name');
            $table->string('sheet_name')->nullable();
            $table->unsignedSmallInteger('header_row')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['buyer_id', 'name']);
        });

        Schema::create('buyer_import_profile_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('buyer_import_profiles')->cascadeOnDelete();
            $table->string('source_header');
            $table->string('source_key', 190);
            // null = the buyer's column is deliberately skipped.
            $table->foreignId('excel_header_id')->nullable()->constrained('excel_headers')->nullOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'source_key']);
        });

        Schema::table('excel_files', function (Blueprint $table) {
            $table->foreignId('buyer_season_id')->nullable()->after('buyer_id')
                ->constrained('buyer_seasons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('excel_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buyer_season_id');
        });
        Schema::dropIfExists('buyer_import_profile_columns');
        Schema::dropIfExists('buyer_import_profiles');
    }
};
