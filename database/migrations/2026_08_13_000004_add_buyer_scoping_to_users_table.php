<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer scoping for Merchandising staff. Both columns in one migration
 * because they answer one question together: which buyer is this merchant
 * confined to, and may they upload for it.
 *
 * buyer_id   inherited from the department-admin who created the account.
 *            Null — the state of every user that exists today — means
 *            unscoped, so nothing changes retroactively for anyone.
 * can_upload an override the owning department-admin may grant to one of
 *            their own people. Default false: a scoped merchant does not
 *            upload unless their admin says so. Unscoped users are never
 *            asked this question, so the default cannot restrict anyone
 *            who is not already in scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('buyer_id')
                ->nullable()
                ->after('is_department_admin')
                ->constrained('buyers')
                ->nullOnDelete();

            $table->boolean('can_upload')
                ->default(false)
                ->after('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_upload');
            $table->dropConstrainedForeignId('buyer_id');
        });
    }
};
