<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TnA depth: a template task can be marked critical (it sits on the order's
 * critical path), and a follow-up task remembers when it was escalated to the
 * supervisor, so it is escalated once and not every morning. Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tna_template_tasks', function (Blueprint $table) {
            $table->boolean('is_critical')->default(false)->after('assignee_role');
        });
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_tasks', fn (Blueprint $t) => $t->dropColumn('escalated_at'));
        Schema::table('tna_template_tasks', fn (Blueprint $t) => $t->dropColumn('is_critical'));
    }
};
