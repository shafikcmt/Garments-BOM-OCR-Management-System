<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();      // receiver
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // merchant
            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->cascadeOnDelete();

            $table->string('type')->default('excel_update'); 
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('url')->nullable();

            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
