<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — Category on the issue itself.
//
// Category used to be display-only on the Issue form, mirrored from the
// selected item. It is now a picked value from the item_categories master, so
// it needs somewhere to live. Still auto-filled from the item when one is
// selected; the user can change it.
//
// Additive and nullable: existing issue rows keep working with a null category.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_issues', function (Blueprint $table) {
            $table->foreignId('item_category_id')->nullable()->after('issue_approver_id')
                ->constrained('item_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_category_id');
        });
    }
};
