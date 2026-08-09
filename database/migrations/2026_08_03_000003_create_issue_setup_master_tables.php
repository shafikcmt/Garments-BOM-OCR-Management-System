<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — "Issue Setup" master data for the three columns
// the Excel "Consumption" sheet fills by hand: Indent Section, Indent Person
// and Approved By. In the Excel these are free text, which is why the live file
// carries 42 spellings of the same handful of approvers ("Asraf Sir", "Asraf
// Sir", "Ashif Sir"). Making them masters is the whole point of the change.
//
// All three carry soft deletes: a name that has already been used on an issue
// must stay resolvable for old records, so retiring one hides it from the
// dropdown without breaking history.
//
// Note: this does NOT touch config('stock.indent_sections'), which the separate
// Buyer/Style Bulk Issue screen (module B) still reads. That screen is
// unchanged by design.
return new class extends Migration
{
    /** The three masters are identical in shape, so they are built from one spec. */
    private const TABLES = ['indent_sections', 'indent_persons', 'issue_approvers'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();

                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->text('remarks')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                // No DB unique index here on purpose: MySQL treats every NULL
                // deleted_at as distinct, so a (name, deleted_at) unique would
                // not actually stop two live duplicates, and a unique on name
                // alone would let one soft-deleted row block re-adding that
                // name forever. Uniqueness among live rows is enforced in the
                // controller's validation instead.
                $table->index(['is_active', 'name']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
