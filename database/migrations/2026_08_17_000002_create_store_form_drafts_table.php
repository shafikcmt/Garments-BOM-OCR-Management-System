<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Half-finished Record Issue / Record Receiving forms, saved so an interruption
 * does not cost the typing.
 *
 * DELIBERATELY NOT A STATUS ON stock_issues / stock_purchases. Those two are
 * flat line tables with no header row to mark, so a draft flag on them would
 * have to be filtered out of the report service, the ledger, both report
 * screens, both importers' duplicate detection and every stock check — and one
 * missed filter is a wrong balance on the tables the whole store reads. Nothing
 * in here is joined to them, summed with them, or visible to any calculation.
 * A draft cannot move stock because there is no path from this table to stock.
 *
 * The form state is kept as JSON rather than mirrored into columns because a
 * draft is never reported on, summed, joined or searched by content. Its shape
 * IS the form's shape, so normalising it would mean a parallel schema to
 * maintain every time either form gains a field. That is the opposite of
 * purchase_requisitions, where the record is the business document itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_form_drafts', function (Blueprint $table) {
            $table->id();

            // Which form this belongs to — 'issue' or 'receiving'. A string
            // rather than an enum so a third screen needs no migration.
            $table->string('form', 32);

            // Drafts are the author's own: a half-typed form is not something a
            // colleague can read, and two people resuming one would overwrite
            // each other. Cascade, because a draft is worthless without them.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // A readable summary for the list, worked out when the draft is
            // saved — the payload is not queryable, and reading JSON to render
            // a list of names is not something to do per row.
            $table->string('label', 255)->nullable();

            $table->json('payload');

            $table->timestamps();

            // How the list is read: this user's drafts for this screen, newest
            // first. One index covers it.
            $table->index(['created_by', 'form', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_form_drafts');
    }
};
