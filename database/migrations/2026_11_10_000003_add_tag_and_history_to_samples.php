<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sample traceability: a Tag ID for every sample (SMP-26-0012, printed on the
 * physical tag) and a history of what happened to it - each status change and
 * buyer comment, so rework rounds are counted and the buyer's comments can be
 * searched later as lessons. Additive; existing samples get a Tag ID and a
 * history line for the status they are in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table) {
            $table->string('tag_id', 30)->nullable()->unique()->after('id');
        });

        Schema::create('sample_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')->constrained('samples')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->date('event_date');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['status', 'event_date']);
        });

        DB::table('samples')->orderBy('id')->each(function ($s) {
            $created = $s->created_at ? \Illuminate\Support\Carbon::parse($s->created_at) : now();
            DB::table('samples')->where('id', $s->id)->update(['tag_id' => 'SMP-' . $created->format('y') . '-' . str_pad((string) $s->id, 4, '0', STR_PAD_LEFT)]);
            DB::table('sample_logs')->insert([
                'sample_id' => $s->id, 'status' => $s->status, 'note' => $s->buyer_comment,
                'event_date' => $s->comment_date ?: $created->toDateString(), 'user_id' => $s->updated_by ?: $s->created_by, 'created_at' => $created,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_logs');
        Schema::table('samples', function (Blueprint $table) {
            $table->dropUnique(['tag_id']);
            $table->dropColumn('tag_id');
        });
    }
};
