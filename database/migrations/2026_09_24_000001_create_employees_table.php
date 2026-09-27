<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seed of the Employee Master.
 *
 * Today this table exists for ONE thing: a staff member who has no company
 * email can sign in with their Employee ID. The ID is the real one from the
 * group's HR records, typed in by an admin — never generated here — so that
 * when the HR / Payroll module (docs/09_FUTURE_ERP_ROADMAP.md, module 14,
 * PIMS) arrives, its people are these rows and its IDs are these IDs. No
 * second numbering scheme, no reconciliation, no migration of data.
 *
 * Deliberately thin. HRM will ALTER this same table for joining date,
 * designation, department, shift and the rest; nothing here is shaped in a
 * way that would make that awkward:
 *
 *   employee_id   the HR identifier, unique, free-form string (leading
 *                 zeros and letters preserved — "074047", "HAPL-0231")
 *   user_id       the login account, if the person has one. Nullable, so
 *                 HRM can hold employees who never log in; unique when set,
 *                 so one account cannot answer to two IDs. nullOnDelete: a
 *                 removed account leaves the employee record standing.
 *   name          the person's name as HR has it (may differ from the
 *                 account's display name)
 *   is_active     HR status; an inactive employee cannot sign in by ID
 *
 * Not a column on users, on purpose: an employee is not a login account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 50)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
