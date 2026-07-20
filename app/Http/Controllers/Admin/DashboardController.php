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
    public function index()
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