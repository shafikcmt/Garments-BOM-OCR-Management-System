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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // BASIC ORDER INFO
            $table->string('buyer_name');                 // Buyer Name
            $table->string('division')->nullable();        // Division
            $table->string('season_name');                 // Season
            $table->string('order_status')->nullable();    // Order Status (CONFIRMED etc.)
            $table->string('order_category')->nullable();  // Order Category (BULK etc.)
            $table->string('product_type')->nullable();    // Product Type

            // STYLE & PO
            $table->string('style_name');                  // Style Number / Style Name
            $table->string('po_number')->unique();         // PO #
            $table->string('description')->nullable();     // Description
            $table->string('wash_type')->nullable();       // Wash Type

            // QUANTITY
            $table->integer('order_qty')->default(0);      // Order Qty
            $table->integer('sewing_qty')->default(0);     // Sewing Qty
            $table->integer('balance_to_sewing')->default(0); // Balance to sewing

            // PRODUCTION METRICS
            $table->decimal('smv', 8, 2)->nullable();           // SMV
            $table->decimal('total_minutes', 12, 2)->nullable(); // Total Minutes

            // COMMERCIAL
            $table->decimal('fob', 8, 2)->nullable();        // FOB
            $table->decimal('sales_value', 12, 2)->nullable(); // Sales Value
            $table->decimal('gm', 8, 2)->nullable();         // GM
            $table->string('destination')->nullable();       // Destination

            // DATES
            $table->date('pcd')->nullable();                 // PCD
            $table->date('x_fty')->nullable();               // X-Fty
            $table->date('x_country')->nullable();           // X-Country
            $table->date('original_x_fty')->nullable();      // ORIGINAL X-Fty
            $table->date('original_x_country')->nullable();  // ORIGINAL X-Country

            // STATUS
            $table->string('shipment_status')->nullable();        // Shipment Status
            $table->string('fabric_booking_status')->nullable(); // Fabric Booking Status

            // REMARKS
            $table->text('remarks')->nullable();              // Remarks

            // SYSTEM STATUS
            $table->string('status')->default('Open');        // Open / Processing / Completed

            // AUDIT
            $table->foreignId('created_by')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            $table->timestamps();
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
