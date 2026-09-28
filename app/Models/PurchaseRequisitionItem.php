<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * General Stock (module A) — one item line of a Purchase Requisition.
 *
 * The *_snapshot fields are frozen copies of numbers that GeneralStockReportService
 * derives live. They are stored, not re-derived, because a requisition is a
 * document: it has to keep showing the stock position that justified it on the
 * day it was raised. See the migration for the full reasoning.
 */
class PurchaseRequisitionItem extends Model
{
    use HasFactory;

    public const TYPE_REPLACE = 'replace';
    public const TYPE_NEW = 'new';

    protected $fillable = [
        'purchase_requisition_id',
        'stock_item_id',
        'custom_item_name',
        'item_category_id',
        'sort_order',
        'uom_snapshot',
        'specification',
        'type',
        'user_dept',
        'qty_requested',
        'stock_in_hand_snapshot',
        'safety_stock_snapshot',
        'consumption_last_month_snapshot',
        'last_purchase_qty_snapshot',
        'last_purchase_rate_snapshot',
        'last_purchase_date_snapshot',
        'to_be_procured_qty',
        'rate_appx',
        'amount',
        'store_pending_qty',
        'accounts_pending_qty',
        'remarks',
    ];

    protected $casts = [
        'qty_requested' => 'decimal:4',
        'stock_in_hand_snapshot' => 'decimal:4',
        'safety_stock_snapshot' => 'decimal:4',
        'consumption_last_month_snapshot' => 'decimal:4',
        'last_purchase_qty_snapshot' => 'decimal:4',
        'last_purchase_rate_snapshot' => 'decimal:4',
        'last_purchase_date_snapshot' => 'date',
        'to_be_procured_qty' => 'decimal:4',
        'rate_appx' => 'decimal:4',
        'amount' => 'decimal:4',
        'store_pending_qty' => 'decimal:4',
        'accounts_pending_qty' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    /**
     * A line naming something that is not in the Item Master.
     *
     * The department typed the name because the picker had nothing to offer.
     * Such a line carries no stock_item_id, so it has no UOM, no category and
     * no stock or last-purchase figures — there is no master record to read
     * them from.
     */
    public function isCustomItem(): bool
    {
        return $this->stock_item_id === null && filled($this->custom_item_name);
    }

    /** The query form of isCustomItem(). */
    public function scopeNotInItemMaster(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('stock_item_id')->whereNotNull('custom_item_name')->where('custom_item_name', '<>', '');
    }

    /**
     * The Store added this line's item to the Item Master: point the line at
     * the new master record and take its Uom and category, so the sheet reads
     * like any other line from here on. The typed name is dropped because
     * the master's name now stands for it. Quantities, rate and the frozen
     * figures are left alone — there was no stock to snapshot when the line
     * was raised, and rewriting history is not this method's job.
     */
    public function linkToStockItem(StockItem $item): void
    {
        $this->update([
            'stock_item_id' => $item->id,
            'custom_item_name' => null,
            'uom_snapshot' => $item->uom,
            'item_category_id' => $item->item_category_id,
        ]);
    }

    /**
     * What this line is called, wherever it is printed.
     *
     * One definition, used by the screen, the single-requisition document and
     * the monthly report, so a custom item can never show as a blank dash on
     * one of them and a name on another.
     */
    public function displayName(): string
    {
        return $this->stockItem?->name
            ?? ($this->custom_item_name ?: '—');
    }

    /** Said next to the name, so Store knows this one still has to be added. */
    public const NOT_IN_MASTER_NOTE = 'Not in Item Master';

    /** @return array<string, string> type => label */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_REPLACE => 'Replace',
            self::TYPE_NEW => 'New',
        ];
    }

    /**
     * Leaves out department lines a counted final sheet repeats, so the
     * Store's consolidated sheet and the department's own are not both
     * counted. Interim, until final sheets link to the department sheets
     * they merge (Step 4).
     *
     * A department line is repeated when a final sheet in countedInTotals(),
     * dated in the same month, carries the same item: the same stock item,
     * or — for a typed item with no stock item — the same name, compared
     * trimmed, case-insensitive and with spaces collapsed. A department line
     * the final sheet does not carry still counts.
     *
     * Matched in PHP rather than SQL: the month comparison would need a
     * different date function per database (MySQL live-dev, PostgreSQL live,
     * SQLite tests), and the lines involved are few.
     */
    public function scopeNotRepeatedOnFinalSheet(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotIn($query->getModel()->getTable().'.id', self::repeatedOnFinalSheetIds());
    }

    /** @return list<int> ids of the department lines a final sheet repeats */
    public static function repeatedOnFinalSheetIds(): array
    {
        $counted = fn (string $level) => self::query()
            ->join('purchase_requisitions', 'purchase_requisitions.id', '=', 'purchase_requisition_items.purchase_requisition_id')
            ->whereIn('purchase_requisitions.id', PurchaseRequisition::query()->countedInTotals()->select('id'))
            ->where('purchase_requisitions.level', $level)
            ->get([
                'purchase_requisition_items.id',
                'purchase_requisition_items.stock_item_id',
                'purchase_requisition_items.custom_item_name',
                'purchase_requisitions.requisition_date',
            ]);

        $onFinal = [];

        foreach ($counted(PurchaseRequisition::LEVEL_FINAL) as $line) {
            if ($key = self::monthItemKey($line)) {
                $onFinal[$key] = true;
            }
        }

        if ($onFinal === []) {
            return [];
        }

        return $counted(PurchaseRequisition::LEVEL_DEPARTMENT)
            ->filter(fn ($line) => isset($onFinal[self::monthItemKey($line) ?? '']))
            ->map(fn ($line) => (int) $line->id)
            ->values()
            ->all();
    }

    /** "2026-08|item:56" / "2026-08|name:hand sanitiser 5l", or null for a line naming nothing. */
    private static function monthItemKey(self $line): ?string
    {
        $date = $line->requisition_date;

        if (! $date) {
            return null;
        }

        $month = \Illuminate\Support\Carbon::parse($date)->format('Y-m');

        if ($line->stock_item_id) {
            return $month.'|item:'.$line->stock_item_id;
        }

        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $line->custom_item_name)));

        return $name === '' ? null : $month.'|name:'.$name;
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function itemCategory()
    {
        return $this->belongsTo(ItemCategory::class);
    }
}
