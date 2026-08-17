<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_item_id',
        'challan_no',
        'rv_no',
        // Challan Date — the supplier's document date, and what the Consumable
        // Stock Report keys its Addition totals and last-addition date on.
        'purchase_date',
        // RCV Date — when the goods physically reached the store.
        'rcv_date',
        'qty',
        'unit_price',
        'supplier_name',
        'general_stock_supplier_id',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'rcv_date' => 'date',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
    ];

    /**
     * SQL that rebuilds "which lines belong to the same delivery".
     *
     * One goods-receiving event = one RV No, one challan and one challan date,
     * shared by all its lines. Legacy rows predate the auto-generated RV, and
     * the same hand-typed number was reused months apart, so the date is part
     * of the key — otherwise two unrelated old deliveries would merge into one
     * group.
     *
     * Lives on the model rather than in a controller because it describes how
     * THIS TABLE groups, and both Purchase History and the Receiving Report
     * have to group identically. One definition, so they cannot drift.
     *
     * CONCAT() is fine on MySQL and PostgreSQL, the two engines this runs on,
     * and absent on SQLite — which is what the test suite uses. So every test
     * that rendered Purchase History failed on "no such function: CONCAT" while
     * production was perfectly healthy, and the screen was effectively untested.
     * SQLite gets the || spelling; MySQL and PostgreSQL get the SQL they always
     * got, character for character, so no production query changes.
     *
     * || is deliberately NOT used for everyone even though PostgreSQL accepts
     * it: on MySQL || is OR by default, which would silently return 0 or 1 as
     * the group key and merge every delivery into one.
     */
    public static function groupKeyExpr(): string
    {
        $date = self::dateKeyExpr('purchase_date');

        return match (\Illuminate\Support\Facades\DB::getDriverName()) {
            'sqlite' => "(COALESCE(rv_no,'')||'|'||COALESCE(challan_no,'')||'|'||{$date})",
            default => "CONCAT(COALESCE(rv_no,''),'|',COALESCE(challan_no,''),'|',{$date})",
        };
    }

    /**
     * A date column rendered as 'YYYY-MM-DD' text, or '' when it is NULL.
     *
     * COALESCE(<date column>, '') cannot be written directly. PostgreSQL
     * resolves a COALESCE to one common type, picks `date` from the column, and
     * then rejects '' as a date — "invalid input syntax for type date". MySQL
     * and MariaDB accept the same expression only because they coerce the
     * column to text instead, so the bug is invisible until the app runs on
     * Postgres. The date is therefore converted to text FIRST, and the fallback
     * is then an empty string against a string, which every engine accepts.
     *
     * Formatted explicitly rather than left to each engine's default rendering:
     * the key only has to be internally consistent, but a key that reads the
     * same everywhere is far easier to compare when a group looks wrong on one
     * environment and right on another.
     */
    private static function dateKeyExpr(string $column): string
    {
        return match (\Illuminate\Support\Facades\DB::getDriverName()) {
            'pgsql' => "COALESCE(TO_CHAR({$column}, 'YYYY-MM-DD'), '')",
            'sqlite' => "COALESCE(STRFTIME('%Y-%m-%d', {$column}), '')",
            default => "COALESCE(DATE_FORMAT({$column}, '%Y-%m-%d'), '')",
        };
    }

    /**
     * Total Value on the Excel "Purchase" sheet. Derived, never stored, so it
     * can never drift out of step with qty × unit price.
     */
    public function getTotalValueAttribute(): float
    {
        return (float) $this->qty * (float) ($this->unit_price ?? 0);
    }

    /** "Month" on the Excel sheet — derived from the challan date. */
    public function getMonthLabelAttribute(): ?string
    {
        return $this->purchase_date?->format('M-y');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * General Stock's own supplier list — deliberately not the Buyer/Style
     * `Supplier` model, which is a separate list for a separate purpose.
     */
    public function generalStockSupplier()
    {
        return $this->belongsTo(GeneralStockSupplier::class, 'general_stock_supplier_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
