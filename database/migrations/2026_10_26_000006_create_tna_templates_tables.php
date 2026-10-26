<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time and Action templates. A template is a list of tasks, each due a number
 * of days before / after an anchor date of the order (ship date, or the day
 * the order came in), given to a role. Applying it to a style makes ordinary
 * follow-up tasks, so the to-do list, lights and reminders work unchanged.
 * Everything is entered on screen (Admin > TnA Templates); nothing is coded
 * per buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tna_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_apply')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tna_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tna_template_id')->constrained('tna_templates')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('anchor', 30)->default('ship_date');
            $table->integer('offset_days')->default(0);
            $table->string('assignee_role', 125);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tna_template_tasks');
        Schema::dropIfExists('tna_templates');
    }
};
