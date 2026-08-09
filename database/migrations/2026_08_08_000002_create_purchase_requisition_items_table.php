<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Stock (module A) — one item line of a Purchase Requisition, column
 * for column with the reference workbook's row 9/10 header pair.
 *
 * Why the *_snapshot columns exist
 * --------------------------------
 * Stock in Hand, Safety Stock, Consumption (Last Month) and the Last Purchase
 * figures are all DERIVED — GeneralStockReportService recomputes them from the
 * live purchase and issue records every time it is asked. That is right for the
 * Stock Report, where a backdated challan should correct history.
 *
 * It is wrong for a requisition. A requisition is a document: it records the
 * case that was made for buying something ON THE DAY it was raised. If the
 * numbers were re-derived at read time, reopening an August requisition in
 * December would show December's stock position and the approval would no
 * longer be evidence of anything.
 *
 * So the live services fill these fields on the entry form, and the values are
 * frozen here on save. The derived logic is never duplicated — only its output
 * for one moment is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();

            // Restricted, not cascaded: an item that appears on a requisition
            // is part of a signed document and must not vanish from it because
            // someone tidied the item master.
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();

            // Snapshot of the category, so the category-wise report keeps
            // grouping an old requisition the way it was grouped when raised,
            // even if the item is later recategorised.
            $table->foreignId('item_category_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);

            // Excel columns C and D. UOM is a snapshot for the same reason as
            // the category; specification is typed per requisition because the
            // requester may ask for a specific brand or model this time.
            $table->string('uom_snapshot', 50)->nullable();
            $table->string('specification')->nullable();

            // Excel column E — "Type (Replace/ New)".
            $table->enum('type', ['replace', 'new'])->nullable();

            // Excel column F — "User Dept./ Section", free text because one line
            // can serve several ("Production/Sample/ Cad/Cutting").
            $table->string('user_dept')->nullable();

            // Excel column G — the only quantity the requester actually types.
            $table->decimal('qty_requested', 18, 4);

            // Excel H, I, J — "Stock Details", frozen (see the note above).
            $table->decimal('stock_in_hand_snapshot', 18, 4)->nullable();
            $table->decimal('safety_stock_snapshot', 18, 4)->nullable();
            $table->decimal('consumption_last_month_snapshot', 18, 4)->nullable();

            // Excel K, L, M — "Last Purchase Details", frozen.
            $table->decimal('last_purchase_qty_snapshot', 18, 4)->nullable();
            $table->decimal('last_purchase_rate_snapshot', 18, 4)->nullable();
            $table->date('last_purchase_date_snapshot')->nullable();

            // Excel N, O, P — "To Be Procured". Qty and Amount are derived on
            // the form (Requested − In Hand, floored at 0; Qty × Rate) but are
            // STORED so the printed document and its total can never be
            // recomputed into a different number later.
            $table->decimal('to_be_procured_qty', 18, 4)->default(0);
            $table->decimal('rate_appx', 18, 4)->nullable();
            $table->decimal('amount', 18, 4)->default(0);

            // Excel Q and S — the acknowledgement Pending Qty columns. Filled
            // during the approval flow, not at entry.
            $table->decimal('store_pending_qty', 18, 4)->nullable();
            $table->decimal('accounts_pending_qty', 18, 4)->nullable();

            // Excel column U.
            $table->string('remarks', 1000)->nullable();

            $table->timestamps();

            // The category-wise report groups by these two together.
            $table->index(['purchase_requisition_id', 'item_category_id'], 'pr_items_req_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
    }
};
