<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Services\DashboardMetricsService;
use App\Services\DepartmentActivityService;

/**
 * Merchandising owns the largest share of the BOM columns, so the dashboard
 * reports on that share: how many columns this role owns, how much of it is
 * filled in, and what is still outstanding.
 */
class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics)
    {
        // Required-column progress for this department only, from the same
        // service the Admin Dashboard reads — so a department and admin
        // never see two different numbers for the same work.
        $activity = app(DepartmentActivityService::class);
        $workspace = $activity->forRole('merchant') ?? $activity->emptyProgressFor('merchant');

        $trend = $metrics->monthlyTrend(ExcelRow::query());
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'files' => ExcelFile::count(),
            'rows' => ExcelRow::count(),
            'my_files' => ExcelFile::where('uploaded_by', auth()->id())->count(),
        ];

        return view('merchant.dashboard', compact('stats', 'workspace', 'trend', 'delta'));
    }
}
