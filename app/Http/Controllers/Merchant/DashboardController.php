<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Services\DashboardMetricsService;

/**
 * Merchandising owns the largest share of the BOM columns, so the dashboard
 * reports on that share: how many columns this role owns, how much of it is
 * filled in, and what is still outstanding.
 */
class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics)
    {
        $workspace = $metrics->workspaceCompletionFor('merchant');

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
