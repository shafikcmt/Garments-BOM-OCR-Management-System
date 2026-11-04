<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Stock purchasing: Comparative Statement (CS) and Internal PO.
 *
 * Flow: an approved Purchase Requisition line -> CS (suppliers compared per
 * line) -> CS approved -> Internal PO per chosen supplier -> Store receiving
 * against the PO number.
 *
 * Deliberately separate from `booking_pos`, which is the BUYER-order PO (BOM,
 * style, buyer scope). General Stock consumables belong to no buyer, so they
 * get their own tables; the only thing shared is the idea of a PO number.
 *
 * Additive only: new tables, and two nullable columns on stock_purchases that
 * link a receiving line to the PO line it answers. No foreign keys on the
 * stock_purchases side so an old receiving can never block deleting a PO draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One counter row per document type and financial year ("cs|26-27").
        Schema::create('gs_purchase_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('period_key', 40)->unique();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });

        Schema::create('gs_comparative_statements', function (Blueprint $table) {
            $table->id();
            $table->string('cs_no', 40)->unique();
            $table->date('cs_date');
            // draft, submitted, checked, approved, cancelled
            $table->string('status', 20)->default('draft')->index();

            // The form's control box, frozen when the CS is created.
            $table->string('form_code', 40)->nullable();
            $table->string('form_issue_date', 20)->nullable();
            $table->string('form_version_no', 10)->nullable();
            $table->string('form_revision_no', 10)->nullable();

            $table->text('recommendation')->nullable();
            $table->text('approver_note')->nullable();
            $table->text('remarks')->nullable();

            // The three signature boxes: Prepared, Checked (Manager SCM), Approved.
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Sent back: the note says what to fix; the sheet is a draft again.
            $table->text('return_note')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();

            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The suppliers compared on the sheet, one column group each.
        Schema::create('gs_cs_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gs_comparative_statement_id')->constrained('gs_comparative_statements')->cascadeOnDelete();
            $table->unsignedBigInteger('general_stock_supplier_id')->nullable()->index();
            $table->string('name', 255);
            $table->text('address')->nullable();
            $table->string('phone', 100)->nullable();
            // "Excluding VAT. One month credit along with work order."
            $table->text('terms')->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gs_cs_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gs_comparative_statement_id')->constrained('gs_comparative_statements')->cascadeOnDelete();
            // Which requisition line this answers. No FK: the requisition is a
            // signed document and must never be blocked by, or cascade into, a CS.
            $table->unsignedBigInteger('purchase_requisition_item_id')->nullable()->index();
            $table->unsignedBigInteger('stock_item_id')->nullable()->index();
            $table->string('item_name', 255);
            $table->string('uom', 50)->nullable();
            $table->decimal('qty', 18, 4);
            $table->string('specification', 255)->nullable();
            $table->string('current_supplier', 255)->nullable();
            // The supplier chosen for this line (gs_cs_suppliers.id).
            $table->unsignedBigInteger('selected_supplier_id')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('gs_cs_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gs_cs_item_id')->constrained('gs_cs_items')->cascadeOnDelete();
            $table->foreignId('gs_cs_supplier_id')->constrained('gs_cs_suppliers')->cascadeOnDelete();
            $table->string('specification', 255)->nullable();
            // NULL = this supplier did not quote the line.
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['gs_cs_item_id', 'gs_cs_supplier_id'], 'gs_cs_quotes_item_supplier_unique');
        });

        Schema::create('gs_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_no', 40)->unique();
            $table->date('po_date');
            // draft, issued, partially_received, received, cancelled
            $table->string('status', 24)->default('draft')->index();

            $table->foreignId('gs_comparative_statement_id')->nullable()->constrained('gs_comparative_statements')->nullOnDelete();
            $table->unsignedBigInteger('gs_cs_supplier_id')->nullable();
            $table->unsignedBigInteger('general_stock_supplier_id')->nullable()->index();
            $table->string('supplier_name', 255);
            $table->text('supplier_address')->nullable();
            $table->string('supplier_phone', 100)->nullable();

            $table->string('reference_no', 100)->nullable();
            $table->string('bin_number', 60)->nullable();
            $table->text('invoice_to')->nullable();
            $table->text('consignee')->nullable();
            $table->string('dispatched_through', 150)->nullable();
            $table->string('destination', 255)->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->text('remarks')->nullable();

            $table->decimal('total_amount', 18, 2)->default(0);

            // Checked By / Authorised Signatory boxes.
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();

            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gs_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gs_purchase_order_id')->constrained('gs_purchase_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('gs_cs_item_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_requisition_item_id')->nullable()->index();
            $table->unsignedBigInteger('stock_item_id')->nullable()->index();
            $table->string('item_name', 255);
            $table->string('uom', 50)->nullable();
            $table->decimal('qty', 18, 4);
            $table->decimal('rate', 18, 4)->default(0);
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('due_on', 40)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A receiving line can name the PO line it answers.
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('gs_purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('gs_purchase_order_item_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropIndex(['gs_purchase_order_id']);
            $table->dropIndex(['gs_purchase_order_item_id']);
            $table->dropColumn(['gs_purchase_order_id', 'gs_purchase_order_item_id']);
        });

        Schema::dropIfExists('gs_purchase_order_items');
        Schema::dropIfExists('gs_purchase_orders');
        Schema::dropIfExists('gs_cs_quotes');
        Schema::dropIfExists('gs_cs_items');
        Schema::dropIfExists('gs_cs_suppliers');
        Schema::dropIfExists('gs_comparative_statements');
        Schema::dropIfExists('gs_purchase_sequences');
    }
};
