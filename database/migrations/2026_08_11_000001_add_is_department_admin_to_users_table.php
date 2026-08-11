<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a user as the administrator of their own department.
 *
 * A department admin may run the User Management screen, but only over the
 * users of their own department — the department being read off their role, as
 * it always has been, since there is no users.department column.
 *
 * Deliberately a flag rather than "holds the department's broad role". Every
 * Store user holds a Store role; only one or two of them should be able to
 * create accounts and hand out permissions. Tying it to the role would give the
 * whole department the power meant for its head.
 *
 * Additive and default false: no existing user is promoted by this migration.
 * Only a super admin can tick it, from the User Management screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_department_admin')
                ->default(false)
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_department_admin');
        });
    }
};
