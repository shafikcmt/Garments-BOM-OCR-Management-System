<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer order (PO) register of a style: what the workspace does not hold for a
 * customer PO - destination, contract / L/C reference, the quotation it was won
 * on, remark - and the order-level approval.
 *
 * Keyed by style + customer PO (like order_size_quantities), not by order line,
 * so it survives the workspace re-syncing its order lines. Quantities, colours
 * and ship dates stay owned by the workspace and are only read here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->string('customer_po_no', 191)->default('');
            $table->string('destination', 160)->nullable();
            $table->string('contract_ref', 160)->nullable();
            $table->foreignId('costing_id')->nullable()->constrained('costings')->nullOnDelete();
            $table->text('remark')->nullable();
            $table->string('approval_status', 20)->default('draft');
            $table->string('approved_fingerprint', 40)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mother_style_id', 'customer_po_no'], 'buyer_orders_style_po_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_orders');
    }
};
