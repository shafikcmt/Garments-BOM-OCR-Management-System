<?php

namespace App\Services;

use App\Models\ExcelCell;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Figures shared by the role dashboards.
 *
 * Everything here is a live count. Several roles (commercial, account,
 * merchant) have no module of their own — they work inside the shared BOM
 * workspace — so the question their dashboard has to answer is "how much of
 * the BOM is waiting on me?". That is answerable because every column declares
 * an owner role, which makes each role's share of the sheet countable.
 */
class DashboardMetricsService
{
    /**
     * How much of the workspace this role still owes.
     *
     * Expected cells are (rows × columns this role owns) rather than the cells
     * that happen to exist, because a cell that was never created is exactly a
     * cell nobody has filled in.
     *
     * @return array{fields: int, rows: int, expected: int, filled: int, pending: int, percent: float}
     */
    public function workspaceCompletionFor(string $role): array
    {
        $headerIds = ExcelHeader::query()
            ->whereHas('ownerRole', fn ($q) => $q->where('name', $role))
            ->pluck('id');

        $rows = ExcelRow::count();
        $fields = $headerIds->count();
        $expected = $fields * $rows;

        $filled = $headerIds->isEmpty() ? 0 : ExcelCell::query()
            ->whereIn('header_id', $headerIds->all())
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->count();

        return [
            'fields' => $fields,
            'rows' => $rows,
            'expected' => $expected,
            'filled' => $filled,
            'pending' => max(0, $expected - $filled),
            'percent' => $expected > 0 ? round(($filled / $expected) * 100, 1) : 0.0,
        ];
    }

    /**
     * Rows created per month for the last N months, oldest first.
     *
     * Months with nothing are kept, so a quiet period looks quiet instead of
     * being collapsed out of the series.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function monthlyTrend(Builder $query, int $months = 6, string $column = 'created_at'): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $counts = $query->clone()
            ->where($column, '>=', $start)
            ->get([$column])
            ->groupBy(fn ($model) => $model->{$column}->format('Y-m'))
            ->map->count();

        $trend = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $trend[] = [
                'label' => $month->format('M'),
                'value' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * Month-on-month change for a trend series.
     *
     * Returns null when the previous month had nothing: a percentage against
     * zero is not a growth figure, and these numbers are read by people making
     * decisions on them.
     */
    public function deltaFor(array $trend): ?float
    {
        if (count($trend) < 2) {
            return null;
        }

        $current = (float) $trend[count($trend) - 1]['value'];
        $previous = (float) $trend[count($trend) - 2]['value'];

        return $previous > 0 ? round((($current - $previous) / $previous) * 100) : null;
    }

    /**
     * Per-role share of the workspace, for the admin overview.
     *
     * @return array<int, array{role: string, fields: int, filled: int, percent: float}>
     */
    public function workspaceOwnershipBreakdown(): array
    {
        return ExcelHeader::query()
            ->join('roles', 'excel_headers.owner_role_id', '=', 'roles.id')
            ->select('roles.name', DB::raw('count(*) as fields'))
            ->groupBy('roles.name')
            ->orderByDesc('fields')
            ->get()
            ->map(fn ($row) => [
                'role' => $row->name,
                'fields' => (int) $row->fields,
            ])
            ->all();
    }
}
