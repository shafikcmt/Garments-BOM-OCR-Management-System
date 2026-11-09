<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order progress meetings: who met, what was decided, and the action items.
 * An action item is an ordinary follow-up task (so it reaches the person's
 * to-do list, the morning reminder and the escalation) that remembers the
 * meeting it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('mother_style_id')->nullable()->constrained('mother_styles')->nullOnDelete();
            $table->string('title', 160);
            $table->date('meeting_date');
            $table->string('attendees', 500)->nullable();
            $table->text('minutes')->nullable();
            $table->date('next_meeting_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'meeting_date']);
        });

        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->foreignId('meeting_id')->nullable()->after('mother_style_id')->constrained('meetings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meeting_id');
        });
        Schema::dropIfExists('meetings');
    }
};
