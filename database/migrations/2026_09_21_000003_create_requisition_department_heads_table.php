<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who heads each department, for the requisition workflow.
 *
 * A department-raised requisition goes to its department head before it
 * reaches the Store. This project has no department column and no "head"
 * flag on users — a department is a Spatie role — so the head is a mapping
 * the admin keeps here: role name → one user.
 *
 * One head per department: the row is unique on role_name and choosing
 * another person replaces it. A department with no row has no head step, and
 * its requisitions go straight to the Store as they always did.
 *
 * Cascade on the user: a deleted account cannot stay head of anything; the
 * department simply reverts to "no head configured" until the admin names
 * someone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisition_department_heads', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 60)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_department_heads');
    }
};
