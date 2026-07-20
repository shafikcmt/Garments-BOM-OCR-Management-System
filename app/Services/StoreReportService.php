<?php

namespace App\Services;

use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MaterialStockLedger;
use Illuminate\Support\Collection;

/**
 * Store reporting (read-only). Aggregates the existing movement tables
 * (material_receivings + material_bulk_issues) from three angles — style, buyer
 * and material — and pairs each group with its lifetime closing balance from the
 * cached material_stock_ledgers table.
 *
 * Two deliberately separate measures, never mixed:
 *   - Period Movement  = Receive - Issue, honours the date filter.
 *   - Ledger Balance   = material_stock_ledgers total closing, ALWAYS lifetime
 *                        (the ledger is a cached closing snapshot with no date
 *                        column, so a date range cannot be applied to it).
 *
 * Writes nothing and changes no column or relation.
 */
class StoreReportService
{
    public const TYPE_STYLE = 'style';
    public const TYPE_BUYER = 'buyer';
    public const TYPE_MATERIAL = 'material';

    /** Label shown when the source row has no value for the grouping column. */
    private const UNSPECIFIED = 'Not Specified';

    /**
     * Report types available in the UI: key => screen label.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_STYLE => 'Style-wise',
            self::TYPE_BUYER => 'Buyer-wise',
            self::TYPE_MATERIAL => 'Material-wise',
        ];
    }

    /** Heading for the first (grouping) column of the report table. */
    public static function groupHeading(string $type): string
    {
        return match ($type) {
            self::TYPE_BUYER => 'Buyer',
            self::TYPE_MATERIAL => 'Material',
            default => 'Style',
        };
    }

    /** Physical column each report type groups on. */
    private function groupColumn(string $type): string
    {
        return match ($type) {
            self::TYPE_BUYER => 'buyer_name',
            self::TYPE_MATERIAL => 'material_description',
            default => 'style_name',
        };
    }

    /**
     * Build the report rows.
     *
     * @param  array{buyer?:string|null, style?:string|null, material?:string|null, date_from?:string|null, date_to?:string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $type, array $filters = []): Collection
    {
        $column = $this->groupColumn($type);

        $rows = [];

        // --- Receive side (material_receivings) ---
        foreach ($this->receiveTotals($column, $filters) as $key => $data) {
            $rows[$key] = array_merge($this->blankRow($data['label']), $data['values']);
        }

        // --- Issue side (material_bulk_issues) ---
        foreach ($this->issueTotals($column, $filters) as $key => $data) {
            $rows[$key] ??= $this->blankRow($data['label']);
            $rows[$key] = array_merge($rows[$key], $data['values']);
        }

        // --- Lifetime closing balance (material_stock_ledgers, no date filter) ---
        foreach ($this->ledgerTotals($column, $filters) as $key => $data) {
            $rows[$key] ??= $this->blankRow($data['label']);
            $rows[$key]['ledger_balance'] = $data['values']['ledger_balance'];
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['total_issue'] = $row['bulk_qty'] + $row['sample_qty'] + $row['liability_qty'] + $row['dead_qty'];
                $row['period_movement'] = $row['receive_qty'] - $row['total_issue'];

                return $row;
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Column totals for the report footer.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    public function totals(Collection $rows): array
    {
        $keys = [
            'receive_qty', 'bulk_qty', 'sample_qty', 'liability_qty', 'dead_qty',
            'total_issue', 'period_movement', 'ledger_balance', 'receive_value',
        ];

        return collect($keys)
            ->mapWithKeys(fn ($key) => [$key => (float) $rows->sum($key)])
            ->all();
    }

    /**
     * Distinct filter options, sourced from the existing movement tables so the
     * dropdowns only ever offer values that can actually return rows.
     *
     * @return array{buyers: Collection<int, string>, styles: Collection<int, string>}
     */
    public function filterOptions(): array
    {
        return [
            'buyers' => $this->distinctValues('buyer_name'),
            'styles' => $this->distinctValues('style_name'),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function distinctValues(string $column): Collection
    {
        $fromReceivings = MaterialReceiving::whereNotNull($column)->distinct()->pluck($column);
        $fromIssues = MaterialBulkIssue::whereNotNull($column)->distinct()->pluck($column);

        return $fromReceivings
            ->concat($fromIssues)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Zeroed row so every group has the same shape regardless of which source
     * tables it appeared in.
     *
     * @return array<string, mixed>
     */
    private function blankRow(string $label): array
    {
        return [
            'label' => $label,
            'receive_qty' => 0.0,
            'receive_value' => 0.0,
            'bulk_qty' => 0.0,
            'sample_qty' => 0.0,
            'liability_qty' => 0.0,
            'dead_qty' => 0.0,
            'total_issue' => 0.0,
            'period_movement' => 0.0,
            'ledger_balance' => 0.0,
        ];
    }

    /**
     * Aggregated receive quantity and value. Value uses the unit_price already
     * stored on each receiving row — no rate is derived or assumed here, and
     * rows without a price simply contribute 0.
     *
     * @return array<string, array{label: string, values: array<string, float>}>
     */
    private function receiveTotals(string $column, array $filters): array
    {
        $query = MaterialReceiving::query()
            ->selectRaw("{$column} as group_raw, SUM(qty) as receive_qty, SUM(qty * COALESCE(unit_price, 0)) as receive_value")
            ->groupBy($column);

        $this->applyScopeFilters($query, $filters);
        $this->applyDateFilter($query, 'receive_date', $filters);

        return $this->keyByLabel($query->get(), fn ($row) => [
            'receive_qty' => (float) $row->receive_qty,
            'receive_value' => (float) $row->receive_value,
        ]);
    }

    /**
     * Aggregated four-way issue split.
     *
     * @return array<string, array{label: string, values: array<string, float>}>
     */
    private function issueTotals(string $column, array $filters): array
    {
        $query = MaterialBulkIssue::query()
            ->selectRaw("{$column} as group_raw, SUM(bulk_qty) as bulk_qty, SUM(sample_qty) as sample_qty, SUM(liability_qty) as liability_qty, SUM(dead_qty) as dead_qty")
            ->groupBy($column);

        $this->applyScopeFilters($query, $filters);
        $this->applyDateFilter($query, 'issue_date', $filters);

        return $this->keyByLabel($query->get(), fn ($row) => [
            'bulk_qty' => (float) $row->bulk_qty,
            'sample_qty' => (float) $row->sample_qty,
            'liability_qty' => (float) $row->liability_qty,
            'dead_qty' => (float) $row->dead_qty,
        ]);
    }

    /**
     * Lifetime closing balance per group. The date filter is intentionally NOT
     * applied — the ledger holds a cached current snapshot, not dated movements.
     *
     * @return array<string, array{label: string, values: array<string, float>}>
     */
    private function ledgerTotals(string $column, array $filters): array
    {
        $query = MaterialStockLedger::query()
            ->selectRaw("{$column} as group_raw, SUM(total_closing_qty) as ledger_balance")
            ->groupBy($column);

        $this->applyScopeFilters($query, $filters);

        return $this->keyByLabel($query->get(), fn ($row) => [
            'ledger_balance' => (float) $row->ledger_balance,
        ]);
    }

    /**
     * Normalize the raw group value into a stable key so rows that differ only
     * by casing or padding merge into one line across the three source tables.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $results
     * @return array<string, array{label: string, values: array<string, float>}>
     */
    private function keyByLabel(Collection $results, callable $values): array
    {
        $merged = [];

        foreach ($results as $row) {
            $label = trim((string) ($row->group_raw ?? '')) ?: self::UNSPECIFIED;
            $key = mb_strtolower($label);

            if (! isset($merged[$key])) {
                $merged[$key] = ['label' => $label, 'values' => []];
            }

            // Same key can appear twice (e.g. "Blue" and "blue") — add them up.
            foreach ($values($row) as $field => $value) {
                $merged[$key]['values'][$field] = ($merged[$key]['values'][$field] ?? 0.0) + $value;
            }
        }

        return $merged;
    }

    /**
     * Buyer / style / material filters. Shared by all three source tables since
     * each one carries the same denormalized identity columns.
     */
    private function applyScopeFilters($query, array $filters): void
    {
        if ($buyer = ($filters['buyer'] ?? null)) {
            $query->where('buyer_name', $buyer);
        }

        if ($style = ($filters['style'] ?? null)) {
            $query->where('style_name', $style);
        }

        if ($material = ($filters['material'] ?? null)) {
            $query->where(function ($q) use ($material) {
                $q->where('material_description', 'like', "%{$material}%")
                    ->orWhere('sap_code', 'like', "%{$material}%");
            });
        }
    }

    /** Date range against the movement table's own date column. */
    private function applyDateFilter($query, string $dateColumn, array $filters): void
    {
        if ($from = ($filters['date_from'] ?? null)) {
            $query->whereDate($dateColumn, '>=', $from);
        }

        if ($to = ($filters['date_to'] ?? null)) {
            $query->whereDate($dateColumn, '<=', $to);
        }
    }
}
