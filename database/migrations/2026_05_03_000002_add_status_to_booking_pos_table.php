<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_pos')) {
            return;
        }

        Schema::table('booking_pos', function (Blueprint $table) {
            if (! Schema::hasColumn('booking_pos', 'status')) {
                $table->string('status')->default('applied')->after('booking_data');
            }

            if (! Schema::hasColumn('booking_pos', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('generated_at');
            }

            if (! Schema::hasColumn('booking_pos', 'completed_by')) {
                $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('booking_pos')->whereNull('status')->update(['status' => 'applied']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_pos')) {
            return;
        }

        if (Schema::hasColumn('booking_pos', 'completed_by')) {
            Schema::table('booking_pos', function (Blueprint $table) {
                $table->dropForeign(['completed_by']);
                $table->dropColumn('completed_by');
            });
        }

        if (Schema::hasColumn('booking_pos', 'completed_at')) {
            Schema::table('booking_pos', function (Blueprint $table) {
                $table->dropColumn('completed_at');
            });
        }

        if (Schema::hasColumn('booking_pos', 'status')) {
            Schema::table('booking_pos', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
