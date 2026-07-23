<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\User;
use Spatie\Permission\Models\Role;
use App\Models\Supplier;
use App\Models\BookingInstruction;
use App\Models\BookingPo;

class DashboardController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $totalUsers = User::count();
        $activeUsers = User::where('status', 1)->count();
        $totalRoles = Role::count();
        $totalHeaders = ExcelHeader::count();
        $merchantUploadHeaders = ExcelHeader::where('merchant_can_upload', true)->count();
        $totalSuppliers = Supplier::count();
        $activeSuppliers = Supplier::where('is_active', true)->count();
        $totalBookingInstructions = BookingInstruction::count();
        $defaultBookingInstructions = BookingInstruction::where('is_default', true)->count();
        $totalGeneratedPos = BookingPo::count();

        $metrics = app(\App\Services\DashboardMetricsService::class);

        // Which role owns how much of the sheet — the admin view of the same
        // ownership the other dashboards report on for themselves.
        $ownership = $metrics->workspaceOwnershipBreakdown();

        // Department progress on the columns each one owns. Scoped to one order
        // when ?workspace= names a real file, otherwise every workspace at once —
        // the same widget answers "where is everyone" and "where is this order".
        $workspaceOptions = ExcelFile::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'original_file_name', 'upload_batch_no']);

        $selectedWorkspace = $request->filled('workspace')
            ? $workspaceOptions->firstWhere('id', (int) $request->input('workspace'))
            : null;

        $departmentActivity = app(\App\Services\DepartmentActivityService::class)
            ->summary($selectedWorkspace);

        $trend = $metrics->monthlyTrend(BookingPo::query());
        $delta = $metrics->deltaFor($trend);

        return view('admin.dashboard', compact(
            'totalUsers',
            'activeUsers',
            'totalRoles',
            'totalHeaders',
            'merchantUploadHeaders',
            'totalSuppliers',
            'activeSuppliers',
            'totalBookingInstructions',
            'defaultBookingInstructions',
            'totalGeneratedPos',
            'ownership',
            'departmentActivity',
            'workspaceOptions',
            'selectedWorkspace',
            'trend',
            'delta',
        ));
    }

    public function workspace()
    {
        $files = ExcelFile::with(['uploader', 'lockedBy'])
            ->latest()
            ->get();

        $fileSummaries = app(\App\Services\ExcelFileSummaryService::class)->for($files);

        $workspaceLockRoles = Role::orderBy('name')->get(['id', 'name']);
        $workspaceLockUsers = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.workspace', compact('files', 'workspaceLockRoles', 'workspaceLockUsers', 'fileSummaries'));
    }
}