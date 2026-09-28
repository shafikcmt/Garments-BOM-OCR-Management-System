<?php

namespace App\Services;

use App\Models\Department;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockIssue;
use App\Models\StockPurchase;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Requirement vs Issue — per section and item, how much was requested on
 * requisitions against how much the Store actually issued. Replaces the
 * Store's hand-kept "Requirement against consume qty" workbook.
 *
 * READ ONLY. Two grouped queries (requested, issued) and three option lists;
 * everything else is arithmetic on the grouped rows.
 *
 * Requested:
 *   - Department sheets, grouped by their section, under the Part A counting
 *     rule (PurchaseRequisition::countedInTotals()).
 *   - Final sheets carry no section. A final line adds to a "Not assigned to
 *     a section" row only for the quantity the same month's department lines
 *     of the same item have not already counted:
 *       not_assigned = max(0, final qty − department qty)
 *     so the grand total never counts one quantity twice.
 *
 * Issued: stock_issues for the same section + item + period. Issues carry
 * the paper issue-slip number, never a requisition number, so they cannot
 * be tied to a particular requisition.
 *
 * Values: Qty × Approx Rate. The rate is the requisition line's rate, else
 * the last purchase rate the line recorded when raised, else the latest
 * Receiving (GRN) line for the item up to the end of the period, else the
 * Item Master rate; none of these → "—", and the row is left out of the
 * values. A zero rate counts as none.
 *
 * Issued % (overall) = issued value of REQUESTED items / requested value —
 * value-weighted, never an average of row percentages. Items issued but
 * never requested ("Not Requested") are valued separately.
 */
class RequirementVsIssueService
{
    public const PERMISSION = 'store.requirement_issue.view';

    public const STATUS_FULL = 'fully_issued';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_NOT_ISSUED = 'not_issued';
    public const STATUS_OVER = 'over_issued';
    public const STATUS_NOT_REQUESTED = 'not_requested';

    /** Section filter value for sections no department claims. */
    public const NO_DEPARTMENT = 'none';

    /** Category filter value for items with no category. */
    public const UNCATEGORIZED = 'none';

    private const EPSILON = 0.00001;

    /** @var array<string, string> sortable column => row key */
    public const SORTABLE = [
        'item' => 'item_name',
        'section' => 'section_name',
        'rate' => 'rate',
        'requested_qty' => 'requested_qty',
        'requested_value' => 'requested_value',
        'issued_qty' => 'issued_qty',
        'issued_value' => 'issued_value',
        'balance_qty' => 'balance_qty',
        'balance_value' => 'balance_value',
        'pct' => 'pct',
    ];

    /** @return array<string, string> status => label, in filter order */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_FULL => 'Fully Issued',
            self::STATUS_PARTIAL => 'Partial',
            self::STATUS_NOT_ISSUED => 'Not Issued',
            self::STATUS_OVER => 'Over Issued',
            self::STATUS_NOT_REQUESTED => 'Not Requested',
        ];
    }

    /**
     * Who may open this view, the Stock Summary card and their exports:
     * whoever holds store.requirement_issue.view. Purely the permission — no
     * role or department is hard-coded. Admin grants or revokes it per user
     * on the user permission screen, and passes it through the super-admin
     * rule. The Store head gets it automatically (see Department::booted).
     */
    public static function canView(?User $user): bool
    {
        return $user !== null && $user->can(self::PERMISSION);
    }

    /**
     * Give the Store head the permission, directly (permissions here are
     * per-user grants). Called when Admin sets or changes the head. Nothing
     * is revoked from anyone. There is no permission audit table, so the
     * grant is written to the application log.
     */
    public static function grantToStoreHead(int $userId): void
    {
        $user = User::find($userId);

        if (! $user || $user->hasDirectPermission(self::PERMISSION)) {
            return;
        }

        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(self::PERMISSION, 'web'));

        \Illuminate\Support\Facades\Log::info('Requirement vs Issue permission granted to the new Store head.', [
            'user_id' => $user->id,
            'user' => $user->name,
            'by_user_id' => auth()->id(),
        ]);
    }

    /**
     * Take the permission back from a Store head who has been replaced. A
     * direct grant only — Admin can give it back by hand on the user
     * permission screen. Logged like the grant.
     */
    public static function revokeFromOldStoreHead(int $userId): void
    {
        $user = User::find($userId);

        if (! $user || ! $user->hasDirectPermission(self::PERMISSION)) {
            return;
        }

        $user->revokePermissionTo(self::PERMISSION);

        \Illuminate\Support\Facades\Log::info('Requirement vs Issue permission revoked from the previous Store head.', [
            'user_id' => $user->id,
            'user' => $user->name,
            'by_user_id' => auth()->id(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    /**
     * Raw query input → clean filters. Unknown ids and statuses are dropped;
     * a bad month falls back to the current month (month-end safe).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filters(array $input, array $options): array
    {
        $from = $this->month($input['from'] ?? null);
        $to = $this->month($input['to'] ?? null, $from);

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $known = fn (string $key, Collection $valid) => collect((array) ($input[$key] ?? []))
            ->map(fn ($v) => (string) $v)
            ->filter(fn ($v) => $valid->contains($v))
            ->unique()->values()->all();

        $sort = (string) ($input['sort'] ?? 'balance_value');

        return [
            'from' => $from,
            'to' => $to,
            'departments' => $known('departments', $options['departments']->keys()->map(fn ($k) => (string) $k)->push(self::NO_DEPARTMENT)),
            'sections' => $known('sections', $options['sections']->pluck('id')->map(fn ($id) => (string) $id)),
            'categories' => $known('categories', $options['categories']->keys()->map(fn ($k) => (string) $k)->push(self::UNCATEGORIZED)),
            'statuses' => $known('statuses', collect(array_keys(self::statusLabels()))),
            'requisition_no' => mb_substr(trim((string) ($input['requisition_no'] ?? '')), 0, 100),
            'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 100),
            'sort' => array_key_exists($sort, self::SORTABLE) ? $sort : 'balance_value',
            'dir' => ($input['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** "2026-08" → 1 Aug 2026. '!Y-m' so the 29th–31st never roll into the next month. */
    private function month(mixed $value, ?Carbon $default = null): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return Carbon::createFromFormat('!Y-m', $value)->startOfMonth();
        }

        return $default?->copy() ?? now()->startOfMonth();
    }

    /**
     * Filter choices, loaded once.
     *
     * @return array{departments: Collection<string, string>, sections: Collection<int, array>, categories: Collection<int, string>}
     */
    public function options(): array
    {
        $departments = Department::query()->active()->ordered()->pluck('label', 'key');

        $sections = IndentSection::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'department_key'])
            ->map(fn (IndentSection $s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'department_key' => $s->department_key && $departments->has($s->department_key) ? $s->department_key : null,
                'department' => $s->department_key && $departments->has($s->department_key)
                    ? $departments[$s->department_key]
                    : 'No department',
            ]);

        return [
            'departments' => $departments,
            'sections' => $sections,
            'categories' => ItemCategory::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }

    /**
     * Which sections the Section filter lists: those of the checked
     * departments, or all when none is checked.
     *
     * @return Collection<int, array>
     */
    public function sectionsFor(array $options, array $departments): Collection
    {
        if ($departments === []) {
            return $options['sections'];
        }

        return $options['sections']->filter(fn ($s) => in_array($s['department_key'] ?? self::NO_DEPARTMENT, $departments, true))->values();
    }

    /*
    |--------------------------------------------------------------------------
    | The report
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{rows: Collection<int, array>, summary: array, sections: Collection<int, array>}
     */
    public function build(array $filters, array $options): array
    {
        $start = $filters['from']->copy()->startOfMonth();
        $end = $filters['to']->copy()->endOfMonth();

        $rows = $this->merge($this->requested($start, $end, $filters), $this->issued($start, $end));

        $this->applyRates($rows, $end);

        $sectionNames = IndentSection::withTrashed()->pluck('name', 'id');
        $sectionDepartments = $options['sections']->pluck('department_key', 'id');
        $departmentLabels = $options['departments'];
        $categoryNames = $options['categories'];

        $rows = $rows->map(function (array $row) use ($sectionNames, $sectionDepartments, $departmentLabels, $categoryNames) {
            $row['section_name'] = $row['section_id'] === null
                ? 'Not assigned to a section'
                : ($sectionNames[$row['section_id']] ?? 'Section #'.$row['section_id']);
            $row['department_key'] = $row['section_id'] === null ? null : ($sectionDepartments[$row['section_id']] ?? null);
            $row['department'] = $row['department_key'] ? ($departmentLabels[$row['department_key']] ?? '—') : '—';
            $row['category'] = $row['category_id'] ? ($categoryNames[$row['category_id']] ?? null) : null;

            return $this->finish($row);
        });

        $rows = $this->applyFilters($rows, $filters);

        $counts = collect(array_keys(self::statusLabels()))
            ->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])
            ->all();

        // The Status filter narrows the table last, so the count line above
        // it can still say how many of each there are.
        $visible = $filters['statuses'] === []
            ? $rows
            : $rows->filter(fn ($r) => in_array($r['status'], $filters['statuses'], true));

        $visible = $this->sort($visible, $filters['sort'], $filters['dir'])->values();

        return [
            'rows' => $visible,
            'summary' => $this->summary($visible) + ['counts' => $counts],
            'sections' => $this->bySection($visible),
        ];
    }

    /**
     * Requested quantity and value per section + item, and the Not-assigned
     * remainder of the final sheets. Grouped by requisition_date in SQL so
     * the month can be read in PHP — a month function differs per database.
     *
     * @return array<string, array>
     */
    private function requested(Carbon $start, Carbon $end, array $filters): array
    {
        $grouped = $this->requestedQuery($start, $end, $filters)->get();

        return $this->requestedRows($grouped);
    }

    /** The requested side's one grouped query — public so its SQL can be read per database. */
    public function requestedQuery(Carbon $start, Carbon $end, array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $rate = 'COALESCE(purchase_requisition_items.rate_appx, purchase_requisition_items.last_purchase_rate_snapshot)';

        return PurchaseRequisitionItem::query()
            ->join('purchase_requisitions', 'purchase_requisitions.id', '=', 'purchase_requisition_items.purchase_requisition_id')
            ->whereIn('purchase_requisitions.id', PurchaseRequisition::query()->countedInTotals()->select('id'))
            ->whereBetween('purchase_requisitions.requisition_date', [$start->toDateString(), $end->toDateString()])
            ->when($filters['requisition_no'] !== '', fn ($q) => $q->whereLike('purchase_requisitions.requisition_no', '%'.$filters['requisition_no'].'%'))
            ->groupBy(
                'purchase_requisitions.level',
                'purchase_requisitions.indent_section_id',
                'purchase_requisitions.requisition_date',
                'purchase_requisition_items.stock_item_id',
                'purchase_requisition_items.custom_item_name',
            )
            ->select([
                'purchase_requisitions.level',
                'purchase_requisitions.indent_section_id as section_id',
                'purchase_requisitions.requisition_date',
                'purchase_requisition_items.stock_item_id',
                'purchase_requisition_items.custom_item_name',
            ])
            ->selectRaw('SUM(purchase_requisition_items.qty_requested) as qty')
            ->selectRaw("SUM(CASE WHEN {$rate} IS NULL THEN 0 ELSE purchase_requisition_items.qty_requested END) as rated_qty")
            ->selectRaw("SUM(CASE WHEN {$rate} IS NULL THEN 0 ELSE purchase_requisition_items.qty_requested * {$rate} END) as rated_value")
            ->selectRaw('MAX(purchase_requisition_items.item_category_id) as category_id')
            ->selectRaw('MAX(purchase_requisition_items.uom_snapshot) as uom')
            ->selectRaw('MAX(purchase_requisition_items.specification) as specification');
    }

    /** @return array<string, array> */
    private function requestedRows(Collection $grouped): array
    {
        $rows = [];
        $departmentQty = [];   // month|item => qty on department sheets
        $final = [];           // month|item => the final sheets' qty, value, details

        foreach ($grouped as $g) {
            $itemKey = $this->itemKey($g->stock_item_id, $g->custom_item_name);

            if ($itemKey === null) {
                continue;
            }

            $monthItem = Carbon::parse($g->requisition_date)->format('Y-m').'|'.$itemKey;
            $details = [
                'item_id' => $g->stock_item_id ? (int) $g->stock_item_id : null,
                'custom_item_name' => $g->custom_item_name,
                'category_id' => $g->category_id ? (int) $g->category_id : null,
                'uom' => $g->uom,
                'specification' => $g->specification,
            ];

            if ($g->level === PurchaseRequisition::LEVEL_FINAL) {
                $final[$monthItem] ??= ['item_key' => $itemKey, 'qty' => 0.0, 'rated_qty' => 0.0, 'rated_value' => 0.0] + $details;
                $final[$monthItem]['qty'] += (float) $g->qty;
                $final[$monthItem]['rated_qty'] += (float) $g->rated_qty;
                $final[$monthItem]['rated_value'] += (float) $g->rated_value;

                continue;
            }

            $departmentQty[$monthItem] = ($departmentQty[$monthItem] ?? 0.0) + (float) $g->qty;
            $this->addRequested($rows, $g->section_id ? (int) $g->section_id : null, $itemKey, $details,
                (float) $g->qty, (float) $g->rated_qty, (float) $g->rated_value);
        }

        foreach ($final as $monthItem => $f) {
            $remainder = max(0.0, $f['qty'] - ($departmentQty[$monthItem] ?? 0.0));

            if ($remainder <= self::EPSILON) {
                continue;
            }

            // The remainder carries the final sheets' own average rate.
            $share = $f['rated_qty'] > 0 ? min(1.0, $remainder / $f['qty']) : 0.0;
            $this->addRequested($rows, null, $f['item_key'], $f, $remainder,
                $f['rated_qty'] * $share, $f['rated_value'] * $share);
        }

        return $rows;
    }

    private function addRequested(array &$rows, ?int $sectionId, string $itemKey, array $details, float $qty, float $ratedQty, float $ratedValue): void
    {
        $key = ($sectionId ?? 'na').'|'.$itemKey;

        $rows[$key] ??= $this->blankRow($sectionId, $itemKey, $details);
        $rows[$key]['requested_qty'] += $qty;
        $rows[$key]['rated_qty'] += $ratedQty;
        $rows[$key]['rated_value'] += $ratedValue;
        $rows[$key]['uom'] ??= $details['uom'] ?? null;
        $rows[$key]['specification'] ??= $details['specification'] ?? null;
        $rows[$key]['category_id'] ??= $details['category_id'] ?? null;
    }

    /**
     * Issued quantity per section + item, in one grouped query.
     *
     * @return Collection<int, object>
     */
    private function issued(Carbon $start, Carbon $end): Collection
    {
        return $this->issuedQuery($start, $end)->get();
    }

    /** The issued side's one grouped query — public so its SQL can be read per database. */
    public function issuedQuery(Carbon $start, Carbon $end): \Illuminate\Database\Eloquent\Builder
    {
        return StockIssue::query()
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('indent_section_id', 'stock_item_id', 'item_description')
            ->select(['indent_section_id as section_id', 'stock_item_id', 'item_description'])
            ->selectRaw('SUM(qty) as qty')
            ->selectRaw('MAX(item_category_id) as category_id');
    }

    /** @return Collection<int, array> */
    private function merge(array $rows, Collection $issued): Collection
    {
        foreach ($issued as $i) {
            $itemKey = $this->itemKey($i->stock_item_id, $i->stock_item_id ? null : $i->item_description);

            if ($itemKey === null) {
                continue;
            }

            $sectionId = $i->section_id ? (int) $i->section_id : null;
            $key = ($sectionId ?? 'na').'|'.$itemKey;

            $rows[$key] ??= $this->blankRow($sectionId, $itemKey, [
                'item_id' => $i->stock_item_id ? (int) $i->stock_item_id : null,
                'custom_item_name' => $i->stock_item_id ? null : $i->item_description,
                'category_id' => $i->category_id ? (int) $i->category_id : null,
            ]);
            $rows[$key]['issued_qty'] += (float) $i->qty;
        }

        return collect(array_values($rows));
    }

    private function blankRow(?int $sectionId, string $itemKey, array $details): array
    {
        return [
            'section_id' => $sectionId,
            'item_key' => $itemKey,
            'item_id' => $details['item_id'] ?? null,
            'custom_item_name' => $details['custom_item_name'] ?? null,
            'category_id' => $details['category_id'] ?? null,
            'uom' => $details['uom'] ?? null,
            'specification' => $details['specification'] ?? null,
            'requested_qty' => 0.0,
            'rated_qty' => 0.0,
            'rated_value' => 0.0,
            'issued_qty' => 0.0,
        ];
    }

    /** Same key as the double-count rule: stock item id, else the typed name normalised. */
    private function itemKey(mixed $stockItemId, ?string $name): ?string
    {
        if ($stockItemId) {
            return 'item:'.(int) $stockItemId;
        }

        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name)));

        return $name === '' ? null : 'name:'.$name;
    }

    /**
     * Item names and brands, and the rate: requested rows keep their own
     * (line rate, else the line's last purchase rate); anything still
     * without one takes the latest Receiving (GRN) line up to the period
     * end, else the Item Master rate. One query each, never one per row.
     */
    private function applyRates(Collection &$rows, Carbon $end): void
    {
        $itemIds = $rows->pluck('item_id')->filter()->unique()->values()->all();

        $items = DB::table('stock_items')->whereIn('id', $itemIds)
            ->get(['id', 'name', 'brand', 'specification', 'uom', 'item_category_id', 'unit_price'])->keyBy('id');

        $needRate = $rows->filter(fn ($r) => $r['rated_qty'] <= self::EPSILON && $r['item_id'])
            ->pluck('item_id')->unique()->values()->all();

        $lastRates = $needRate === [] ? collect() : StockPurchase::query()
            ->whereIn('stock_item_id', $needRate)
            ->whereNotNull('purchase_date')
            ->where('unit_price', '>', 0)
            ->whereDate('purchase_date', '<=', $end->toDateString())
            ->orderByDesc('purchase_date')->orderByDesc('id')
            ->get(['stock_item_id', 'unit_price'])
            ->groupBy('stock_item_id')
            ->map(fn ($g) => (float) $g->first()->unit_price);

        $rows = $rows->map(function (array $row) use ($items, $lastRates) {
            $item = $row['item_id'] ? ($items[$row['item_id']] ?? null) : null;

            $row['item_name'] = $item->name ?? ($row['custom_item_name'] ?: '—');
            $row['uom'] = $row['uom'] ?: ($item->uom ?? null);
            $row['specification'] = trim(implode(' / ', array_filter([
                $row['specification'] ?: ($item->specification ?? null),
                $item->brand ?? null,
            ]))) ?: null;
            $row['category_id'] = $row['category_id'] ?: ($item->item_category_id ?? null);

            $masterRate = $item && (float) $item->unit_price > 0 ? (float) $item->unit_price : null;

            $row['rate'] = $row['rated_qty'] > self::EPSILON
                ? $row['rated_value'] / $row['rated_qty']
                : ($row['item_id'] ? ($lastRates[$row['item_id']] ?? $masterRate) : null);

            return $row;
        });
    }

    /** Values, balance, row % and status for one row. */
    private function finish(array $row): array
    {
        $requested = $row['requested_qty'];
        $issued = $row['issued_qty'];
        $rate = $row['rate'];

        $row['balance_qty'] = $requested - $issued;
        $row['requested_value'] = $rate === null ? null : $requested * $rate;
        $row['issued_value'] = $rate === null ? null : $issued * $rate;
        $row['balance_value'] = $rate === null ? null : $row['balance_qty'] * $rate;
        $row['pct'] = $requested > self::EPSILON ? min(1.0, $issued / $requested) * 100 : null;

        $row['status'] = match (true) {
            $requested <= self::EPSILON => self::STATUS_NOT_REQUESTED,
            $issued <= self::EPSILON => self::STATUS_NOT_ISSUED,
            $issued - $requested > self::EPSILON => self::STATUS_OVER,
            abs($issued - $requested) <= self::EPSILON => self::STATUS_FULL,
            default => self::STATUS_PARTIAL,
        };

        $row['status_label'] = self::statusLabels()[$row['status']];

        return $row;
    }

    /** Department, section, category and search — every filter except Status. */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $sectionFilter = $filters['sections'] !== [] || $filters['departments'] !== [];

        return $rows->filter(function (array $row) use ($filters, $sectionFilter) {
            // "Not assigned" has no section, so any section or department
            // filter leaves it out.
            if ($sectionFilter && $row['section_id'] === null) {
                return false;
            }

            if ($filters['departments'] !== []
                && ! in_array($row['department_key'] ?? self::NO_DEPARTMENT, $filters['departments'], true)) {
                return false;
            }

            if ($filters['sections'] !== [] && ! in_array((string) $row['section_id'], $filters['sections'], true)) {
                return false;
            }

            if ($filters['categories'] !== []
                && ! in_array($row['category_id'] ? (string) $row['category_id'] : self::UNCATEGORIZED, $filters['categories'], true)) {
                return false;
            }

            if ($filters['search'] !== '') {
                $haystack = mb_strtolower($row['item_name'].' '.($row['specification'] ?? ''));

                if (! str_contains($haystack, mb_strtolower($filters['search']))) {
                    return false;
                }
            }

            // A requisition number narrows the requested side only; an issue
            // cannot be tied to a requisition, so items it did not request
            // drop out rather than show as Not Requested.
            if ($filters['requisition_no'] !== '' && $row['requested_qty'] <= self::EPSILON) {
                return false;
            }

            return true;
        });
    }

    private function sort(Collection $rows, string $sort, string $dir): Collection
    {
        $key = self::SORTABLE[$sort];

        return $rows->sort(function ($a, $b) use ($key, $dir) {
            // Blanks (no rate, no %) always last, whichever way.
            if ($a[$key] === null || $b[$key] === null) {
                return ($a[$key] === null) <=> ($b[$key] === null);
            }

            $cmp = is_string($a[$key]) ? strcasecmp($a[$key], $b[$key]) : $a[$key] <=> $b[$key];

            return ($dir === 'asc' ? $cmp : -$cmp) ?: strcasecmp($a['item_name'], $b['item_name']);
        });
    }

    /** Cards and totals row, from exactly the rows shown. */
    public function summary(Collection $rows): array
    {
        $requested = $rows->where('status', '!=', self::STATUS_NOT_REQUESTED);
        $notRequested = $rows->where('status', self::STATUS_NOT_REQUESTED);

        $requestedValue = (float) $requested->sum(fn ($r) => $r['requested_value'] ?? 0);
        $issuedValue = (float) $requested->sum(fn ($r) => $r['issued_value'] ?? 0);

        // The totals row covers REQUESTED items only, quantities and values
        // alike, so it reads consistently with Issued %. Items issued without
        // a requisition are totalled on their own line.
        return [
            'requested_qty' => (float) $requested->sum('requested_qty'),
            'issued_qty' => (float) $requested->sum('issued_qty'),
            'balance_qty' => (float) $requested->sum('balance_qty'),
            'not_requested_qty' => (float) $notRequested->sum('issued_qty'),
            'requested_value' => $requestedValue,
            'issued_value' => $issuedValue,
            'balance_value' => $requestedValue - $issuedValue,
            'pct' => $requestedValue > self::EPSILON ? $issuedValue / $requestedValue * 100 : null,
            'not_requested_value' => (float) $notRequested->sum(fn ($r) => $r['issued_value'] ?? 0),
            'unrated' => $rows->whereNull('rate')->count(),
        ];
    }

    /**
     * One line per section in view, for the section table.
     *
     * @return Collection<int, array>
     */
    private function bySection(Collection $rows): Collection
    {
        return $rows->groupBy(fn ($r) => $r['section_id'] ?? 'na')
            ->map(function (Collection $group) {
                $first = $group->first();
                $summary = $this->summary($group);

                return [
                    'section_id' => $first['section_id'],
                    'section_name' => $first['section_name'],
                    'department' => $first['department'],
                    'items' => $group->count(),
                    'requested_value' => $summary['requested_value'],
                    'issued_value' => $summary['issued_value'],
                    'pct' => $summary['pct'],
                ];
            })
            ->sortByDesc('requested_value')
            ->values();
    }

    /** "Dept.: CAD & Sample · Month of August 2026" — the filters, said as a title. */
    public function title(array $filters, array $options): string
    {
        $parts = [];

        if ($filters['departments'] !== []) {
            $parts[] = 'Dept.: '.collect($filters['departments'])
                ->map(fn ($k) => $k === self::NO_DEPARTMENT ? 'No department' : ($options['departments'][$k] ?? $k))
                ->implode(', ');
        }

        if ($filters['sections'] !== []) {
            $parts[] = 'Section: '.$options['sections']->whereIn('id', array_map('intval', $filters['sections']))->pluck('name')->implode(', ');
        }

        if ($filters['categories'] !== []) {
            $parts[] = 'Category: '.collect($filters['categories'])
                ->map(fn ($k) => $k === self::UNCATEGORIZED ? 'Uncategorized' : ($options['categories'][$k] ?? $k))
                ->implode(', ');
        }

        if ($filters['statuses'] !== []) {
            $parts[] = 'Status: '.collect($filters['statuses'])->map(fn ($s) => self::statusLabels()[$s])->implode(', ');
        }

        if ($filters['requisition_no'] !== '') {
            $parts[] = 'Requisition: '.$filters['requisition_no'];
        }

        if ($filters['search'] !== '') {
            $parts[] = 'Search: "'.$filters['search'].'"';
        }

        $parts[] = $this->periodLabel($filters);

        return implode(' · ', $parts);
    }

    public function periodLabel(array $filters): string
    {
        return $filters['from']->equalTo($filters['to'])
            ? 'Month of '.$filters['from']->format('F Y')
            : $filters['from']->format('F Y').' – '.$filters['to']->format('F Y');
    }
}
