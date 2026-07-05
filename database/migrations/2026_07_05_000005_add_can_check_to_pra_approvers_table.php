<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which pooled approvers are also eligible to act as the "Checker" in the
 * sequential Check -> Approve flow. A single pool is reused (a user can be both
 * a checker and an approver); this flag only gates the checker dropdown at PRA
 * create time. Defaults to false so existing approvers keep working as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pra_approvers', function (Blueprint $table) {
            $table->boolean('can_check')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('pra_approvers', function (Blueprint $table) {
            $table->dropColumn('can_check');
        });
    }
};
