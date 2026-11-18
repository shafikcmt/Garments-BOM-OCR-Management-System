<?php

namespace App\Models\Concerns;

use App\Models\Supplier;

/**
 * Links a transaction row (PI / payment request, receiving) to the Vendor Master.
 *
 * These screens capture the vendor as text (supplier_name). When a row is created
 * without a supplier_id, the id is filled by an EXACT, case-insensitive match on the
 * vendor's name, legal name or code. The text stays on the row as the historical value:
 * renaming the vendor later never rewrites old transactions. No match leaves it null.
 */
trait LinksVendor
{
    protected static function bootLinksVendor(): void
    {
        static::creating(function ($model) {
            if ($model->supplier_id === null && filled($model->supplier_name)) {
                $model->supplier_id = static::vendorIdForName((string) $model->supplier_name);
            }
        });
    }

    public static function vendorIdForName(string $name): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // Escape LIKE wildcards: whereLike without % is a case-insensitive "equals" on MySQL and PostgreSQL.
        $exact = addcslashes($name, '%_\\');

        $matches = Supplier::query()
            ->where(fn ($q) => $q->whereLike('supplier_name', $exact)->orWhereLike('legal_name', $exact)->orWhereLike('supplier_code', $exact))
            ->limit(2)->pluck('id');

        // Two vendors with the same name: do not guess.
        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    public function vendor()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }
}
