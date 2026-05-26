@extends('layouts.app')

@section('title', 'Order Information')

@section('content')
@php
    $orderInfo = $orderInfo ?? [
        'Buyer Name' => '-',
        'Season Name' => '-',
        'Style Name' => '-',
        'Contract Number' => '-',
        'Contract Shipment Date' => '-',
    ];

    $updateQueryString = http_build_query(request()->only('page', 'per_page', 'search', 'filters'));
    $globalSearchValue = request()->query('search', '');
    $filterValues = (array) request()->query('filters', []);

    $roleColorClasses = [
        'merchant' => 'role-merchant',
        'commercial' => 'role-commercial',
        'supply-chain' => 'role-supply-chain',
        'supply_chain' => 'role-supply-chain',
        'supplychain' => 'role-supply-chain',
        'production' => 'role-production',
        'accounts' => 'role-accounts',
        'account' => 'role-accounts',
        'store' => 'role-store',
    ];

    $normalizeRoleSlug = function ($roleName) {
        $roleSlug = strtolower(trim((string) $roleName));
        $roleSlug = preg_replace('/[^a-z0-9]+/', '-', $roleSlug);

        return trim($roleSlug, '-');
    };

    $headerRoleClass = function ($header) use ($roleColorClasses, $normalizeRoleSlug) {
        $roleSlug = $normalizeRoleSlug(optional($header->ownerRole)->name ?? 'default');

        return $roleColorClasses[$roleSlug] ?? 'role-default';
    };

    $currentUserRoleSlugs = auth()->check()
        ? auth()->user()->roles->pluck('name')
            ->map($normalizeRoleSlug)
            ->filter()
            ->flatMap(function ($roleSlug) {
                $aliases = [$roleSlug];

                if ($roleSlug === 'account') {
                    $aliases[] = 'accounts';
                }

                if ($roleSlug === 'accounts') {
                    $aliases[] = 'account';
                }

                if (in_array($roleSlug, ['supply-chain', 'supplychain'], true)) {
                    $aliases[] = 'supply-chain';
                    $aliases[] = 'supplychain';
                }

                return $aliases;
            })
            ->unique()
            ->values()
        : collect();

    $shouldAutoScrollToUserColumns = $globalSearchValue === '' && count($filterValues) === 0;

    // Keep this outside @json(...complex expression...) because Blade can break
    // when arrow functions return arrays inside @json().
    $sheetHeadersForJs = $headers->map(function ($header) use ($normalizeRoleSlug) {
        $ownerRoleName = optional($header->ownerRole)->name;

        return [
            'id' => $header->id,
            'header_key' => $header->header_key,
            'header_name' => $header->header_name,
            'owner_role' => $ownerRoleName,
            'owner_role_slug' => $normalizeRoleSlug($ownerRoleName),
            'field_type' => $header->field_type,
            'value_mode' => $header->value_mode ?? 'input',
        ];
    })->values();
@endphp

<style>

    .notification-highlight-cell {
    background: #fff3cd !important;
    box-shadow: inset 0 0 0 2px #f59e0b;
    animation: notificationPulse 1.4s ease-in-out 3;
}

.notification-highlight-cell input,
.notification-highlight-cell .excel-readonly-cell {
    background: #fff7df !important;
    border-color: #f59e0b !important;
}

.notification-highlight-alert {
    border: 1px solid #fde68a;
    background: #fffbeb;
    color: #92400e;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
}

@keyframes notificationPulse {
    0% { box-shadow: inset 0 0 0 2px #f59e0b; }
    50% { box-shadow: inset 0 0 0 4px #fbbf24; }
    100% { box-shadow: inset 0 0 0 2px #f59e0b; }
}


    .draft-restored-cell {
        background: #ecfdf5 !important;
        box-shadow: inset 0 0 0 2px #10b981;
        animation: draftRestorePulse 1.4s ease-in-out 3;
    }

    .draft-restored-cell input,
    .draft-restored-cell .excel-readonly-cell {
        background: #f0fdf4 !important;
        border-color: #10b981 !important;
    }

    .draft-restore-toast {
        position: fixed;
        right: 24px;
        bottom: 24px;
        z-index: 10000;
        max-width: 360px;
        border-radius: 12px;
        padding: 12px 16px;
        background: linear-gradient(135deg, #059669, #0f766e);
        color: #fff;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.25);
        font-size: 13px;
        font-weight: 700;
        opacity: 0;
        transform: translateY(8px);
        pointer-events: none;
        transition: opacity .2s ease, transform .2s ease;
    }

    .draft-restore-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

@keyframes draftRestorePulse {
    0% { box-shadow: inset 0 0 0 2px #10b981; }
    50% { box-shadow: inset 0 0 0 4px #34d399; }
    100% { box-shadow: inset 0 0 0 2px #10b981; }
}

    .calculated-cell {
        background: #f8fafc;
    }

    .excel-readonly-cell {
        min-width: 132px;
        min-height: 28px;
        padding: 3px 6px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        line-height: 1.2;
        font-size: 12px;
    }

    .excel-calculated-value {
        background: #f4f8ff;
        border: 1px solid #dbe6f5;
        color: #1e3a8a;
        font-weight: 600;
    }

    .formula-live-updated {
        box-shadow: inset 0 0 0 1px rgba(96, 165, 250, 0.25);
    }

    .excel-data-table .role-column {
        border-left: 1px solid #edf2f7;
    }

    .excel-data-table thead th.role-column {
        color: #0f172a;
    }

    .excel-data-table tbody td.role-column {
        background-clip: padding-box;
        transition: background-color 0.18s ease, box-shadow 0.18s ease;
    }

    .excel-data-table tbody tr:nth-child(even) td.role-column {
        background-clip: padding-box;
    }

    .role-merchant { background-color: #f8fbff !important; }
    .role-commercial { background-color: #fffaf6 !important; }
    .role-supply-chain { background-color: #f6fcf8 !important; }
    .role-production { background-color: #fbf9ff !important; }
    .role-accounts { background-color: #fff8f8 !important; }
    .role-store { background-color: #f5fcfb !important; }
    .role-default { background-color: #fafcff !important; }

    .excel-data-table tbody td.calculated-cell {
        background: linear-gradient(180deg, #f7fbff, #eef5ff) !important;
    }

    .excel-data-table thead th.formula-header-cell {
        background: linear-gradient(180deg, #f7fbff, #edf4ff) !important;
        border-bottom: 1px solid #d9e6f5;
    }

    .header-title {
        font-weight: 700;
        line-height: 1.2;
        margin-bottom: 4px;
        font-size: 12px;
        letter-spacing: -0.01em;
    }

    .header-meta {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 4px;
    }

    .role-chip,
    .formula-chip {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 2px 7px;
        font-size: 9px;
        font-weight: 700;
        line-height: 1.1;
        border: 1px solid rgba(15, 23, 42, 0.06);
        box-shadow: 0 1px 1px rgba(15, 23, 42, 0.02);
    }

    .role-chip {
        background: rgba(255, 255, 255, 0.9);
        color: #475569;
    }

    .formula-chip {
        background: linear-gradient(180deg, #4f8df8, #2f6fe8);
        color: #fff;
        border-color: #3f7ceb;
    }
    .order-summary-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 2px 8px rgba(15,23,42,.05);
        overflow: hidden;
    }

    .order-summary-top {
        background: #f8fafc;
        border-bottom: 1px solid #e9eef5;
        padding: 8px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        min-height: 44px;
    }

    .order-summary-meta {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 6px;
    }

    .order-meta-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .order-meta-chip-label {
        color: #94a3b8;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    .order-meta-chip-value {
        color: #0f172a;
    }

    .order-meta-chip-batch {
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }

    .order-meta-chip-status {
        background: #f0fdf4;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }

    .order-meta-chip-locked {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .order-meta-chip-rows {
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .order-summary-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 0;
        border-top: 1px solid #f1f5f9;
    }

    .order-info-box {
        flex: 1 1 160px;
        padding: 8px 14px;
        border-right: 1px solid #f1f5f9;
        min-width: 0;
    }

    .order-info-box:last-child {
        border-right: 0;
    }

    .order-info-label {
        font-size: 10px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .order-info-value {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .excel-work-card {
        border: 1px solid #e8edf4;
        border-radius: 14px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        overflow: hidden;
        background: #fff;
    }

    .excel-table-wrap {
        overflow: auto;
        max-height: 72vh;
        background: #fff;
        border-top: 1px solid #edf2f7;
    }

    .excel-data-table {
        min-width: 1780px;
        margin-bottom: 0;
        font-size: 12px;
        border-color: #e8edf4;
    }

    .excel-data-table thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: linear-gradient(180deg, #f6f9fd, #edf3f9);
        vertical-align: middle;
        text-align: center;
        border-bottom: 1px solid #dce4ee;
        min-width: 145px;
        padding: 5px 8px;
        white-space: normal;
        box-shadow: inset 0 -1px 0 #dce4ee;
    }

    .excel-data-table thead tr.filter-row th {
        top: 42px;
        z-index: 2;
        background: #ffffff;
        padding: 4px 5px;
        min-width: 145px;
        box-shadow: inset 0 -1px 0 #edf2f7;
    }

    .excel-data-table thead th:first-child,
    .excel-data-table thead tr.filter-row th:first-child,
    .excel-data-table tbody td:first-child {
        position: sticky;
        left: 0;
        z-index: 4;
        background: #fff;
        min-width: 64px;
        width: 64px;
        text-align: center;
        font-weight: 700;
    }

    .excel-data-table thead th:first-child {
        background: linear-gradient(180deg, #e8eef6, #dde7f2);
    }

    .excel-data-table thead tr.filter-row th:first-child {
        background: #fff;
    }

    .excel-data-table tbody td {
        vertical-align: middle;
        background: #fff;
        padding: 2px 4px;
        border-color: #edf2f7;
    }

    .excel-data-table tbody tr:nth-child(even) td {
        background: #fcfcfd;
    }

    .excel-data-table tbody tr:hover td {
        background-color: #f8fbff;
    }

    .excel-data-table tbody tr:hover td.role-merchant { background-color: #f2f8ff !important; }
    .excel-data-table tbody tr:hover td.role-commercial { background-color: #fff5eb !important; }
    .excel-data-table tbody tr:hover td.role-supply-chain { background-color: #eefaf3 !important; }
    .excel-data-table tbody tr:hover td.role-production { background-color: #f7f3ff !important; }
    .excel-data-table tbody tr:hover td.role-accounts { background-color: #fff1f1 !important; }
    .excel-data-table tbody tr:hover td.role-store { background-color: #eefbf8 !important; }
    .excel-data-table tbody tr:hover td.role-default { background-color: #f6faff !important; }

    .excel-data-table input.form-control {
        min-width: 132px;
        border-radius: 7px;
        padding: 3px 6px;
        font-size: 12px;
        height: 28px;
        border-color: #d9e2ec;
        background: rgba(255, 255, 255, 0.96);
    }

    .excel-data-table input.form-control:focus {
        border-color: #b8c7da;
        box-shadow: 0 0 0 0.1rem rgba(148, 163, 184, 0.12);
    }

    .sheet-pagination {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        background: #ffffff;
        border-top: 1px solid #edf2f7;
    }

    .sheet-pagination .page-status {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
    }

    .sheet-pager {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 5px;
    }

    .sheet-pager .pager-btn,
    .sheet-pager .pager-page,
    .sheet-pager .pager-ellipsis {
        min-width: 32px;
        height: 30px;
        padding: 5px 9px;
        border-radius: 8px;
        border: 1px solid #dbe3ec;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        line-height: 1;
        text-decoration: none;
        background: #fff;
        color: #334155;
        font-weight: 700;
    }

    .sheet-pager .pager-btn {
        min-width: 82px;
    }

    .sheet-pager a:hover {
        background: #f8fbff;
        border-color: #b8c7da;
        color: #1d4ed8;
    }

    .sheet-pager .active {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .sheet-pager .disabled {
        color: #94a3b8;
        background: #f8fafc;
        pointer-events: none;
        cursor: not-allowed;
    }

    .sheet-pager .pager-ellipsis {
        border-color: transparent;
        background: transparent;
        min-width: 24px;
        padding-left: 3px;
        padding-right: 3px;
    }

    .sheet-pagination svg {
        width: 14px !important;
        height: 14px !important;
    }

    .filter-input {
        min-width: 128px;
        border-radius: 7px;
        border: 1px solid #dbe3ec;
        font-size: 12px;
        height: 28px;
        padding: 3px 6px;
        background: #fff;
    }

    .server-search-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
        padding: 6px 12px;
        border-top: 1px solid #edf2f7;
        background: #ffffff;
    }

    .server-search-left {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 5px;
    }

    .server-search-input {
        width: min(280px, 60vw);
        height: 30px;
        font-size: 12px;
        border-radius: 7px;
        border: 1px solid #dbe4ef;
    }

    .search-help-text {
        font-size: 10px;
        color: #94a3b8;
        font-weight: 600;
    }

    .header-find-tools {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
        gap: 5px;
        margin-left: auto;
    }

    .header-find-label {
        font-size: 10px;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .header-find-input,
    .header-find-select {
        height: 30px;
        border-radius: 7px;
        border: 1px solid #dbe4ef;
        font-size: 12px;
        color: #334155;
    }

    .header-find-input {
        width: min(180px, 46vw);
    }

    .header-find-select {
        width: min(210px, 54vw);
    }

    .active-header-column {
        position: relative;
        box-shadow: inset 0 0 0 2px #2563eb !important;
        background-color: #eff6ff !important;
    }

    .active-header-column input,
    .active-header-column .excel-readonly-cell {
        border-color: #2563eb !important;
        background: #ffffff !important;
    }

    @media (max-width: 992px) {
        .header-find-tools {
            width: 100%;
            justify-content: flex-start;
            margin-left: 0;
        }

        .search-help-text {
            width: 100%;
        }
    }

    .table-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        justify-content: space-between;
        padding: 7px 12px;
        border-bottom: 1px solid #edf2f7;
        background: #f8fafc;
        min-height: 46px;
    }

    .table-toolbar-left {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
    }

    .section-title {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        white-space: nowrap;
    }

    .paste-note {
        font-size: 11px;
        color: #94a3b8;
        margin: 0;
        display: none;
    }

    .custom-success-alert {
        border: 0;
        border-radius: 12px;
        background: linear-gradient(135deg, #dcfce7, #bbf7d0);
        color: #166534;
        box-shadow: 0 8px 24px rgba(22, 101, 52, 0.12);
    }

    .draft-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 20px;
        backdrop-filter: blur(2px);
    }

    .draft-modal-overlay.show {
        display: flex;
    }

    .draft-modal-box {
        width: 100%;
        max-width: 520px;
        border-radius: 20px;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 25px 70px rgba(15, 23, 42, 0.30);
        animation: modalPop .22s ease-out;
    }

    @keyframes modalPop {
        from {
            opacity: 0;
            transform: translateY(10px) scale(.98);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .draft-modal-header {
        background: linear-gradient(135deg, #1d4ed8, #0f766e);
        color: #fff;
        padding: 18px 22px;
    }

    .draft-modal-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }

    .draft-modal-subtitle {
        margin: 6px 0 0;
        font-size: 13px;
        opacity: .92;
    }

    .draft-modal-body {
        padding: 22px;
    }

    .draft-modal-body p {
        margin-bottom: 10px;
        color: #334155;
        font-size: 14px;
    }

    .draft-modal-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
        padding: 0 22px 22px;
    }

    .btn-soft-secondary {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #cbd5e1;
    }

    .btn-soft-secondary:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    .btn-gradient-primary {
        background: linear-gradient(135deg, #2563eb, #0f766e);
        border: none;
        color: #fff;
    }

    .btn-gradient-primary:hover {
        color: #fff;
        opacity: .95;
    }

    .btn-gradient-warning {
        background: linear-gradient(135deg, #f59e0b, #f97316);
        border: none;
        color: #fff;
    }

    .btn-gradient-warning:hover {
        color: #fff;
        opacity: .95;
    }

    @media (max-width: 1200px) {
        .order-summary-grid {
            grid-template-columns: repeat(2, minmax(150px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .order-summary-grid {
            grid-template-columns: 1fr;
        }

        .draft-modal-actions {
            flex-direction: column;
        }

        .draft-modal-actions .btn {
            width: 100%;
        }
    }

    .po-row-locked { background: #fff7ed !important; }
    .file-row-locked { background: #f8fafc !important; }
    .po-row-lock-badge,
    .file-row-lock-badge { display: inline-flex; align-items: center; gap: 4px; margin-top: 4px; padding: 3px 7px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-size: 10px; font-weight: 800; }
    .file-row-lock-badge { background: #e0f2fe; color: #075985; }
</style>

<div class="container-fluid">
    @if(session('success'))
        <div id="pageSuccessAlert" class="alert custom-success-alert py-2 px-3 mb-3">
            {{ session('success') }}
        </div>
    @endif

    @if(session('warning'))
        <div class="alert alert-warning py-2 px-3 mb-3 rounded-3">
            {{ session('warning') }}
        </div>
    @endif

    @if($isFileLockedForUser ?? false)
        <div class="alert alert-warning py-2 px-3 mb-3 rounded-3">
            <div class="fw-bold"><i class="bi bi-lock-fill me-1"></i>This workspace file is locked for your user or role.</div>
            <div class="small">You can view this file, but you cannot edit, update, paste changes, or add rows until admin unlocks it for you.</div>
            @if(!empty($fileLockInfo['reason'] ?? ''))
                <div class="small mt-1"><strong>Reason:</strong> {{ $fileLockInfo['reason'] }}</div>
            @endif
        </div>
    @endif

    @if(!empty($highlightBatchId) && isset($highlightedCellKeys) && $highlightedCellKeys->count() > 0)
    <div class="alert notification-highlight-alert py-2 px-3 mb-3">
        Merchant updated {{ $highlightedCellKeys->count() }} cell(s). Highlighted cells show the specific changes from this notification.
    </div>
    @endif

    @if($canAddRow ?? false)
        <form method="POST" action="{{ route('uploaded-files.rows.store', $excelFile->id) }}" id="add-row-form" class="d-none">
            @csrf
        </form>
    @endif

    @if($canDeleteFile ?? false)
        <form method="POST" action="{{ route('uploaded-files.destroy', $excelFile->id) }}" id="delete-file-form" class="d-none">
            @csrf
            @method('DELETE')
        </form>
    @endif

    <div class="order-summary-card mb-2">
        <div class="order-summary-top">
            <div class="order-summary-meta">
                <span class="fw-bold text-slate-900 me-1" style="font-size:13px;">Order Information</span>
                <span class="order-meta-chip order-meta-chip-batch"><span class="order-meta-chip-label">Batch</span><span class="order-meta-chip-value">{{ $excelFile->upload_batch_no }}</span></span>
                <span class="order-meta-chip order-meta-chip-rows"><span class="order-meta-chip-label">Rows</span><span class="order-meta-chip-value">{{ $excelFile->total_rows }}</span></span>
                <span class="order-meta-chip order-meta-chip-status"><span class="order-meta-chip-label">Status</span><span class="order-meta-chip-value">{{ ucfirst($excelFile->status ?? 'pending') }}</span></span>
                @if($excelFile->is_locked)
                    <span class="order-meta-chip order-meta-chip-locked"><i class="bi bi-lock-fill me-1"></i>{{ $fileLockInfo['summary'] ?? $excelFile->lockScopeLabel() }}</span>
                @endif
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <a href="{{ url()->previous() }}" class="btn btn-outline-secondary btn-sm" style="min-height:32px;font-size:12px;"><i class="bi bi-arrow-left"></i></a>
                @if($canDeleteFile ?? false)
                    <button type="submit" form="delete-file-form" class="btn btn-outline-danger btn-sm" style="min-height:32px;font-size:12px;" onclick="return confirm('Are you sure you want to delete this file?');"><i class="bi bi-trash"></i></button>
                @endif
            </div>
        </div>
        <div class="order-summary-grid">
            @foreach($orderInfo as $label => $value)
                <div class="order-info-box">
                    <div class="order-info-label">{{ $label }}</div>
                    <div class="order-info-value" title="{{ $value ?: '-' }}">{{ $value ?: '-' }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('uploaded-files.update', $excelFile->id) }}{{ $updateQueryString ? '?' . $updateQueryString : '' }}" id="excelFileForm">
        @csrf
        @method('PUT')

        <div class="excel-work-card">
            <div class="table-toolbar">
                <div class="table-toolbar-left">
                    <h4 class="section-title">Excel Work Sheet</h4>
                    <p class="paste-note">Multiple row/column paste supported.</p>
                </div>

                <div class="d-flex gap-2">
                    @if($canAddRow ?? false)
                        <button type="button" id="addRowButton" class="btn btn-outline-primary btn-sm">
                            Add New Row
                        </button>
                    @endif

                    @if(count($editableHeaderIds) > 0)
                        <button type="submit" class="btn btn-primary btn-sm" id="saveChangesButton">
                            Save Changes
                        </button>
                    @endif
                </div>
            </div>

            <div class="server-search-bar">
                <div class="server-search-left">
                    <input
                        type="text"
                        id="globalDocSearch"
                        class="form-control form-control-sm server-search-input"
                        value="{{ $globalSearchValue }}"
                        placeholder="Search entire document..."
                        autocomplete="off"
                    >
                    <button type="button" class="btn btn-outline-primary btn-sm" id="applySheetSearchButton">
                        Search
                    </button>
                    @if($globalSearchValue || count($filterValues))
                        <a href="{{ route('uploaded-files.show', $excelFile->id) }}?per_page={{ $perPage }}" class="btn btn-outline-secondary btn-sm">
                            Clear
                        </a>
                    @endif
                </div>
                <div class="header-find-tools">
                    <span class="header-find-label">Header Finder</span>
                    <input
                        type="text"
                        id="headerQuickFind"
                        class="form-control form-control-sm header-find-input"
                        list="headerQuickFindList"
                        placeholder="Type header name..."
                        autocomplete="off"
                    >
                    <datalist id="headerQuickFindList">
                        @foreach($headers as $header)
                            <option value="{{ $header->header_name }}">{{ ucfirst(str_replace('_', ' ', optional($header->ownerRole)->name ?? 'N/A')) }}</option>
                        @endforeach
                    </datalist>
                    <select id="headerJumpSelect" class="form-select form-select-sm header-find-select">
                        <option value="">Select header to find/edit</option>
                        @foreach($headers as $header)
                            <option value="{{ $header->id }}">{{ $header->header_name }} — {{ ucfirst(str_replace('_', ' ', optional($header->ownerRole)->name ?? 'N/A')) }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="headerFindButton">Find</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="jumpToMyColumnsButton">My Columns</button>
                </div>

                <span class="search-help-text">All {{ $excelFile->total_rows ?: $rows->total() }} rows</span>
            </div>

            <div class="excel-table-wrap">
                <table class="table table-bordered excel-data-table" id="excelDataTable">
                    <thead>
                        <tr>
                            <th>Row</th>
                            @foreach($headers as $header)
                                @php
                                    $isHeaderCalculated = in_array($header->id, $calculatedHeaderIds ?? [], true)
                                        || in_array($header->header_key, $calculatedHeaderKeys ?? [], true);
                                    $roleClass = $headerRoleClass($header);
                                    $roleLabel = ucfirst(str_replace('_', ' ', optional($header->ownerRole)->name ?? 'N/A'));
                                    $canEditHeaderColumn = in_array($header->id, $editableHeaderIds, true) && !($isFileLockedForUser ?? false) && !$isHeaderCalculated;
                                @endphp
                                <th
                                    class="role-column {{ $roleClass }} {{ $isHeaderCalculated ? 'formula-header-cell' : '' }}"
                                    data-header-id="{{ $header->id }}"
                                    data-header-key="{{ $header->header_key }}"
                                    data-role-name="{{ optional($header->ownerRole)->name ?? '' }}"
                                    data-value-mode="{{ $header->value_mode ?? 'input' }}"
                                    data-col-index="{{ $loop->index }}"
                                    data-can-edit="{{ $canEditHeaderColumn ? '1' : '0' }}"
                                >
                                    <div class="header-title">{{ $header->header_name }}</div>
                                    <div class="header-meta">
                                        <span class="role-chip">{{ $roleLabel }}</span>
                                        @if($isHeaderCalculated)
                                            <span class="formula-chip">Auto</span>
                                        @endif
                                    </div>
                                </th>
                            @endforeach
                        </tr>

                        <tr class="filter-row">
                            <th>
                                <input type="text" class="form-control form-control-sm filter-input" placeholder="Row" data-column="0" data-filter-key="__row" value="{{ $filterValues['__row'] ?? '' }}" autocomplete="off">
                            </th>
                            @foreach($headers as $header)
                                @php
                                    $isHeaderCalculated = in_array($header->id, $calculatedHeaderIds ?? [], true)
                                        || in_array($header->header_key, $calculatedHeaderKeys ?? [], true);
                                    $roleClass = $headerRoleClass($header);
                                @endphp
                                <th
                                    class="role-column {{ $roleClass }} {{ $isHeaderCalculated ? 'formula-header-cell' : '' }}"
                                    data-header-id="{{ $header->id }}"
                                    data-col-index="{{ $loop->index }}"
                                >
                                    <input
                                        type="text"
                                        class="form-control form-control-sm filter-input"
                                        placeholder="Search"
                                        data-column="{{ $loop->index + 1 }}"
                                        data-filter-key="{{ $header->id }}"
                                        value="{{ $filterValues[$header->id] ?? '' }}"
                                        autocomplete="off"
                                    >
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($rows as $row)
                            @php
                                $cellsByHeaderId = $row->cells->keyBy('header_id');
                                $rowLockedInfo = ($lockedRowInfo ?? collect())->get($row->id);
                                $isPoRowLocked = ($lockedRowIds ?? collect())->has($row->id);
                            @endphp
                            <tr class="{{ trim(($isFileLockedForUser ?? false ? 'file-row-locked ' : '') . ($isPoRowLocked ? 'po-row-locked' : '')) }}">
                                <td>
                                    {{ $row->row_number }}
                                    @if($isFileLockedForUser ?? false)
                                        <div class="file-row-lock-badge" title="This workspace file is locked by admin">
                                            <i class="bi bi-lock-fill"></i> File Locked
                                        </div>
                                    @endif
                                    @if($isPoRowLocked)
                                        <div class="po-row-lock-badge" title="PO {{ $rowLockedInfo['po_no'] ?? '' }} locked{{ !empty($rowLockedInfo['reason'] ?? '') ? ': ' . $rowLockedInfo['reason'] : '' }}">
                                            <i class="bi bi-lock-fill"></i> PO Locked
                                        </div>
                                    @endif
                                </td>

                               @foreach($headers as $header)
                                @php
                                    $cell = $cellsByHeaderId->get($header->id);
                                    $value = $cell->value ?? '';
                                    $isCalculated = in_array($header->id, $calculatedHeaderIds ?? [], true)
                                        || in_array($header->header_key, $calculatedHeaderKeys ?? [], true);
                                    $editable = in_array($header->id, $editableHeaderIds, true) && !$isCalculated && !$isPoRowLocked && !($isFileLockedForUser ?? false);
                                    $cellHighlightKey = $row->id . '-' . $header->id;
                                    $isHighlightedCell = isset($highlightedCellKeys) && $highlightedCellKeys->contains($cellHighlightKey);
                                    $roleClass = $headerRoleClass($header);

                                    $inputType = 'text';
                                    if ($header->field_type === 'number') {
                                        $inputType = 'number';
                                    } elseif ($header->field_type === 'date') {
                                        $inputType = 'date';
                                    }
                                @endphp

                                    <td
                                        class="role-column {{ $roleClass }} {{ trim(($isCalculated ? 'calculated-cell ' : '') . ($isHighlightedCell ? 'notification-highlight-cell' : '')) }}"
                                        data-header-id="{{ $header->id }}"
                                        data-header-key="{{ $header->header_key }}"
                                        data-row-index="{{ $loop->parent->index }}"
                                        data-col-index="{{ $loop->index }}"
                                    >
                                        @if($editable)
                                            <input
                                                type="{{ $inputType }}"
                                                name="cells[{{ $row->id }}][{{ $header->id }}]"
                                                value="{{ $value }}"
                                                class="form-control form-control-sm excel-editable-cell"
                                                data-row-index="{{ $loop->parent->index }}"
                                                data-col-index="{{ $loop->index }}"
                                                data-original-value="{{ $value }}"
                                                data-header-id="{{ $header->id }}"
                                                data-header-key="{{ $header->header_key }}"
                                                @if($inputType === 'number') step="any" @endif
                                            >
                                        @else
                                            <div
                                                class="px-1 py-1 excel-readonly-cell {{ $isCalculated ? 'excel-calculated-value' : '' }}"
                                                data-row-index="{{ $loop->parent->index }}"
                                                data-col-index="{{ $loop->index }}"
                                                data-header-id="{{ $header->id }}"
                                                data-header-key="{{ $header->header_key }}"
                                                data-cell-display="1"
                                                data-formula-output="{{ $isCalculated ? '1' : '0' }}"
                                                data-cell-value="{{ $value }}"
                                                title="{{ ($isFileLockedForUser ?? false) ? 'This workspace file is locked by admin' : ($isPoRowLocked ? 'This PO row is locked by admin control' : ($isCalculated ? 'Calculated field - live auto update before save' : '')) }}"
                                            >
                                                {{ $value !== '' && $value !== null ? $value : '-' }}
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($headers) + 1 }}" class="text-center">No row data found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(method_exists($rows, 'currentPage'))
                @php
                    $currentPage = $rows->currentPage();
                    $lastPage = method_exists($rows, 'lastPage') ? $rows->lastPage() : $currentPage;
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($lastPage, $currentPage + 2);
                @endphp

                <div class="sheet-pagination">
                    <div class="page-status">
                        Showing {{ $rows->firstItem() ?? 0 }} to {{ $rows->lastItem() ?? 0 }} of {{ method_exists($rows, 'total') ? $rows->total() : 0 }} rows
                    </div>

                    <div class="sheet-pager">
                        @if($rows->onFirstPage())
                            <span class="pager-btn disabled">&lsaquo; Previous</span>
                        @else
                            <a class="pager-btn" href="{{ $rows->previousPageUrl() }}">&lsaquo; Previous</a>
                        @endif

                        @if($startPage > 1)
                            <a class="pager-page" href="{{ $rows->url(1) }}">1</a>
                            @if($startPage > 2)
                                <span class="pager-ellipsis">...</span>
                            @endif
                        @endif

                        @for($pageNo = $startPage; $pageNo <= $endPage; $pageNo++)
                            @if($pageNo == $currentPage)
                                <span class="pager-page active">{{ $pageNo }}</span>
                            @else
                                <a class="pager-page" href="{{ $rows->url($pageNo) }}">{{ $pageNo }}</a>
                            @endif
                        @endfor

                        @if($endPage < $lastPage)
                            @if($endPage < $lastPage - 1)
                                <span class="pager-ellipsis">...</span>
                            @endif
                            <a class="pager-page" href="{{ $rows->url($lastPage) }}">{{ $lastPage }}</a>
                        @endif

                        @if($rows->hasMorePages())
                            <a class="pager-btn" href="{{ $rows->nextPageUrl() }}">Next &rsaquo;</a>
                        @else
                            <span class="pager-btn disabled">Next &rsaquo;</span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>

<div class="draft-modal-overlay" id="draftRestoreModal">
    <div class="draft-modal-box">
        <div class="draft-modal-header">
            <h4 class="draft-modal-title">Unsaved Work Found</h4>
            <p class="draft-modal-subtitle">Your previous unsaved worksheet changes are available.</p>
        </div>

        <div class="draft-modal-body">
            <p>Do you want to restore the unsaved changes?</p>
            <p class="mb-0"><strong>Restore</strong> will fill the edited cells again. <strong>Discard</strong> will remove the saved draft.</p>
        </div>

        <div class="draft-modal-actions">
            <button type="button" class="btn btn-soft-secondary" id="discardDraftBtn">Discard</button>
            <button type="button" class="btn btn-gradient-warning" id="restoreOnlyBtn">Restore</button>
            <button type="button" class="btn btn-gradient-primary" id="restoreAndSaveBtn">Restore & Save</button>
        </div>
    </div>
</div>

<div class="draft-restore-toast" id="draftRestoreToast"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.getElementById('excelDataTable');
    const filterInputs = document.querySelectorAll('.filter-input');
    const form = document.getElementById('excelFileForm');
    const addRowForm = document.getElementById('add-row-form');
    const addRowButton = document.getElementById('addRowButton');
    const deleteFileForm = document.getElementById('delete-file-form');
    const editableInputs = Array.from(document.querySelectorAll('.excel-editable-cell'));
    const successAlert = document.getElementById('pageSuccessAlert');

    const sheetHeaders = @json($sheetHeadersForJs);
    const calculatedHeaderKeys = @json($calculatedHeaderKeys ?? []);
    const calculatedHeaderIds = @json($calculatedHeaderIds ?? []);
    const currentUserRoleSlugs = @json($currentUserRoleSlugs);
    const shouldAutoScrollToUserColumns = @json($shouldAutoScrollToUserColumns);

    const headerQuickFind = document.getElementById('headerQuickFind');
    const headerJumpSelect = document.getElementById('headerJumpSelect');
    const headerFindButton = document.getElementById('headerFindButton');
    const jumpToMyColumnsButton = document.getElementById('jumpToMyColumnsButton');

    const draftModal = document.getElementById('draftRestoreModal');
    const discardDraftBtn = document.getElementById('discardDraftBtn');
    const restoreOnlyBtn = document.getElementById('restoreOnlyBtn');
    const restoreAndSaveBtn = document.getElementById('restoreAndSaveBtn');
    const draftRestoreToast = document.getElementById('draftRestoreToast');

    const draftKey = `excel_draft_user_{{ auth()->id() }}_file_{{ $excelFile->id }}`;
    const draftMeta = {
        version: 2,
        fileId: {{ (int) $excelFile->id }},
        userId: {{ (int) auth()->id() }},
    };
    let isSubmitting = false;
    let restoreModalShown = false;
    let userEditedWorksheet = false;
    let draftSaveTimer = null;

    const editableGrid = {};

    function buildEditableGrid() {
        Object.keys(editableGrid).forEach(key => delete editableGrid[key]);

        editableInputs.forEach(input => {
            const rowIndex = parseInt(input.dataset.rowIndex, 10);
            const colIndex = parseInt(input.dataset.colIndex, 10);

            if (!editableGrid[rowIndex]) {
                editableGrid[rowIndex] = {};
            }

            editableGrid[rowIndex][colIndex] = input;
        });
    }

    function focusEditableCell(rowIndex, colIndex) {
        if (editableGrid[rowIndex] && editableGrid[rowIndex][colIndex]) {
            editableGrid[rowIndex][colIndex].focus();
            editableGrid[rowIndex][colIndex].select();
        }
    }

    function normalizeSearchText(value) {
        return String(value ?? '')
            .toLowerCase()
            .replace(/[_\-]+/g, ' ')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    }

    function headerIndexById(headerId) {
        const id = Number(headerId);
        return sheetHeaders.findIndex(header => Number(header.id) === id);
    }

    function headerById(headerId) {
        const index = headerIndexById(headerId);
        return index >= 0 ? sheetHeaders[index] : null;
    }

    function findHeaderByName(query) {
        const normalized = normalizeSearchText(query);

        if (normalized === '') {
            return null;
        }

        return sheetHeaders.find(header => normalizeSearchText(header.header_name) === normalized)
            || sheetHeaders.find(header => normalizeSearchText(header.header_key) === normalized)
            || sheetHeaders.find(header => normalizeSearchText(header.header_name).includes(normalized))
            || sheetHeaders.find(header => normalizeSearchText(header.header_key).includes(normalized));
    }

    function clearActiveHeaderColumn() {
        document.querySelectorAll('.active-header-column').forEach(element => {
            element.classList.remove('active-header-column');
        });
    }

    function highlightHeaderColumn(headerId) {
        clearActiveHeaderColumn();

        const selector = `[data-header-id="${headerId}"]`;
        document.querySelectorAll(selector).forEach(element => {
            element.classList.add('active-header-column');
        });

        setTimeout(clearActiveHeaderColumn, 3500);
    }

    function scrollToHeader(header, focusFirstEditable = true) {
        if (!header || !table) {
            return;
        }

        const index = headerIndexById(header.id);
        const headerCell = table.querySelector(`thead tr:first-child th[data-header-id="${header.id}"]`);

        if (!headerCell) {
            return;
        }

        headerCell.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        highlightHeaderColumn(header.id);

        if (typeof searchColumnStorageKey !== 'undefined') {
            localStorage.setItem(searchColumnStorageKey, String(index + 1));
        }

        if (headerJumpSelect) {
            headerJumpSelect.value = String(header.id);
        }

        if (focusFirstEditable) {
            setTimeout(function () {
                const editable = document.querySelector(`.excel-editable-cell[data-header-id="${header.id}"]`);

                if (editable) {
                    editable.focus({ preventScroll: true });
                    editable.select();
                    return;
                }

                const filter = document.querySelector(`.filter-input[data-filter-key="${header.id}"]`);

                if (filter) {
                    filter.focus({ preventScroll: true });
                    filter.select();
                }
            }, 450);
        }
    }

    function scrollToHeaderFromFinder() {
        const selectedHeader = headerJumpSelect && headerJumpSelect.value ? headerById(headerJumpSelect.value) : null;
        const typedHeader = headerQuickFind ? findHeaderByName(headerQuickFind.value) : null;
        const targetHeader = selectedHeader || typedHeader;

        if (!targetHeader) {
            if (headerQuickFind) {
                headerQuickFind.focus();
            }
            return;
        }

        scrollToHeader(targetHeader, true);
    }

    function firstHeaderForCurrentUser() {
        const roles = Array.isArray(currentUserRoleSlugs) ? currentUserRoleSlugs : [];

        if (roles.length === 0) {
            return null;
        }

        return sheetHeaders.find(header => roles.includes(header.owner_role_slug)) || null;
    }

    function scrollToCurrentUserColumns(focusFirstEditable = false) {
        const targetHeader = firstHeaderForCurrentUser();

        if (!targetHeader) {
            return;
        }

        scrollToHeader(targetHeader, focusFirstEditable);
    }

    function normalizeValue(value) {
        return String(value ?? '').trim();
    }

    function getCurrentInputMap() {
        const data = {};

        editableInputs.forEach(input => {
            data[input.name] = input.value ?? '';
        });

        return data;
    }

    function getChangedFields() {
        const changed = {};

        editableInputs.forEach(input => {
            const originalValue = normalizeValue(input.dataset.originalValue);
            const currentValue = normalizeValue(input.value);

            if (currentValue !== originalValue) {
                changed[input.name] = input.value ?? '';
            }
        });

        return changed;
    }

    function hasRealChanges(obj) {
        return obj && Object.keys(obj).length > 0;
    }

    function getDraftFields(savedDraft) {
        if (!savedDraft || typeof savedDraft !== 'object') {
            return {};
        }

        // Backward compatible: old drafts were stored directly as { inputName: value }.
        if (savedDraft.fields && typeof savedDraft.fields === 'object') {
            return savedDraft.fields;
        }

        return savedDraft;
    }

    function getVisibleDraftChanges(fields) {
        const visibleDraft = {};

        editableInputs.forEach(input => {
            if (!Object.prototype.hasOwnProperty.call(fields, input.name)) {
                return;
            }

            const draftValue = fields[input.name] ?? '';

            // Do not show restore modal for values already equal to database/original value.
            if (normalizeValue(draftValue) === normalizeValue(input.dataset.originalValue)) {
                return;
            }

            // Do not show restore modal when the current visible input already has the draft value.
            if (normalizeValue(draftValue) === normalizeValue(input.value)) {
                return;
            }

            visibleDraft[input.name] = draftValue;
        });

        return visibleDraft;
    }

    function buildDraftPayload(fields) {
        return {
            ...draftMeta,
            savedAt: new Date().toISOString(),
            fields: fields,
        };
    }

    function saveDraftToLocal(force = false) {
        // Important: clicking/focusing a cell must not create a draft.
        // Draft will be saved only after user actually changes a value.
        if (!force && !userEditedWorksheet) {
            return;
        }

        const changed = getChangedFields();

        if (hasRealChanges(changed)) {
            localStorage.setItem(draftKey, JSON.stringify(buildDraftPayload(changed)));
        } else {
            clearDraft();
        }
    }

    function scheduleDraftSave() {
        clearTimeout(draftSaveTimer);
        draftSaveTimer = setTimeout(() => saveDraftToLocal(true), 250);
    }

    function clearDraft() {
        clearTimeout(draftSaveTimer);
        localStorage.removeItem(draftKey);
    }

    function clearRestoredHighlights() {
        document.querySelectorAll('.draft-restored-cell').forEach(cell => {
            cell.classList.remove('draft-restored-cell');
        });
    }

    function highlightRestoredInput(input) {
        const td = input.closest('td');
        if (td) {
            td.classList.add('draft-restored-cell');
        }
        input.classList.add('draft-restored-input');
    }

    function showDraftToast(message) {
        if (!draftRestoreToast) return;

        draftRestoreToast.textContent = message;
        draftRestoreToast.classList.add('show');

        setTimeout(() => {
            draftRestoreToast.classList.remove('show');
        }, 3000);
    }

    function applyDraft(draftFields) {
        clearRestoredHighlights();
        let appliedCount = 0;

        editableInputs.forEach(input => {
            if (Object.prototype.hasOwnProperty.call(draftFields, input.name)) {
                input.value = draftFields[input.name] ?? '';
                highlightRestoredInput(input);
                appliedCount++;
            }
        });

        if (appliedCount > 0) {
            userEditedWorksheet = true;
            updateLiveFormulas();
        }

        return appliedCount;
    }

    function markWorksheetChanged() {
        userEditedWorksheet = hasRealChanges(getChangedFields());

        if (userEditedWorksheet) {
            scheduleDraftSave();
        } else {
            clearDraft();
        }
    }

    function openDraftModal() {
        if (restoreModalShown || !draftModal) return;

        restoreModalShown = true;
        draftModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeDraftModal() {
        if (!draftModal) return;

        draftModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    function restoreDraftIfNeeded() {
        const rawDraft = localStorage.getItem(draftKey);
        if (!rawDraft) return;

        let savedDraft = null;

        try {
            savedDraft = JSON.parse(rawDraft);
        } catch (error) {
            clearDraft();
            return;
        }

        const draftFields = getDraftFields(savedDraft);

        if (!hasRealChanges(draftFields)) {
            clearDraft();
            return;
        }

        const visibleDraft = getVisibleDraftChanges(draftFields);

        // If no visible input needs restore, remove stale draft so modal does not keep showing.
        if (!hasRealChanges(visibleDraft)) {
            clearDraft();
            return;
        }

        openDraftModal();

        if (discardDraftBtn) {
            discardDraftBtn.onclick = function () {
                clearDraft();
                closeDraftModal();
            };
        }

        if (restoreOnlyBtn) {
            restoreOnlyBtn.onclick = function () {
                const appliedCount = applyDraft(visibleDraft);
                saveDraftToLocal(true);
                closeDraftModal();

                if (appliedCount > 0) {
                    showDraftToast(appliedCount + ' restored cell(s) highlighted. Click Save Changes when ready.');
                }
            };
        }

        if (restoreAndSaveBtn) {
            restoreAndSaveBtn.onclick = function () {
                const appliedCount = applyDraft(visibleDraft);
                clearDraft();
                isSubmitting = true;
                closeDraftModal();

                if (form && appliedCount > 0) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            };
        }
    }

    function handleArrowNavigation(input, event) {
        const rowIndex = parseInt(input.dataset.rowIndex, 10);
        const colIndex = parseInt(input.dataset.colIndex, 10);

        const key = event.key;
        const valueLength = input.value.length;
        const selectionStart = input.selectionStart ?? 0;
        const selectionEnd = input.selectionEnd ?? 0;

        if (key === 'ArrowUp') {
            event.preventDefault();
            focusEditableCell(rowIndex - 1, colIndex);
            return;
        }

        if (key === 'ArrowDown' || key === 'Enter') {
            event.preventDefault();
            focusEditableCell(rowIndex + 1, colIndex);
            return;
        }

        if (key === 'ArrowLeft') {
            if (selectionStart !== 0 || selectionEnd !== 0) {
                return;
            }

            event.preventDefault();
            focusEditableCell(rowIndex, colIndex - 1);
            return;
        }

        if (key === 'ArrowRight') {
            if (selectionStart !== valueLength || selectionEnd !== valueLength) {
                return;
            }

            event.preventDefault();
            focusEditableCell(rowIndex, colIndex + 1);
        }
    }

    function handleMultiPaste(input, event) {
        const clipboard = (event.clipboardData || window.clipboardData).getData('text');

        if (!clipboard || (!clipboard.includes('\n') && !clipboard.includes('\t'))) {
            return;
        }

        event.preventDefault();

        const startRow = parseInt(input.dataset.rowIndex, 10);
        const startCol = parseInt(input.dataset.colIndex, 10);

        const pastedRows = clipboard
            .replace(/\r/g, '')
            .split('\n')
            .filter(row => row !== '');

        pastedRows.forEach((rowText, rowOffset) => {
            const cols = rowText.split('\t');

            cols.forEach((cellValue, colOffset) => {
                const targetRow = startRow + rowOffset;
                const targetCol = startCol + colOffset;

                if (editableGrid[targetRow] && editableGrid[targetRow][targetCol]) {
                    editableGrid[targetRow][targetCol].value = cellValue;
                }
            });
        });

        updateLiveFormulas();
        markWorksheetChanged();
    }


    const headerAliases = {
        style_name: ['style', 'buyer name'],
        contract_number: ['initial contract number', 'contract number', 'gmnts po number', 'gmts po number', 'po number'],
        contract_shipment_date: ['contract shipment date', 'initial contract shipment date', 'po shipment date'],
        customer_contract_quantity: ['customer contract quantity', 'customer po quantity', 'order qty', 'gmts order qty', 'gmts order quantity'],
        initial_consumption: ['booking consumption from cad', 'initial consumption', 'booking yy', 'consumption', 'costing yy in sms', 'costing yy'],
        wastage_for_ordering_percent: ['% wastage for ordering', 'waste %', 'wastage %', 'waste'],
        materials_ordered: ['materials ordered', 'material ordered'],
        material_pi_number: ['material pi number', 'pi number', 'vendor pi number'],
        pi_rate: ['pi rate', 'invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate'],
        pmt_doc_no: ['pmt doc no', 'payment doc no', 'payment reference number', 'payment ref no'],
        bl_awb_no: ['bl / awb no', 'bl awb no', 'bl no', 'awb no'],
        committed_ex_mill: ['committed ex mill', 'committed x-fty date', 'committed x fty date', 'committed ex-fty date', 'committed ex fty date'],
        committed_etd: ['committed etd', 'commited etd'],
        committed_eta: ['committed eta'],
        ata: ['ata'],
        actual_inhouse: ['actual inhouse', 'actual in-house'],
        invoiced_qty_scm: ['invoiced qty(scm)', 'invoiced qty scm', 'invoiced qty'],
        invoiced_rate_scm: ['invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate', 'pi rate'],
        invoiced_qty_store: ['invoiced qty(store)', 'invoiced qty store', 'in-house / receipt qty', 'receipt qty'],
        invoiced_rate_store: ['invoiced rate(store)', 'invoiced rate store', 'invoiced rate(scm)', 'invoiced rate scm', 'invoiced rate', 'pi rate'],
        receipt_qty: ['receipt qty', 'in-house / receipt qty', 'in house receipt qty', 'receiving', 'inhouse qty'],
        production_consumption: ['production consumption', 'prod yy', 'production yy'],
        production_wastage_percent: ['production wastage %', 'prod. wastage %', 'prod wastage %'],
        issued_qty: ['issued qty', 'issued'],
        shipment_month: ['shipment month'],
        pcd_required: ['pcd required'],
        order_to_be_placed_by: ['order to be placed by', 'order to be placed'],
        consumption_incl_yy: ['consumption based on which materials order including yy', 'yy waste', 'yy + waste %'],
        materials_to_be_ordered: ['materials to be ordered', 'material to be ordered'],
        short_excess_ordered: ['(short)/excess ordered', '(short) / excess ordered', 'short excess ordered'],
        material_order_status: ['material order status'],
        pi_status: ['pi status'],
        pi_amount: ['pi amount'],
        payment_reqd_date: ["payment req'd date", 'payment reqd date', 'payment required date'],
        payment_status: ['payment status'],
        bl_status: ['bl status'],
        arrival_status: ['arrival status'],
        committed_inhouse: ['committed inhouse', 'committed in house'],
        final_status: ['final status'],
        pcd_as_per_committed_inhouse: ['pcd as per committed inhouse', 'rm inh as per committed inhouse'],
        invoiced_amount_scm: ['invoiced amount(scm)', 'invoiced amount scm', 'invoiced amount'],
        invoiced_amount_store: ['invoiced amount(store)', 'invoiced amount store'],
        gmnts_po_number: ['gmnts po number', 'gmts po number', 'gmt po number'],
        gmts_order_qty: ['gmts order qty', 'gmts order quantity', 'gmt order qty'],
        production_cons_incl_wastage: ['production consumption including wastage', 'production cons including wastage', 'prod. yy + wastage', 'prod yy waste'],
        requirement: ['requirement'],
        excess_shortage: ['excess / (shortage)', 'excess shortage', '(short) / excess in-house qty'],
        liability: ['liability', 'liability qty'],
        buyer_liability: ['buyer liability'],
        buyer_liability_value: ['buyer liability value'],
        liability_based_on_receiving: ['liability based on receiving'],
        short_excess_issued: ['(short)/ excess issued', '(short) / excess issued', 'short excess issued'],
        return_back_to_stores: ['return back to stores'],
        dead_stock_quantity: ['dead stock quantity'],
        material_cost_value: ['material cost value', 'material issue value'],
        dead_stock_value: ['dead stock value'],
        liability_stock_value: ['liability stock value'],
        short_excess_value: ['short & excess value', 'short and excess value']
    };

    function normalizeHeaderKey(value) {
        return String(value ?? '')
            .toLowerCase()
            .trim()
            .replace(/[&+]/g, ' and ')
            .replace(/[’']/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function buildHeaderLookup() {
        const lookup = {};

        sheetHeaders.forEach((header, index) => {
            [header.header_key, header.header_name, normalizeHeaderKey(header.header_key), normalizeHeaderKey(header.header_name)].forEach(key => {
                const normalized = normalizeHeaderKey(key);
                if (normalized && lookup[normalized] === undefined) {
                    lookup[normalized] = index;
                }
            });
        });

        Object.entries(headerAliases).forEach(([canonical, aliases]) => {
            const normalizedCanonical = normalizeHeaderKey(canonical);
            if (lookup[normalizedCanonical] !== undefined) return;

            aliases.forEach(alias => {
                const aliasKey = normalizeHeaderKey(alias);
                if (lookup[normalizedCanonical] === undefined && lookup[aliasKey] !== undefined) {
                    lookup[normalizedCanonical] = lookup[aliasKey];
                }
            });
        });

        return lookup;
    }

    const headerIndexByKey = buildHeaderLookup();

    function rowElements() {
        if (!table) return [];
        return Array.from(table.querySelectorAll('tbody tr')).filter(row => row.querySelectorAll('td').length > 1);
    }

    function readCellValue(row, colIndex) {
        const cell = row.querySelectorAll('td')[colIndex + 1];
        if (!cell) return '';

        const input = cell.querySelector('input.excel-editable-cell');
        if (input) return input.value ?? '';

        const display = cell.querySelector('[data-cell-display]');
        if (display) {
            const value = display.dataset.cellValue ?? display.textContent ?? '';
            return String(value).trim() === '-' ? '' : value;
        }

        const text = cell.textContent ?? '';
        return String(text).trim() === '-' ? '' : text;
    }

    function valAny(rowData, keys) {
        for (const key of keys) {
            const normalized = normalizeHeaderKey(key);
            const index = headerIndexByKey[normalized];
            if (index === undefined) continue;

            const value = rowData[index];
            if (!blank(value)) return value;
        }

        return null;
    }

    function blank(value) {
        return value === null || value === undefined || String(value).trim() === '' || String(value).trim() === '-';
    }

    function num(value) {
        if (blank(value)) return 0;
        const cleaned = String(value).replace(/[,\s]/g, '');
        const parsed = Number(cleaned);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function percent(value) {
        const n = num(value);
        return n > 1 ? n / 100 : n;
    }

    function parseDateValue(value) {
        if (blank(value)) return null;
        const raw = String(value).trim();

        if (/^\d+(\.\d+)?$/.test(raw)) {
            const base = new Date(Date.UTC(1899, 11, 30));
            base.setUTCDate(base.getUTCDate() + parseInt(raw, 10));
            return base;
        }

        let match = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (match) {
            return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        }

        match = raw.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (match) {
            return new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
        }

        const parsed = new Date(raw);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function addDays(date, days) {
        if (!date) return null;
        const next = new Date(date.getTime());
        next.setDate(next.getDate() + days);
        return next;
    }

    function fmtDate(date) {
        if (!date || Number.isNaN(date.getTime())) return '';
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function fmtMonth(date) {
        if (!date || Number.isNaN(date.getTime())) return '';
        return date.toLocaleString('en-US', { month: 'short' });
    }

    function fmtNum(value) {
        if (value === null || value === undefined || value === '') return '';
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        if (Math.abs(n - Math.round(n)) < 0.000001) return String(Math.round(n));
        return n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
    }

    function setFormulaValue(row, keys, value) {
        let targetIndex;

        for (const key of keys) {
            const normalized = normalizeHeaderKey(key);
            if (headerIndexByKey[normalized] !== undefined) {
                targetIndex = headerIndexByKey[normalized];
                break;
            }
        }

        if (targetIndex === undefined) return;

        const cell = row.querySelectorAll('td')[targetIndex + 1];
        if (!cell) return;

        const display = cell.querySelector('[data-formula-output="1"]');
        if (!display) return;

        const normalizedValue = value === null || value === undefined || value === '' ? '' : String(value);
        display.dataset.cellValue = normalizedValue;
        display.textContent = normalizedValue !== '' ? normalizedValue : '-';
        display.classList.add('formula-live-updated');
    }

    function calculateLiveFormulas() {
        const rows = rowElements();
        let previousFormulaKey = null;
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length <= 1) return;

            const rowData = sheetHeaders.map((header, index) => readCellValue(row, index));

            const styleOrBuyer = valAny(rowData, ['style_name', 'style', 'buyer_name']);
            const gmtsColorName = valAny(rowData, ['gmts_color_name', 'gmts_colour_name', 'gmts_color']);
            const contractNumber = valAny(rowData, ['initial_contract_number', 'contract_number', 'customer_contract', 'gmnts_po_number', 'gmts_po_number', 'po_number']);
            const formulaKey = [gmtsColorName, contractNumber, styleOrBuyer]
                .map(value => String(value ?? '').trim())
                .filter(value => value !== '')
                .join('|');

            const contractShipmentDate = parseDateValue(valAny(rowData, ['contract_shipment_date', 'initial_contract_shipment_date', 'po_shipment_date']));
            const customerContractQtySource = num(valAny(rowData, ['bom_quantity', 'customer_contract_quantity', 'customer_po_quantity', 'order_qty', 'gmts_order_qty', 'gmts_order_quantity']));
            const initialConsumption = num(valAny(rowData, ['booking_consumption_from_cad', 'initial_consumption', 'booking_yy', 'consumption', 'costing_yy_in_sms', 'costing_yy']));
            const orderingWastage = percent(valAny(rowData, ['wastage_for_ordering_percent', 'waste_percent', 'wastage_percent', 'waste']));
            const materialsOrderedRaw = valAny(rowData, ['materials_ordered', 'material_ordered']);
            const materialsOrdered = num(materialsOrderedRaw);
            const materialPiNumber = valAny(rowData, ['material_pi_number', 'pi_number', 'vendor_pi_number']);
            const piRate = num(valAny(rowData, ['pi_rate', 'invoiced_rate_scm', 'invoiced_rate']));
            const pmtDocNo = valAny(rowData, ['pmt_doc_no', 'payment_doc_no', 'payment_reference_number', 'payment_ref_no']);
            const blAwbNo = valAny(rowData, ['bl_awb_no', 'bl_no', 'awb_no']);
            const committedExMill = parseDateValue(valAny(rowData, ['committed_ex_mill', 'committed_x_fty_date', 'committed_ex_fty_date', 'committed_ex_fty', 'committed_x_fty']));
            const committedEtd = parseDateValue(valAny(rowData, ['committed_etd', 'commited_etd']));
            const committedEta = parseDateValue(valAny(rowData, ['committed_eta']));
            const ata = parseDateValue(valAny(rowData, ['ata']));
            const actualInhouse = parseDateValue(valAny(rowData, ['actual_inhouse', 'actual_in_house']));
            const invoicedQtyScm = num(valAny(rowData, ['invoiced_qty_scm', 'invoiced_qty']));
            const invoicedRateScm = num(valAny(rowData, ['invoiced_rate_scm', 'invoiced_rate', 'pi_rate']));
            const invoicedQtyStore = num(valAny(rowData, ['invoiced_qty_store', 'in_house_receipt_qty', 'receipt_qty']));
            const invoicedRateStore = num(valAny(rowData, ['invoiced_rate_store', 'invoiced_rate_scm', 'invoiced_rate', 'pi_rate']));
            const receiptQty = num(valAny(rowData, ['receipt_qty', 'in_house_receipt_qty', 'receiving', 'inhouse_qty']));
            const productionConsumption = num(valAny(rowData, ['production_consumption', 'prod_yy', 'production_yy']));
            const productionWastage = percent(valAny(rowData, ['production_wastage_percent', 'prod_wastage_percent', 'prod_wastage']));
            const issuedQty = num(valAny(rowData, ['issued_qty', 'issued']));

            const shipmentMonth = fmtMonth(contractShipmentDate);
            const customerContractQty = (formulaKey !== '' && formulaKey === previousFormulaKey) ? 0 : customerContractQtySource;
            const pcdRequired = contractShipmentDate ? addDays(contractShipmentDate, -45) : null;
            const orderToBePlacedBy = pcdRequired ? addDays(pcdRequired, -70) : null;

            const consumptionInclYy = initialConsumption * (1 + orderingWastage);
            const materialsToBeOrdered = Math.round(consumptionInclYy * customerContractQtySource);
            const shortExcessOrdered = Math.round(materialsOrdered - materialsToBeOrdered);

            let materialOrderStatus = 'PO Pending';
            if (!blank(materialsOrderedRaw)) {
                if (shortExcessOrdered <= (materialsToBeOrdered * -1)) {
                    materialOrderStatus = 'PO Pending';
                } else if (shortExcessOrdered < 0) {
                    materialOrderStatus = 'Short PO Qty';
                } else if (shortExcessOrdered === 0) {
                    materialOrderStatus = 'PO Raised';
                } else {
                    materialOrderStatus = 'Excess Qty PO';
                }
            }

            const piStatus = materialOrderStatus === 'PO Pending'
                ? 'Waiting for PO'
                : (blank(materialPiNumber) ? 'PI Pending' : 'PI Received');
            const piAmount = piRate * materialsOrdered;
            const paymentReqdDate = committedExMill ? addDays(committedExMill, -7) : null;
            const paymentStatus = piStatus !== 'PI Received'
                ? piStatus
                : (blank(pmtDocNo) ? 'Pmt Pending' : 'Pmt Done');
            const blStatus = paymentStatus !== 'Pmt Done'
                ? paymentStatus
                : (blank(blAwbNo) ? 'BL Pending' : 'BL raised');

            let arrivalStatus = blStatus;
            if (ata) {
                arrivalStatus = ata.getTime() > Date.now() ? 'Sailed but not arrived' : 'Arrived';
            } else if (committedEta) {
                if (committedEtd && committedEtd.getTime() > today.getTime()) {
                    arrivalStatus = 'Not Sailed';
                } else if (committedEta.getTime() < today.getTime()) {
                    arrivalStatus = 'Late';
                } else {
                    arrivalStatus = 'Sailed but not arrived';
                }
            }

            const committedInhouse = committedEta ? addDays(committedEta, 7) : null;
            const finalStatus = actualInhouse ? 'Inhouse' : arrivalStatus;
            const pcdAsPerCommittedInhouse = committedInhouse ? addDays(committedInhouse, 2) : null;
            const isStorePayment = String(pmtDocNo ?? '').trim().toUpperCase() === 'STORES';
            const invoicedAmountScm = isStorePayment ? 0 : (invoicedQtyScm * invoicedRateScm);
            const invoicedAmountStore = invoicedQtyStore * invoicedRateStore;
            const gmntsPoNumber = contractNumber;
            const gmtsOrderQty = customerContractQtySource;
            const productionConsInclWastage = productionConsumption * (1 + productionWastage);
            const requirement = productionConsInclWastage * gmtsOrderQty;
            const excessShortage = receiptQty - requirement;
            const liabilityQty = excessShortage > 0 ? excessShortage : 0;
            const buyerLiabilityQty = shortExcessOrdered > 0 ? shortExcessOrdered : 0;
            const buyerLiabilityValue = buyerLiabilityQty * piRate;
            const liabilityBasedOnReceiving = receiptQty - materialsToBeOrdered;
            const shortExcessIssued = requirement - issuedQty;
            const returnBackToStores = shortExcessIssued < 0 ? 0 : shortExcessIssued;
            const deadStockQuantity = (receiptQty - issuedQty) - returnBackToStores;
            const materialCostValue = issuedQty * invoicedRateStore;
            const deadStockValue = deadStockQuantity * invoicedRateStore;
            const liabilityStockValue = liabilityQty * invoicedRateStore;
            const shortExcessValue = excessShortage * invoicedRateStore;

            setFormulaValue(row, ['shipment_month'], shipmentMonth);
            setFormulaValue(row, ['customer_contract_quantity', 'customer_po_quantity'], fmtNum(customerContractQty));
            setFormulaValue(row, ['pcd_required'], fmtDate(pcdRequired));
            setFormulaValue(row, ['order_to_be_placed_by', 'order_to_be_placed'], fmtDate(orderToBePlacedBy));
            setFormulaValue(row, ['consumption_incl_yy', 'consumption_based_on_which_materials_order_including_yy', 'yy_waste'], fmtNum(consumptionInclYy));
            setFormulaValue(row, ['materials_to_be_ordered', 'material_to_be_ordered'], fmtNum(materialsToBeOrdered));
            setFormulaValue(row, ['short_excess_ordered', 'short_excess_ordered_qty'], fmtNum(shortExcessOrdered));
            setFormulaValue(row, ['material_order_status'], materialOrderStatus);
            setFormulaValue(row, ['pi_status'], piStatus);
            setFormulaValue(row, ['pi_amount'], fmtNum(piAmount));
            setFormulaValue(row, ['payment_reqd_date', 'payment_req_d_date', 'payment_required_date'], fmtDate(paymentReqdDate));
            setFormulaValue(row, ['payment_status'], paymentStatus);
            setFormulaValue(row, ['bl_status'], blStatus);
            setFormulaValue(row, ['arrival_status'], arrivalStatus);
            setFormulaValue(row, ['committed_inhouse', 'committed_in_house'], fmtDate(committedInhouse));
            setFormulaValue(row, ['final_status'], finalStatus);
            setFormulaValue(row, ['pcd_as_per_committed_inhouse', 'rm_inh_as_per_committed_inhouse'], fmtDate(pcdAsPerCommittedInhouse));
            setFormulaValue(row, ['invoiced_amount_scm', 'invoiced_amount'], fmtNum(invoicedAmountScm));
            setFormulaValue(row, ['invoiced_amount_store'], fmtNum(invoicedAmountStore));
            setFormulaValue(row, ['gmnts_po_number', 'gmts_po_number'], gmntsPoNumber);
            setFormulaValue(row, ['gmts_order_qty', 'gmts_order_quantity', 'gmts_order_qty_store'], fmtNum(gmtsOrderQty));
            setFormulaValue(row, ['production_cons_incl_wastage', 'production_consumption_including_wastage', 'prod_yy_wastage'], fmtNum(productionConsInclWastage));
            setFormulaValue(row, ['requirement'], fmtNum(requirement));
            setFormulaValue(row, ['excess_shortage', 'excess_shortage_qty'], fmtNum(excessShortage));
            setFormulaValue(row, ['liability', 'liability_qty'], fmtNum(liabilityQty));
            setFormulaValue(row, ['buyer_liability'], fmtNum(buyerLiabilityQty));
            setFormulaValue(row, ['buyer_liability_value'], fmtNum(buyerLiabilityValue));
            setFormulaValue(row, ['liability_based_on_receiving'], fmtNum(liabilityBasedOnReceiving));
            setFormulaValue(row, ['short_excess_issued'], fmtNum(shortExcessIssued));
            setFormulaValue(row, ['return_back_to_stores'], fmtNum(returnBackToStores));
            setFormulaValue(row, ['dead_stock_quantity'], fmtNum(deadStockQuantity));
            setFormulaValue(row, ['material_cost_value', 'material_issue_value'], fmtNum(materialCostValue));
            setFormulaValue(row, ['dead_stock_value'], fmtNum(deadStockValue));
            setFormulaValue(row, ['liability_stock_value'], fmtNum(liabilityStockValue));
            setFormulaValue(row, ['short_excess_value', 'short_and_excess_value'], fmtNum(shortExcessValue));

            previousFormulaKey = formulaKey;
        });
    }

    function updateLiveFormulas() {
        calculateLiveFormulas();
    }

    buildEditableGrid();
    updateLiveFormulas();

    const globalDocSearch = document.getElementById('globalDocSearch');
    const applySheetSearchButton = document.getElementById('applySheetSearchButton');
    const excelTableWrap = document.querySelector('.excel-table-wrap');
    const searchColumnStorageKey = `excel_search_column_user_{{ auth()->id() }}_file_{{ $excelFile->id }}`;
    let sheetSearchTimer = null;

    function rememberActiveSearchColumn() {
        const active = document.activeElement;
        if (active && active.classList && active.classList.contains('filter-input')) {
            localStorage.setItem(searchColumnStorageKey, active.dataset.column || '0');
        } else if (globalDocSearch && active === globalDocSearch) {
            localStorage.setItem(searchColumnStorageKey, 'global');
        }
    }

    function restoreSearchColumnPosition() {
        const savedColumn = localStorage.getItem(searchColumnStorageKey);
        if (!savedColumn) return false;

        setTimeout(function () {
            const target = savedColumn === 'global'
                ? globalDocSearch
                : document.querySelector(`.filter-input[data-column="${savedColumn}"]`);

            if (!target) return;

            target.scrollIntoView({ block: 'nearest', inline: 'center' });
            target.focus({ preventScroll: true });

            if (target.select) {
                const valueLength = String(target.value || '').length;
                target.setSelectionRange(valueLength, valueLength);
            }
        }, 250);

        return true;
    }

    const restoredSearchColumn = restoreSearchColumnPosition();

    if (shouldAutoScrollToUserColumns && !restoredSearchColumn) {
        setTimeout(function () {
            scrollToCurrentUserColumns(false);
        }, 450);
    }

    function submitServerSheetSearch() {
        rememberActiveSearchColumn();
        const url = new URL(window.location.href);

        url.searchParams.delete('page');

        if (globalDocSearch && globalDocSearch.value.trim() !== '') {
            url.searchParams.set('search', globalDocSearch.value.trim());
        } else {
            url.searchParams.delete('search');
        }

        filterInputs.forEach(input => {
            const filterKey = input.dataset.filterKey;
            if (!filterKey) return;

            const paramName = 'filters[' + filterKey + ']';
            const value = input.value.trim();

            if (value !== '') {
                url.searchParams.set(paramName, value);
            } else {
                url.searchParams.delete(paramName);
            }
        });

        window.location.href = url.toString();
    }

    function scheduleServerSheetSearch() {
        clearTimeout(sheetSearchTimer);
        sheetSearchTimer = setTimeout(submitServerSheetSearch, 700);
    }

    [globalDocSearch, ...filterInputs].filter(Boolean).forEach(input => {
        input.addEventListener('input', scheduleServerSheetSearch);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                clearTimeout(sheetSearchTimer);
                submitServerSheetSearch();
            }
        });
    });

    if (applySheetSearchButton) {
        applySheetSearchButton.addEventListener('click', function () {
            clearTimeout(sheetSearchTimer);
            submitServerSheetSearch();
        });
    }

    if (headerJumpSelect) {
        headerJumpSelect.addEventListener('change', function () {
            const header = headerById(this.value);

            if (header) {
                if (headerQuickFind) {
                    headerQuickFind.value = header.header_name || '';
                }

                scrollToHeader(header, true);
            }
        });
    }

    if (headerFindButton) {
        headerFindButton.addEventListener('click', scrollToHeaderFromFinder);
    }

    if (headerQuickFind) {
        headerQuickFind.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                scrollToHeaderFromFinder();
            }
        });

        headerQuickFind.addEventListener('change', scrollToHeaderFromFinder);
    }

    if (jumpToMyColumnsButton) {
        jumpToMyColumnsButton.addEventListener('click', function () {
            scrollToCurrentUserColumns(true);
        });
    }

    editableInputs.forEach(input => {
        input.addEventListener('input', function () {
            clearRestoredHighlights();
            updateLiveFormulas();
            markWorksheetChanged();
        });
        input.addEventListener('change', function () {
            clearRestoredHighlights();
            updateLiveFormulas();
            markWorksheetChanged();
        });

        input.addEventListener('paste', function (event) {
            clearRestoredHighlights();
            handleMultiPaste(this, event);
        });

        input.addEventListener('keydown', function (event) {
            const supportedKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter'];

            if (!supportedKeys.includes(event.key)) {
                return;
            }

            handleArrowNavigation(this, event);
        });
    });

    if (form) {
        form.addEventListener('submit', function () {
            isSubmitting = true;
            clearDraft();
        });
    }

    if (addRowButton && addRowForm) {
        addRowButton.addEventListener('click', function () {
            const changed = getChangedFields();

            if (hasRealChanges(changed)) {
                const shouldSave = confirm('You have unsaved changes. Press OK to save first, or Cancel to continue without saving.');

                if (shouldSave) {
                    isSubmitting = true;
                    if (form) {
                        form.submit();
                    }
                    return;
                }
            }

            addRowForm.submit();
        });
    }

    if (deleteFileForm) {
        deleteFileForm.addEventListener('submit', function () {
            clearDraft();
        });
    }

    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-8px)';

            setTimeout(() => {
                successAlert.remove();
            }, 500);
        }, 2500);
    }

    window.addEventListener('beforeunload', function () {
        if (!isSubmitting && userEditedWorksheet) {
            saveDraftToLocal(true);
        }
    });

    restoreDraftIfNeeded();
});
</script>
@endsection