<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound shipments (Commercial): one record per BL / AWB carrying imported
 * material, with its route and dates, linked to the Booking POs it carries
 * (one shipment can carry several POs, and a PO can come in several shipments).
 * The status is worked out from the dates, never typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_no', 30)->nullable()->unique();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->string('ship_mode', 10)->default('sea');
            $table->string('forwarder', 120)->nullable();
            $table->string('vessel', 120)->nullable();
            $table->string('bl_awb_no', 80)->nullable();
            $table->string('bl_status', 20)->nullable();
            $table->string('container_no', 160)->nullable();
            $table->string('port_of_loading', 120)->nullable();
            $table->string('port_of_discharge', 120)->nullable();
            $table->string('country_of_origin', 80)->nullable();
            $table->date('etd')->nullable();
            $table->date('atd')->nullable();
            $table->date('eta')->nullable();
            $table->date('ata')->nullable();
            $table->date('customs_cleared_date')->nullable();
            $table->date('committed_inhouse_date')->nullable();
            $table->date('actual_inhouse_date')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'eta']);
        });

        Schema::create('inbound_shipment_pos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_shipment_id')->constrained('inbound_shipments')->cascadeOnDelete();
            $table->foreignId('booking_po_id')->constrained('booking_pos')->cascadeOnDelete();
            $table->decimal('qty', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['inbound_shipment_id', 'booking_po_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_shipment_pos');
        Schema::dropIfExists('inbound_shipments');
    }
};
