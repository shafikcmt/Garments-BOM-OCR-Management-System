<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_files', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('completed_at');
            $table->string('lock_scope')->default('all_users')->after('is_locked');
            $table->json('locked_user_ids')->nullable()->after('lock_scope');
            $table->json('locked_role_ids')->nullable()->after('locked_user_ids');
            $table->text('lock_reason')->nullable()->after('locked_role_ids');
            $table->foreignId('locked_by')->nullable()->after('lock_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('excel_files', function (Blueprint $table) {
            $table->dropForeign(['locked_by']);
            $table->dropColumn([
                'is_locked',
                'lock_scope',
                'locked_user_ids',
                'locked_role_ids',
                'lock_reason',
                'locked_by',
                'locked_at',
            ]);
        });
    }
};
