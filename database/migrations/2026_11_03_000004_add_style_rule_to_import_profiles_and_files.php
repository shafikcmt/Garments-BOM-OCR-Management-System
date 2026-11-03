<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Standard model, Phase 2: how a buyer's style codes map to mother / child.
 * Set once on the buyer's import profile and copied to each file it imports, so
 * a later re-sync of the file follows the same rule. Both nullable: null means
 * "automatic" (the Mother Style column, then the style code, then the style).
 *
 *   mother_source  column | code | style
 *   fit_words      comma-separated, e.g. "PETITE, TALL, CURVY, CURVY PETITE"
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['buyer_import_profiles', 'excel_files'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'mother_source')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('mother_source', 20)->nullable();
                    $t->text('fit_words')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['buyer_import_profiles', 'excel_files'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'mother_source')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['mother_source', 'fit_words']));
            }
        }
    }
};
