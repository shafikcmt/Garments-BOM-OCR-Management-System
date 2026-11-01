<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\LocalTime;

/**
 * General Stock (module A) — the monthly Purchase Requisition report: every
 * requisition raised in a month compiled into one list, plus the same list
 * split by item category.
 *
 * Replaces the hand-compiled "Month_Of_<Month>.xlsx" workbook, whose "ALL"
 * sheet held the month's items and whose remaining sheets held the same items
 * grouped by category.
 *
 * Reads the SNAPSHOTS stored on each requisition item rather than asking
 * GeneralStockReportService to recompute a month's worth of stock positions.
 * Two reasons, and both matter:
 *
 *   - Correctness. A requisition records the stock position that justified it
 *     on the day it was raised. Recomputing in December would redraw an August
 *     report with December's stock and the approvals would no longer describe
 *     anything that happened.
 *   - Cost. A month can carry hundreds of item lines; re-deriving each one's
 *     position from the purchase and issue history is work already done and
 *     already stored.
 */
class MonthlyRequisitionReportService
{
    /** Separates the Requisition Nos a merged monthly row was built from. */
    private const SOURCE_SEPARATOR = ', ';

    /** How many Requisition Nos the header names before "and N more". */
    public const HEADER_NOS_SHOWN = 5;

    /** Printed on the Section-wise report and on each of its tabs. */
    public const SECTION_STOCK_NOTE = 'Stock is allocated to sections in request order.';

    /** One row per item, across every section. */
    public const VIEW_ALL = 'all';

    /** One row per item per section. */
    public const VIEW_SECTION = 'section';

    /** The ALL rows, grouped by item category. */
    public const VIEW_CATEGORY = 'category';

    /** @return array<string, string> view => label */
    public static function viewLabels(): array
    {
        return [
            self::VIEW_ALL => 'All',
            self::VIEW_CATEGORY => 'By Category',
            self::VIEW_SECTION => 'By Section',
        ];
    }

    /** A tab's kind, which decides its header and signature boxes. */
    public const TAB_ALL = 'all';

    public const TAB_CATEGORY = 'category';

    public const TAB_SECTION = 'section';

    /**
     * The tabs the switch shows, in order, each with its kind and rows:
     *   All         → ALL
     *   By Category → ALL + one per category
     *   By Section  → ALL + one per section
     *
     * The ALL tab is the same everywhere: every section merged, one row per
     * item, stock subtracted once — and the section tabs, allocating that
     * stock in request order, add up to it.
     *
     * @return Collection<string, array{type: string, rows: Collection}>
     */
    public function tabs(Carbon $monthStart, string $view): Collection
    {
        $all = $this->rows($monthStart);
        $tabs = collect(['ALL' => ['type' => self::TAB_ALL, 'rows' => $all]]);

        if ($view === self::VIEW_SECTION) {
            foreach ($this->bySection($this->rows($monthStart, [], self::VIEW_SECTION)) as $section => $rows) {
                // A section literally named "ALL" must not overwrite the ALL tab.
                $tabs->put($tabs->has((string) $section) ? $section.' (Section)' : (string) $section, ['type' => self::TAB_SECTION, 'rows' => $rows]);
            }

            return $tabs;
        }

        if ($view === self::VIEW_CATEGORY) {
            foreach ($this->byCategory($all) as $category => $rows) {
                $tabs->put((string) $category, ['type' => self::TAB_CATEGORY, 'rows' => $rows]);
            }
        }

        return $tabs;
    }

    /**
     * The tabs a download carries: ALL + one per category for All and By
     * Category, ALL + one per section for By Section.
     *
     * @return Collection<string, array{type: string, rows: Collection}>
     */
    public function exportTabs(Carbon $monthStart, string $view): Collection
    {
        return $this->tabs($monthStart, $view === self::VIEW_SECTION ? self::VIEW_SECTION : self::VIEW_CATEGORY);
    }

    /**
     * What a tab's printed header and footer carry (owner, 2026-09-29):
     *
     *   ALL / category tab: Requisition No HAPL/Dept./ALL/<Year>/<Month>/01,
     *     Section ALL, Name of User ALL, Contact = the Contact Person setting,
     *     the FINAL sheet's signature boxes.
     *   Section tab: HAPL/Dept./<SECTION>/<Year>/<Month>/01, the section's
     *     name, its head (section head, else department head, else the
     *     person on the department sheet's head box) with name, designation,
     *     phone and email from the head's profile, and the DEPARTMENT sheet's
     *     signature boxes.
     *
     * Empty values stay null; the templates print "—".
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{type: string, requisition_no: string, section: string, user_name: ?string, designation: ?string, contact: ?string, signatures: array}
     */
    public function tabHeader(string $type, string $name, Collection $rows, Carbon $monthStart): array
    {
        $period = $monthStart->format('Y').'/'.$monthStart->format('F');
        $number = fn (string $token) => PurchaseRequisitionNumberGenerator::PREFIX.'/Dept./'.$token.'/'.$period.'/01';
        $signatures = app(RequisitionSignatureResolver::class);

        if ($type === self::TAB_SECTION) {
            $head = $this->sectionHead($this->sectionFor($name, $rows));

            return [
                'type' => $type,
                'requisition_no' => $number(mb_strtoupper($name)),
                'section' => $name,
                'user_name' => $head?->name ?: null,
                'designation' => trim((string) $head?->designation) ?: null,
                'contact' => RequisitionHeaderResolver::contactOf($head),
                'signatures' => $signatures->forLevel(\App\Models\RequisitionSignatureBlock::LEVEL_DEPARTMENT, $head),
            ];
        }

        return [
            'type' => $type,
            'requisition_no' => $number('ALL'),
            'section' => 'ALL',
            'user_name' => 'ALL',
            'designation' => null,
            'contact' => RequisitionHeaderResolver::contactPersonContact(),
            'signatures' => $signatures->forLevel(\App\Models\RequisitionSignatureBlock::LEVEL_FINAL),
        ];
    }

    /** The section a Section-wise tab stands for: by the rows' section id, else by name. */
    private function sectionFor(string $name, Collection $rows): ?\App\Models\IndentSection
    {
        $id = $rows->pluck('section_id')->filter()->first();

        return ($id ? \App\Models\IndentSection::find($id) : null)
            // LOWER() is the same on MySQL, PostgreSQL and SQLite.
            ?? \App\Models\IndentSection::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    /**
     * Who a section tab names: the section head (Admin > Departments), else
     * the department head, else the specific user on the department sheet's
     * head box (Admin > Requisition Signatures).
     */
    private function sectionHead(?\App\Models\IndentSection $section): ?\App\Models\User
    {
        $head = $section?->head
            ?? \App\Models\RequisitionDepartmentHead::headOf($section?->department_key);

        if ($head) {
            return $head;
        }

        $box = \App\Models\RequisitionSignatureBlock::query()
            ->forLevel(\App\Models\RequisitionSignatureBlock::LEVEL_DEPARTMENT)
            ->where('assignee_type', \App\Models\RequisitionSignatureBlock::ASSIGNEE_USER)
            ->where('stamp_source', \App\Models\RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD)
            ->with('user')->first();

        return $box?->user;
    }

    public function __construct(private readonly PurchaseRequisitionService $requisitions)
    {
    }

    /**
     * Normalise a "YYYY-MM" input into the first day of that month, falling
     * back to the current month for anything unparseable. Mirrors
     * GeneralStockReportService::resolveMonth so both reports read a month the
     * same way.
     */
    public function resolveMonth(?string $month): Carbon
    {
        try {
            return $month ? Carbon::createFromFormat('!Y-m', $month)->startOfMonth() : LocalTime::now()->startOfMonth();
        } catch (\Throwable $e) {
            return LocalTime::now()->startOfMonth();
        }
    }

    /**
     * The month's rows, by THE RULE (docs/10_DEPLOY_NOTES.md, "Monthly
     * requisition rule"): quantities are SUMMED first — per item across every
     * section (VIEW_ALL, VIEW_CATEGORY), or per item per section
     * (VIEW_SECTION) — and stock is compared ONCE per item:
     *
     *   To Be Procured = max(0, total qty − stock in hand)
     *   Amount         = To Be Procured × the item's latest known rate
     *
     * A sheet belongs to the month of its requisition date. Only counted
     * sheets (countedInTotals(), and a department line a final sheet repeats
     * once).
     *
     * @param  array{category?: int|string|null, search?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Carbon $monthStart, array $filters = [], string $view = self::VIEW_ALL): Collection
    {
        $lines = $this->lines($monthStart, $filters)
            ->map(fn (PurchaseRequisitionItem $line) => $this->row($line));

        $byItem = $lines->groupBy(fn (array $row) => $this->itemKey($row));

        $rows = $view === self::VIEW_SECTION
            ? $byItem->flatMap(fn (Collection $group) => $this->splitBySection($group))
            : $byItem->map(fn (Collection $group) => $this->mergeGroup($group));

        return $rows
            ->sortBy([
                fn ($a, $b) => $view === self::VIEW_SECTION ? strcasecmp($a['section'], $b['section']) : 0,
                fn ($a, $b) => strcasecmp($a['category'] ?? '', $b['category'] ?? ''),
                fn ($a, $b) => strcasecmp($a['item_name'] ?? '', $b['item_name'] ?? ''),
            ])
            ->values();
    }

    /**
     * Section-wise rows, keyed by section name and ordered alphabetically.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  rows(…, VIEW_SECTION)
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function bySection(Collection $rows): Collection
    {
        return $rows->groupBy('section')->sortKeys(SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * Every sheet these rows were built from, in natural order — the screen's
     * "Includes N requisitions" list.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    public function requisitionNos(Collection $rows): array
    {
        return $rows->pluck('requisition_nos')->flatten()->filter()->unique()
            ->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values()->all();
    }

    /**
     * The header's "Requisition No": the sheets these rows were built from,
     * comma separated — the first five, then "and N more".
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function requisitionNosLabel(Collection $rows): string
    {
        $nos = collect($this->requisitionNos($rows));

        if ($nos->isEmpty()) {
            return '—';
        }

        $label = $nos->take(self::HEADER_NOS_SHOWN)->implode(self::SOURCE_SEPARATOR);
        $more = $nos->count() - self::HEADER_NOS_SHOWN;

        return $more > 0 ? $label.' and '.$more.' more' : $label;
    }

    /**
     * The lines of ONE requisition, in the same row shape the monthly report
     * uses, so a single requisition can be printed with the same table.
     *
     * Document order is kept (sort_order, then id) rather than the monthly
     * report's category/name sort: this prints the requisition as it was
     * written, matching the view screen line for line.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function requisitionRows(PurchaseRequisition $requisition): Collection
    {
        $requisition->loadMissing([
            'items.stockItem', 'items.itemCategory',
            'indentSection', 'indentPerson',
        ]);

        return $requisition->items
            ->map(function (PurchaseRequisitionItem $line) use ($requisition) {
                // The relation is already loaded the other way round; setting it
                // here stops row() re-querying the parent for every line.
                $line->setRelation('requisition', $requisition);

                return $this->row($line);
            })
            ->values();
    }

    /**
     * The same rows split by category, keyed by category name and ordered
     * alphabetically.
     *
     * Only categories that actually carry a line appear — a month that used
     * five of the thirteen masters produces five groups, not thirteen with
     * eight empty ones.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function byCategory(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn ($row) => $row['category'] ?: 'Uncategorised')
            ->sortKeys();
    }

    /**
     * Headline figures for the month, and the same per category.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        // 'lines' counts the requisition lines the rows were built from, not
        // the rows: one merged row may stand for several lines.
        $perCategory = $this->byCategory($rows)
            ->map(fn (Collection $group) => [
                'lines' => (int) $group->sum('line_count'),
                'items' => $group->pluck('stock_item_id')->unique()->count(),
                'qty_requested' => (float) $group->sum('qty_requested'),
                'to_be_procured' => (float) $group->sum('to_be_procured'),
                'amount' => (float) $group->sum('amount'),
            ]);

        return [
            'requisitions' => $rows->pluck('requisition_ids')->flatten()->unique()->count(),
            'lines' => (int) $rows->sum('line_count'),
            'items' => $rows->pluck('stock_item_id')->unique()->count(),
            'qty_requested' => (float) $rows->sum('qty_requested'),
            'to_be_procured' => (float) $rows->sum('to_be_procured'),
            'amount' => (float) $rows->sum('amount'),
            'per_category' => $perCategory,
        ];
    }

    /** Categories present in the month, for the filter dropdown. */
    public function categoriesInMonth(Carbon $monthStart): Collection
    {
        return $this->lines($monthStart)
            ->map(fn (PurchaseRequisitionItem $line) => optional($line->itemCategory)->name)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The month's item lines, already eager-loaded.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PurchaseRequisitionItem>
     */
    private function lines(Carbon $monthStart, array $filters = []): Collection
    {
        return PurchaseRequisitionItem::query()
            ->with(['stockItem', 'itemCategory', 'requisition.indentSection', 'requisition.indentPerson'])
            ->whereHas('requisition', function ($q) use ($monthStart) {
                $q->whereYear('requisition_date', $monthStart->year)
                    ->whereMonth('requisition_date', $monthStart->month)
                    ->countedInTotals();
            })
            ->notRepeatedOnFinalSheet()
            // A line an approver set to 0 (not approved) is left out of the
            // month; the single sheet's own PDF/Excel still prints it as 0.
            ->where('qty_requested', '>', 0)
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->whereHas(
                'itemCategory',
                fn ($c) => $c->where('name', $category)
            ))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE
                // is case-sensitive and quietly returns less than it should. A
                // miss here reads as a requisition that never happened.
                $q->where(fn ($w) => $w->whereLike('user_dept', $like)
                    ->orWhereLike('specification', $like)
                    ->orWhereHas('stockItem', fn ($i) => $i->where(fn ($n) => $n->whereLike('name', $like)->orWhereLike('sku', $like)))
                    // Lines naming something not in the Item Master have no
                    // stockItem to search; their typed name is searched here.
                    ->orWhereLike('custom_item_name', $like)
                    ->orWhereHas('requisition', fn ($r) => $r->whereLike('requisition_no', $like)));
            })
            ->get();
    }

    /**
     * One stored line, flattened into the shape both the screen and the export
     * read. Every figure is the snapshot frozen when the requisition was
     * raised — nothing here is recomputed.
     *
     * @return array<string, mixed>
     */
    private function row(PurchaseRequisitionItem $line): array
    {
        $requisition = $line->requisition;

        return [
            'stock_item_id' => $line->stock_item_id,
            // displayName() falls back to the typed name for a line naming
            // something not in the Item Master, so such a line prints its item
            // rather than a dash on the report, the PDF and the Excel alike.
            'item_name' => $line->displayName(),
            'sku' => optional($line->stockItem)->sku,
            'is_custom_item' => $line->isCustomItem(),
            'uom' => $line->uom_snapshot,
            'category' => optional($line->itemCategory)->name,
            'specification' => $line->specification,
            'type' => $line->type,
            'user_dept' => $line->user_dept,
            'qty_requested' => (float) $line->qty_requested,
            'stock_in_hand' => $line->stock_in_hand_snapshot === null ? null : (float) $line->stock_in_hand_snapshot,
            'safety_stock' => $line->safety_stock_snapshot === null ? null : (float) $line->safety_stock_snapshot,
            'consumption_last_month' => $line->consumption_last_month_snapshot === null ? null : (float) $line->consumption_last_month_snapshot,
            'last_purchase_qty' => $line->last_purchase_qty_snapshot === null ? null : (float) $line->last_purchase_qty_snapshot,
            'last_purchase_rate' => $line->last_purchase_rate_snapshot === null ? null : (float) $line->last_purchase_rate_snapshot,
            'last_purchase_date' => $line->last_purchase_date_snapshot,
            'to_be_procured' => (float) $line->to_be_procured_qty,
            'rate_appx' => $line->rate_appx === null ? null : (float) $line->rate_appx,
            'amount' => (float) $line->amount,
            'store_pending_qty' => $line->store_pending_qty,
            'accounts_pending_qty' => $line->accounts_pending_qty,
            'remarks' => $line->remarks,

            // Where the line came from. Kept as lists so a merged row can carry
            // several without a second shape.
            'requisition_no' => $requisition?->requisition_no,
            'requisition_nos' => array_filter([$requisition?->requisition_no]),
            'requisition_ids' => array_filter([$requisition?->id]),
            'requisition_date' => $requisition?->requisition_date,
            // The Section-wise key: the sheet's section, else — a final sheet
            // has none — the line's own User Dept.
            'section' => trim((string) (optional($requisition?->indentSection)->name ?: $line->user_dept)) ?: 'No Section',
            'section_id' => $requisition?->indent_section_id,
            'line_count' => 1,
        ];
    }

    /**
     * What makes two lines the same item: the stock item, or — for a typed
     * item with no stock item — its name, trimmed, case-insensitive, spaces
     * collapsed. (Grouping on stock_item_id alone put every typed item in
     * one row.)
     *
     * @param  array<string, mixed>  $row
     */
    private function itemKey(array $row): string
    {
        return $row['stock_item_id']
            ? 'item:'.$row['stock_item_id']
            : 'name:'.mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $row['item_name'])));
    }

    /**
     * The item's rows split per section. Each section's quantity is summed;
     * the item's ONE stock figure is then allocated to the sections in the
     * order they first asked (earliest requisition first), so the sections'
     * To Be Procured add up to exactly the ALL row's (owner, 2026-09-29):
     *
     *   stock 6; Cutting 20 (first), Sewing 5
     *     → Cutting: allocated 6, TBP 14; Sewing: allocated 0, TBP 5
     *
     * "stock_in_hand" on a section row is the ALLOCATED part (the column reads
     * "Stock Allocated"); the item's full stock is item_stock_in_hand. Safety
     * stock and consumption print the item's figures — per item, never split.
     *
     * @param  Collection<int, array<string, mixed>>  $group  one item's rows
     * @return Collection<int, array<string, mixed>>
     */
    private function splitBySection(Collection $group): Collection
    {
        $item = $this->mergeGroup($group);
        $remaining = (float) ($item['stock_in_hand'] ?? 0);

        $rows = $group
            ->groupBy('section')
            ->map(fn (Collection $rows) => $this->mergeGroup($rows))
            ->sortBy([
                fn ($a, $b) => ($a['first_date'] ?? 0) <=> ($b['first_date'] ?? 0),
                fn ($a, $b) => ($a['requisition_ids'][0] ?? 0) <=> ($b['requisition_ids'][0] ?? 0),
            ])
            ->map(function (array $row) use ($item, &$remaining) {
                // The part of the item's stock given to this section.
                $allocated = $item['stock_in_hand'] === null ? null : min($remaining, $row['qty_requested']);
                $toBeProcured = max(0.0, round($row['qty_requested'] - (float) $allocated));
                $remaining = max(0.0, $remaining - $row['qty_requested']);

                return array_merge($row, [
                    // Printed under "Stock Allocated" on the Section-wise
                    // report; the item's full stock stays alongside.
                    'stock_in_hand' => $allocated,
                    'item_stock_in_hand' => $item['stock_in_hand'],
                    'safety_stock' => $item['safety_stock'],
                    'consumption_last_month' => $item['consumption_last_month'],
                    'last_purchase_qty' => $item['last_purchase_qty'],
                    'last_purchase_rate' => $item['last_purchase_rate'],
                    'last_purchase_date' => $item['last_purchase_date'],
                    'rate_appx' => $item['rate_appx'],
                    'to_be_procured' => $toBeProcured,
                    'amount' => $item['rate_appx'] !== null ? $toBeProcured * $item['rate_appx'] : 0.0,
                ]);
            })
            ->values();

        // The item's safety top-up is bought once, not per section: whatever
        // the item total (mergeGroup(), safety included) holds above the
        // section shortfalls goes on the last section, so the sections still
        // add up to the item.
        $extra = $item['to_be_procured'] - (float) $rows->sum('to_be_procured');
        if ($rows->isNotEmpty() && abs($extra) > 0.00001) {
            $last = $rows->pop();
            $last['to_be_procured'] = max(0.0, $last['to_be_procured'] + $extra);
            $last['amount'] = $last['rate_appx'] !== null ? $last['to_be_procured'] * $last['rate_appx'] : 0.0;
            $rows->push($last);
        }

        return $rows;
    }

    /**
     * Break every value in $values on $separator and return the flat list of
     * trimmed, non-empty parts.
     *
     * Needed because a stored cell can itself be a merged list; see the note in
     * mergeByItem().
     *
     * @param  Collection<int, string|null>  $values
     * @return list<string>
     */
    private function explodeAll(Collection $values, string $separator): array
    {
        return $values
            ->flatMap(fn ($value) => explode($separator, (string) $value))
            ->map(fn ($part) => trim($part))
            ->filter(fn ($part) => $part !== '')
            ->values()
            ->all();
    }

    /**
     * Collapse one item's rows (all sections, or one section) into one row.
     *
     * An item has one stock figure for the month, so listing it once per
     * requisition would show that figure several times and, with each line
     * subtracting it again, understate what is left to buy. Quantities are
     * summed, departments and source Requisition Nos gathered into single
     * cells, and stock is subtracted once from the total.
     *
     * The stock, safety, consumption and last-purchase figures are taken from
     * the LATEST requisition to name the item — the most recent reading of a
     * position that moved during the month. The rate is the latest known one:
     * the newest line's Rate Appx., else its last purchase rate.
     *
     * @param  Collection<int, array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function mergeGroup(Collection $group): array
    {
        // Oldest first, so "first non-empty" means the earliest
        // description and the newest snapshot is easy to reach.
        $ordered = $group->sortBy([
            fn ($a, $b) => ($a['requisition_date']?->timestamp ?? 0) <=> ($b['requisition_date']?->timestamp ?? 0),
            fn ($a, $b) => ($a['requisition_ids'][0] ?? 0) <=> ($b['requisition_ids'][0] ?? 0),
        ])->values();

        $latest = $ordered->last();
        $newestFirst = $ordered->reverse();
        $qty = (float) $ordered->sum('qty_requested');
        $stock = $latest['stock_in_hand'];

        // Recomputed from the combined quantity against ONE stock
        // figure — not the sum of the per-requisition shortfalls, which
        // would each have subtracted that same stock again.
        $toBeProcured = $this->requisitions->toBeProcured($qty, $stock, $latest['safety_stock'] ?? null);

        $rate = $newestFirst->pluck('rate_appx')->first(fn ($value) => $value !== null)
            ?? $newestFirst->pluck('last_purchase_rate')->first(fn ($value) => $value !== null);

        return array_merge($latest, [
            'line_count' => (int) $ordered->sum('line_count'),
            'first_date' => $ordered->first()['requisition_date']?->timestamp,
            'qty_requested' => $qty,
            'to_be_procured' => $toBeProcured,
            'rate_appx' => $rate,
            'amount' => $rate !== null ? $toBeProcured * $rate : 0.0,

            'specification' => $ordered->pluck('specification')->first(fn ($v) => $v !== null && $v !== ''),
            'type' => $ordered->pluck('type')->first(fn ($v) => $v !== null && $v !== ''),

            // Split before joining. Each stored value may ALREADY be a
            // merged cell ("Store/Production", written when one
            // requisition served two departments), so treating it as a
            // single name would produce "Store/Production/Store" the
            // moment another requisition mentions Store again. Breaking
            // every cell back into its parts is what makes the
            // department list distinct.
            'user_dept' => $this->requisitions->joinDistinct(
                $this->explodeAll($ordered->pluck('user_dept'), PurchaseRequisitionService::DEPARTMENT_SEPARATOR),
                PurchaseRequisitionService::DEPARTMENT_SEPARATOR
            ),
            'remarks' => $this->requisitions->joinDistinct(
                $this->explodeAll($ordered->pluck('remarks'), '/'),
                ' / '
            ),

            'requisition_no' => $this->requisitions->joinDistinct(
                $ordered->pluck('requisition_no')->map(fn ($v) => trim((string) $v))->all(),
                self::SOURCE_SEPARATOR
            ),
            'requisition_nos' => $ordered->pluck('requisition_nos')->flatten()->unique()->values()->all(),
            'requisition_ids' => $ordered->pluck('requisition_ids')->flatten()->unique()->values()->all(),

            'store_pending_qty' => $ordered->sum('store_pending_qty') ?: null,
            'accounts_pending_qty' => $ordered->sum('accounts_pending_qty') ?: null,
        ]);
    }
}
