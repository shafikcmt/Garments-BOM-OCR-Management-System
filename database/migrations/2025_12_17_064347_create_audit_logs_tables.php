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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); // Log ID (bigint PK)

            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade'); // Linked order

            $table->foreignId('field_id')
                  ->constrained('order_fields')
                  ->onDelete('cascade'); // Linked field

            $table->text('old_value')->nullable(); // Previous value
            $table->text('new_value')->nullable(); // Updated value
            $table->string('action'); // insert / update / delete

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade'); // Who changed

            $table->timestamp('created_at')->useCurrent(); // Change time
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
