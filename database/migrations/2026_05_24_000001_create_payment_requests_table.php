<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no')->unique();
            $table->string('supplier_name')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->decimal('total_pi_amount', 18, 4)->default(0);
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['supplier_name', 'buyer_name', 'season_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
