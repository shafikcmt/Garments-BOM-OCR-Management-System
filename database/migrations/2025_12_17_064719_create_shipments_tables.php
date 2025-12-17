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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id(); // Shipment ID (bigint PK)

            $table->foreignId('order_id')
                ->constrained('orders')
                ->onDelete('cascade'); // Linked order

            $table->string('ship_mode'); // Sea / Air
            $table->string('container_type')->nullable(); // 20FT / 40FT
            $table->string('container_number')->nullable(); // Optional

            $table->string('bl_awb_no'); // BL / AWB number
            $table->string('vessel_name')->nullable(); // Vessel name

            $table->date('etd')->nullable(); // ETD
            $table->date('eta')->nullable(); // ETA
            $table->date('ata')->nullable(); // ATA

            $table->string('status')->default('In Transit'); // In Transit / Arrived
            $table->text('remarks')->nullable(); // Optional notes

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
