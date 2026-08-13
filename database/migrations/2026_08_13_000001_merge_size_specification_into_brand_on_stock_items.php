<?php

use App\Models\StockItem;
use Illuminate\Database\Migrations\Migration;

// General Stock (module A) — Brand, Size and Specification became one field.
//
// The store asked for a single "Brand/Specification" column: in practice the
// three were filled inconsistently, and a store user looking for an item reads
// them as one line anyway. `brand` is now that field. Nothing is renamed and
// nothing is dropped — `size` and `specification` stay on the table with their
// old values, so this is reversible and no history is lost.
//
// This migration only moves what would otherwise stop being visible: an item
// whose brand is blank but which carries a size or a specification would show
// an empty Brand/Specification column after the change. Items that already
// have a brand are left exactly as they are — overwriting a brand someone
// typed is not a migration's business.
return new class extends Migration
{
    public function up(): void
    {
        StockItem::query()
            ->where(fn ($q) => $q->whereNull('brand')->orWhere('brand', ''))
            ->where(fn ($q) => $q->whereNotNull('size')->orWhereNotNull('specification'))
            ->select(['id', 'size', 'specification'])
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $merged = collect([$item->size, $item->specification])
                        ->map(fn ($part) => trim((string) $part))
                        ->filter()
                        ->implode(' - ');

                    if ($merged === '') {
                        continue;
                    }

                    // The column is a 255-char string; a long legacy
                    // specification is trimmed rather than rejected. The full
                    // text is still on the row in `specification`.
                    $item->newQuery()->whereKey($item->id)->update([
                        'brand' => mb_substr($merged, 0, 255),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: the source columns were never emptied. Clearing the
        // filled-in brands would be a guess about which of them this migration
        // wrote, and would delete values a user has since edited by hand.
    }
};
