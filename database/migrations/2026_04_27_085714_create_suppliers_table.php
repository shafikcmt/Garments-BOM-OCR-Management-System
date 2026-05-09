<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // Basic supplier info
            $table->string('supplier_code')->unique()->nullable();
            $table->string('supplier_name');
            $table->string('legal_name')->nullable();

            // Contact info
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Address info
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();

            // Booking default info
            $table->string('item_type')->nullable();
            $table->string('incoterm')->nullable();
            $table->string('ship_mode')->nullable();
            $table->decimal('tolerance_percent', 5, 2)->nullable();

            // Status
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};