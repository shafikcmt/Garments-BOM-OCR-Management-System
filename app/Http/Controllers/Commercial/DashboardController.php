<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Services\DashboardMetricsService;

/**
 * Commercial has no module of its own — it works inside the shared BOM
 * workspace, so the dashboard reports on this role's share of it: how many
 * columns it owns, how much is filled, and what is still outstanding.
 */
class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics)
    {
        $workspace = $metrics->workspaceCompletionFor('commercial');

        $trend = $metrics->monthlyTrend(ExcelRow::query());
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'files' => ExcelFile::count(),
            'rows' => ExcelRow::count(),
        ];

        return view('commercial.dashboard', compact('stats', 'workspace', 'trend', 'delta'));
    }
}
