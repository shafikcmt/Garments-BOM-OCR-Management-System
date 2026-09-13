<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Services\DashboardMetricsService;
use App\Services\DepartmentActivityService;
use App\Support\Concerns\ScopesToAssignedBuyers;

/**
 * Commercial has no module of its own — it works inside the shared BOM
 * workspace, so the dashboard reports on this role's share of it: how many
 * columns it owns, how much is filled, and what is still outstanding.
 */
class DashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ShowsRequisitionSummary;

    use ScopesToAssignedBuyers;

    public function index(DashboardMetricsService $metrics)
    {
        // Required-column progress for this department only, from the same
        // service the Admin Dashboard reads — so a department and admin
        // never see two different numbers for the same work.
        $activity = app(DepartmentActivityService::class);
        $workspace = $activity->forRole('commercial') ?? $activity->emptyProgressFor('commercial');

        // Both counts are buyer-linked, so both are built from a scoped query
        // rather than a bare count(). A dashboard number that disagrees with the
        // report it summarises is a support call, and a confined user reading
        // "40 rows" must find forty rows when they open the report.
        //
        // Two different helpers because the two models reach a buyer two
        // different ways: ExcelRow hops through its file, ExcelFile IS the file.
        $rows = ExcelRow::query();
        $this->applyBuyerScope($rows);

        $files = ExcelFile::query();
        $this->applyBuyerScopeToFiles($files);

        // Cloned per use: monthlyTrend() adds its own grouping and would
        // otherwise hand a mutated builder to the count below it.
        $trend = $metrics->monthlyTrend((clone $rows));
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'files' => $files->count(),
            'rows' => (clone $rows)->count(),
        ];

        return view('commercial.dashboard', compact('stats', 'workspace', 'trend', 'delta') + $this->requisitionSummary(auth()->user()));
    }
}
