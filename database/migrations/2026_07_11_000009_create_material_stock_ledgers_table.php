<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer/Style module (module B) — CACHED closing-stock summary (one row per
// unique key = excel_row_id + size). Recalculated by MaterialStockLedgerService
// whenever a related receiving / bulk-issue / liability-movement / dead-movement
// row is saved or deleted. Chosen over a live query because the source Excel is a
// heavy multi-sheet SUMIF and the dashboard must read closing stock fast.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_stock_ledgers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->nullOnDelete();
            $table->foreignId('booking_po_id')->nullable()->constrained('booking_pos')->nullOnDelete();
            $table->string('size')->nullable();

            // Denormalized identity for reporting.
            $table->string('po_no')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->string('style_name')->nullable();
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->string('uom')->nullable();

            // --- Receive side ---
            $table->decimal('booking_receive_qty', 18, 4)->default(0);
            $table->decimal('internal_po_receive_qty', 18, 4)->default(0);
            $table->decimal('total_receive_qty', 18, 4)->default(0);

            // --- Bulk-issue split ---
            $table->decimal('bulk_issue_qty', 18, 4)->default(0);
            $table->decimal('sample_qty', 18, 4)->default(0);          // sample out of bulk issue
            $table->decimal('declared_liability_qty', 18, 4)->default(0);
            $table->decimal('calculated_dead_qty', 18, 4)->default(0);

            // --- Reuse / sample movements out of Liability & Dead ---
            $table->decimal('liability_to_bulk_qty', 18, 4)->default(0);
            $table->decimal('liability_sample_qty', 18, 4)->default(0);
            $table->decimal('dead_to_bulk_qty', 18, 4)->default(0);
            $table->decimal('dead_sample_qty', 18, 4)->default(0);

            // --- Computed closings ---
            $table->decimal('running_closing_qty', 18, 4)->default(0);
            $table->decimal('liability_closing_qty', 18, 4)->default(0);
            $table->decimal('dead_closing_qty', 18, 4)->default(0);
            $table->decimal('total_closing_qty', 18, 4)->default(0);

            // --- Valuation ---
            $table->decimal('avg_unit_price', 18, 4)->nullable();
            $table->decimal('total_value', 18, 4)->default(0);

            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();

            // One cached ledger row per (excel_row_id, size).
            $table->unique(['excel_row_id', 'size'], 'material_stock_ledgers_key_unique');
            $table->index(['buyer_name', 'style_name', 'po_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_stock_ledgers');
    }
};
